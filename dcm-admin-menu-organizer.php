<?php
/**
 * Plugin Name: DCM Admin Menu Organizer
 * Plugin URI: 
 * Description: 管理画面の親メニューの表示順を制御し、セパレーターを追加できます。
 * Version: 1.0.0
 * Author: pm-hiroshi
 * Author URI: 
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dcm-admin-menu-organizer
 *
 * @package DCM_Admin_Menu_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面メニューの表示順を制御するメインクラス
 *
 * @package DCM_Admin_Menu_Organizer
 * @since   1.0.0
 */
class DCM_Admin_Menu_Organizer {

	/**
	 * 設定画面: メニュー順序フィールドID（JS/HTML用）
	 *
	 * @var string
	 */
	private string $menu_order_field_id = 'dcm_admin_menu_order';

	/**
	 * 設定画面: アコーディオンフィールドID（HTML用）
	 *
	 * @var string
	 */
	private string $accordion_field_id = 'dcm_admin_menu_accordion_enabled';

	/**
	 * 設定画面: 未指定メニュー非表示フィールドID（HTML用）
	 *
	 * @var string
	 */
	private string $hide_unspecified_field_id = 'dcm_admin_menu_hide_unspecified';

	/**
	 * 統合設定（DB）のオプション名
	 *
	 * settings.json と同じキー構造で保存する:
	 * - menu_order (string)
	 * - accordion_enabled (bool)
	 * - hide_unspecified (bool)
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private string $settings_option_name = 'dcm_admin_menu_organizer_settings';

	/**
	 * 設定ページスラッグ
	 *
	 * @var string
	 */
	private string $settings_page_slug = 'dcm-menu-organizer';

	/**
	 * フィルタリング済みグループのキャッシュ
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $cached_groups = null;

	/**
	 * 設定ファイルの内容のキャッシュ
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cached_config = null;

	/**
	 * 設定ファイルのパス
	 *
	 * @var string
	 */
	private string $config_file;

	/**
	 * デフォルトの設定ファイルパス
	 *
	 * @var string
	 */
	private string $default_config_file;

	/**
	 * プラグイン名（通知等で使用）
	 *
	 * @var string
	 */
	private string $plugin_name;

	/**
	 * コンストラクタ
	 *
	 * WordPress のアクションフックに登録
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// デフォルトの設定ファイルパスを保存
		$this->default_config_file = WP_CONTENT_DIR . '/dcm-admin-menu-organizer/settings.json';
		$plugin_data               = get_file_data(
			__FILE__,
			[
				'Name' => 'Plugin Name',
			],
			'plugin'
		);
		$this->plugin_name         = ! empty( $plugin_data['Name'] )
			? $plugin_data['Name']
			: __CLASS__;
		
		/**
		 * 設定ファイルのパスをフィルターで変更可能にする
		 *
		 * @since 1.0.0
		 *
		 * @param string $config_file 設定ファイルのフルパス
		 */
		$this->config_file = apply_filters( 'dcm_admin_menu_organizer_config_file', $this->default_config_file );

		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'reorder_admin_menu' ], 999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_separator_styles' ] );
		add_action( 'admin_head', [ $this, 'output_accordion_styles' ] );
		add_action( 'admin_footer', [ $this, 'output_accordion_scripts' ] );

		// プラグイン一覧から設定を初期化できるリンクを追加
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_reset_action_link' ] );
		add_action( 'admin_init', [ $this, 'handle_reset_action' ] );
		add_action( 'admin_notices', [ $this, 'render_reset_notice' ] );
	}

	/**
	 * 設定ページを WordPress の「設定」メニューに追加
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			'親メニュー表示順設定',
			'親メニュー表示順',
			'manage_options',
			$this->settings_page_slug,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * 設定を WordPress の Settings API に登録
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'dcm_menu_organizer_group',
			$this->settings_option_name,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->get_default_db_settings(),
			]
		);
	}

	/**
	 * 統合設定（DB）をサニタイズ
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input 入力値
	 *
	 * @return array<string, mixed> サニタイズされた配列
	 */
	public function sanitize_settings( $input ): array {
		// 権限チェック（明示的なセキュリティ対策）
		if ( ! current_user_can( 'manage_options' ) ) {
			$current = get_option( $this->settings_option_name, [] );
			return is_array( $current ) ? $current : $this->get_default_db_settings();
		}

		$defaults = $this->get_default_db_settings();
		$input    = is_array( $input ) ? $input : [];

		$menu_order = isset( $input['menu_order'] ) ? (string) $input['menu_order'] : '';

		return [
			'menu_order'        => sanitize_textarea_field( $menu_order ),
			'accordion_enabled' => ! empty( $input['accordion_enabled'] ),
			'hide_unspecified'  => ! empty( $input['hide_unspecified'] ),
		] + $defaults;
	}

	/**
	 * 統合設定（DB）のデフォルト値
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_db_settings(): array {
		return [
			'menu_order'        => '',
			'accordion_enabled' => false,
			'hide_unspecified'  => false,
		];
	}

	/**
	 * 統合設定（DB）を取得（後方互換: 旧3オプションからの移行/読み取り）
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private function get_db_settings(): array {
		$defaults = $this->get_default_db_settings();
		$current  = get_option( $this->settings_option_name, null );

		if ( is_array( $current ) ) {
			return $current + $defaults;
		}

		return $defaults;
	}

	/**
	 * 設定ファイルのパスが有効かどうかを検証
	 *
	 * パスの妥当性（セキュリティ）、ファイルの存在、読み取り可能性の両方をチェックします。
	 *
	 * @since 1.0.0
	 *
	 * @param string $path 検証するファイルパス
	 *
	 * @return bool 有効で存在し読み取り可能な場合は true
	 */
	private function is_valid_config_file_path( string $path ): bool {
		if ( empty( $path ) ) {
			return false;
		}

		// 基本的なパストラバーサル対策（相対パス、nullバイトなど）
		if ( strpos( $path, '..' ) !== false || strpos( $path, "\0" ) !== false ) {
			return false;
		}

		// ファイルの存在チェック
		if ( ! file_exists( $path ) ) {
			return false;
		}

		// ファイルの読み取り可能性チェック
		if ( ! is_readable( $path ) ) {
			return false;
		}

		// シンボリックリンクを解決して実際のパスを取得
		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return false;
		}

		// デフォルトパスの場合は WP_CONTENT_DIR 内をチェック（シンボリックリンク解決後）
		// パラメータ $path とデフォルトパスを直接比較（$this->config_file はフィルターで変更されている可能性があるため）
		if ( $path === $this->default_config_file ) {
			$content_dir = realpath( WP_CONTENT_DIR );
			if ( false !== $content_dir ) {
				// シンボリックリンク解決後のパスで比較
				return strpos( $real_path, $content_dir ) === 0;
			}
		}

		// フィルターで変更された場合は、基本的な検証のみ（開発者が意図的に変更したパスとみなす）
		// シンボリックリンクは既にrealpath()で解決されているため、安全
		return true;
	}

	/**
	 * JSON設定ファイルから設定を読み込む（キャッシュ機能付き）
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|null 設定配列、または null（ファイルなし・エラー時）
	 */
	private function load_config_from_file(): ?array {
		// キャッシュがあればそれを返す
		if ( null !== $this->cached_config ) {
			return $this->cached_config;
		}

		// ファイルパスの検証（セキュリティ対策と存在チェック）
		if ( ! $this->is_valid_config_file_path( $this->config_file ) ) {
			$this->cached_config = null;
			return null;
		}

		// ファイルを読み込み
		$json_content = file_get_contents( $this->config_file );
		if ( false === $json_content ) {
			error_log( 'DCM Admin Menu Organizer: Failed to read config file: ' . $this->config_file );
			$this->cached_config = null;
			return null;
		}

		// JSONをパース
		$config = json_decode( $json_content, true );
		if ( null === $config ) {
			error_log( 'DCM Admin Menu Organizer: Invalid JSON in config file: ' . $this->config_file );
			$this->cached_config = null;
			return null;
		}

		// 必須キーのバリデーション
		if ( ! isset( $config['menu_order'] ) ) {
			error_log( 'DCM Admin Menu Organizer: Missing "menu_order" key in config file' );
			$this->cached_config = null;
			return null;
		}

		// menu_order が配列でない場合
		if ( ! is_array( $config['menu_order'] ) ) {
			error_log( 'DCM Admin Menu Organizer: "menu_order" must be an array in config file' );
			$this->cached_config = null;
			return null;
		}

		// キャッシュに保存
		$this->cached_config = $config;

		return $config;
	}

	/**
	 * ファイル設定が有効かどうかをチェック
	 *
	 * @since 1.0.0
	 *
	 * @return bool ファイル設定が有効な場合は true
	 */
	private function is_file_config_active(): bool {
		return null !== $this->load_config_from_file();
	}

	/**
	 * メニュー順序の設定を取得（ファイル優先）
	 *
	 * @since 1.0.0
	 *
	 * @return string メニュー順序の文字列
	 */
	private function get_menu_order_setting(): string {
		// ファイル設定を優先
		$config = $this->load_config_from_file();
		if ( null !== $config && isset( $config['menu_order'] ) ) {
			// 配列を改行区切りの文字列に変換
			return implode( "\n", $config['menu_order'] );
		}

		// ファイル設定がない場合はDBから取得
		$settings = $this->get_db_settings();
		return isset( $settings['menu_order'] ) ? (string) $settings['menu_order'] : '';
	}

	/**
	 * アコーディオン設定を取得（ファイル優先）
	 *
	 * @since 1.0.0
	 *
	 * @return bool アコーディオンが有効な場合は true
	 */
	private function get_accordion_setting(): bool {
		// ファイル設定を優先
		$config = $this->load_config_from_file();
		if ( null !== $config && isset( $config['accordion_enabled'] ) ) {
			return (bool) $config['accordion_enabled'];
		}

		// ファイル設定がない場合はDBから取得
		$settings = $this->get_db_settings();
		return ! empty( $settings['accordion_enabled'] );
	}

	/**
	 * 未指定メニュー非表示設定を取得（ファイル優先）
	 *
	 * @since 1.0.0
	 *
	 * @return bool 未指定メニューを非表示にする場合は true
	 */
	private function get_hide_unspecified_setting(): bool {
		// ファイル設定を優先
		$config = $this->load_config_from_file();
		if ( null !== $config && isset( $config['hide_unspecified'] ) ) {
			return (bool) $config['hide_unspecified'];
		}

		// ファイル設定がない場合はDBから取得
		$settings = $this->get_db_settings();
		return ! empty( $settings['hide_unspecified'] );
	}

	/**
	 * 設定ページをレンダリング
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_file_config      = $this->is_file_config_active();
		$db_settings         = $this->get_db_settings();
		$current_value       = $is_file_config ? $this->get_menu_order_setting() : (string) $db_settings['menu_order'];
		$accordion_enabled   = $is_file_config ? $this->get_accordion_setting() : (bool) $db_settings['accordion_enabled'];
		$hide_unspecified    = $is_file_config ? $this->get_hide_unspecified_setting() : (bool) $db_settings['hide_unspecified'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<p>
				管理画面左側の<strong>親メニュー（トップレベルメニュー）</strong>の表示順を制御します。サブメニューには影響しません。
			</p>
			
			<?php if ( $is_file_config ) : ?>
				<div class="notice notice-info">
					<p>
						<strong>ファイル設定が有効です</strong><br>
						設定はファイルで管理されています: <code><?php echo esc_html( str_replace( ABSPATH, '', $this->config_file ) ); ?></code><br>
						管理画面での編集はできません。設定を変更する場合はファイルを編集してください。
					</p>
				</div>
			<?php endif; ?>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'dcm_menu_organizer_group' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $this->menu_order_field_id ); ?>">
								親メニュー表示順
							</label>
						</th>
						<td>
							<textarea 
								name="<?php echo esc_attr( $this->settings_option_name ); ?>[menu_order]" 
								id="<?php echo esc_attr( $this->menu_order_field_id ); ?>"
								rows="20" 
								cols="80"
								class="large-text code"
								<?php echo $is_file_config ? 'readonly' : ''; ?>
							><?php echo esc_textarea( $current_value ); ?></textarea>
							
							<?php if ( ! $is_file_config ) : ?>
								<p style="margin-top: 10px;">
									<button type="button" class="button" id="import-current-menu">
										デフォルト状態のメニュー順序を挿入
									</button>
								</p>
							<?php endif; ?>
							
							<details style="margin-top: 15px;">
								<summary style="cursor: pointer; font-weight: 600;">使い方</summary>
								<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
									<p style="margin: 0;">
										• メニューのスラッグを1行に1つずつ記載<br>
										• <code>separator</code> = 区切り線（横線のみ）<br>
										• <code>separator: テキスト</code> = ラベル付き区切り線<br>
										• <code>separator: テキスト|背景色|文字色</code> = 背景色・文字色を指定<br>
										• <code>separator: テキスト|背景色|文字色|左ボーダー色</code> = 左に3pxのボーダーを追加<br>
										• <code>separator: テキスト|背景色|文字色|左ボーダー色|アイコン色</code> = アコーディオンアイコンの色を指定（アコーディオン有効時のみ）<br>
										• 空行や <code>#</code> で始まる行はコメントとして無視されます<br>
										<br>
										<strong>注意:</strong> <code>profile.php</code>（プロフィールページ）は一般ユーザーの左メニューにのみ表示されるメニューです。設定に含めないと、一般ユーザーがログインした際に表示されなくなります。
									</p>
								</div>
							</details>
							
							<details style="margin-top: 15px;">
								<summary style="cursor: pointer; font-weight: 600;">設定例</summary>
								<pre style="background: #f9f9f9; padding: 10px; margin-top: 10px; border: 1px solid #ddd;">
# ダッシュボードと投稿
index.php
edit.php

separator: コンテンツ管理

# カスタム投稿タイプ
edit.php?post_type=product
edit.php?post_type=news

separator: 入稿関連|#f0f6fc|#0969da|#0969da

edit.php?post_type=article

# アコーディオン有効時はアイコン色も指定可能
separator: その他|#fff|#333|#333|#666

options-general.php
tools.php</pre>
						</details>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $this->accordion_field_id ); ?>">
							アコーディオン機能
						</label>
					</th>
					<td>
						<label>
							<input 
								type="checkbox" 
								name="<?php echo esc_attr( $this->settings_option_name ); ?>[accordion_enabled]" 
								id="<?php echo esc_attr( $this->accordion_field_id ); ?>"
								value="1"
								<?php checked( $accordion_enabled, true ); ?>
								<?php echo $is_file_config ? 'disabled' : ''; ?>
							>
							アコーディオンを有効にする
						</label>
						<p class="description">
							有効にすると、すべてのテキストセパレーター（<code>separator: テキスト</code>）をクリックで開閉できるようになります。<br>
							開閉状態はブラウザに記憶されます。
						</p>
						
						<details style="margin-top: 10px;">
							<summary style="cursor: pointer; font-weight: 600;">セキュリティー情報</summary>
							<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
								<p style="margin: 0;">
									• 開閉状態はブラウザの<code>localStorage</code>に保存されます<br>
									• <code>Cookie</code>は使用していません<br>
									• 保存されるのは開閉状態のみで、個人情報や機密情報は含まれません<br>
									• ユーザーごとに独立して保存されます（WordPressのユーザーセッションとは別）
								</p>
							</div>
						</details>
						
						<details style="margin-top: 10px;">
							<summary style="cursor: pointer; font-weight: 600;">アイコン色のカスタマイズ</summary>
							<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
								<p style="margin: 0;">
									セパレーターの5番目のパラメータでアイコン色を指定できます。<br>
									例: <code>separator: テキスト|#fff|#333|#333|#666</code> （最後の <code>#666</code> がアイコン色）<br>
									省略時はデフォルトで白（<code>#fff</code>）になります。
								</p>
							</div>
						</details>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $this->hide_unspecified_field_id ); ?>">
							未指定メニューの表示
						</label>
					</th>
					<td>
						<label>
							<input 
								type="checkbox" 
								name="<?php echo esc_attr( $this->settings_option_name ); ?>[hide_unspecified]" 
								id="<?php echo esc_attr( $this->hide_unspecified_field_id ); ?>"
								value="1"
								<?php checked( $hide_unspecified, true ); ?>
								<?php echo $is_file_config ? 'disabled' : ''; ?>
							>
							未指定のメニューを非表示にする
						</label>
						<p class="description">
							<strong>デフォルトの動作:</strong> 設定に含まれないメニューは、元の順序を保持したまま末尾に追加されます。<br>
							<br>
							<strong>このオプションを有効にした場合:</strong> 設定に含まれていないメニューは表示されません。<br>
							<strong>注意:</strong> 設定に含め忘れたメニューは表示されなくなります。設定を確認してください。
						</p>
					</td>
				</tr>
			</table>
			
			<?php if ( ! $is_file_config ) : ?>
				<?php submit_button( '設定を保存' ); ?>
			<?php else : ?>
				<?php submit_button( '設定を保存', 'primary', 'submit', true, [ 'disabled' => 'disabled' ] ); ?>
			<?php endif; ?>
			</form>
		</div>
		
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			const importButton = document.getElementById('import-current-menu');
			const textarea = document.getElementById('<?php echo esc_js( $this->menu_order_field_id ); ?>');
			
			if (importButton && textarea) {
				importButton.addEventListener('click', function() {
					if (textarea.value.trim() !== '') {
						if (!confirm('現在の設定が上書きされますが、よろしいですか？')) {
							return;
						}
					}
					
					// 現在のメニューを取得
					<?php
					global $menu;
					$menu_slugs = [];
					foreach ( $menu as $item ) {
						$slug = $item[2];
						// separatorは除外（WordPress標準とプラグインが追加したもの）
						if ( strpos( $slug, 'separator' ) === 0 ) {
							continue;
						}
						// 可能な限りURL形式に統一（人が認知しやすい形）。
						// - core: index.php / edit.php?post_type=page 等はそのまま
						// - plugin: $menuのslugがpage値だけでも、a[href]は admin.php?page=... になりやすいので統一する
						if ( 0 === strpos( $slug, 'admin.php?' ) || 0 === strpos( $slug, 'admin.php?page=' ) ) {
							$menu_slugs[] = $slug;
							continue;
						}

						$has_php   = strpos( $slug, '.php' ) !== false;
						$has_q     = strpos( $slug, '?' ) !== false;
						$has_slash = strpos( $slug, '/' ) !== false;

						// 例: wp_dbmanager/database-manager.php のような page 値（スラッシュ＋.php）も admin.php?page= に寄せる
						if ( ( ! $has_q && ! $has_php && ! $has_slash ) || ( $has_slash && $has_php && ! $has_q ) ) {
							$menu_slugs[] = 'admin.php?page=' . $slug;
						} else {
							$menu_slugs[] = $slug;
						}
					}
					$menu_slugs_json = wp_json_encode( $menu_slugs );
					?>
					
					// JSONはエスケープせずにそのままJSオブジェクトとして扱う
					const menuSlugs = <?php echo $menu_slugs_json; ?>;
					textarea.value = menuSlugs.join('\n');
					
					alert('メニューを挿入しました！\n必要に応じてseparatorを追加してください。');
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * 管理画面メニューを並び替え
	 *
	 * グループ構造を解析し、表示可能なメニューがあるグループのみを出力します。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reorder_admin_menu(): void {
		global $menu;

		// フィルタリング済みグループを取得（キャッシュされている）
		$groups = $this->get_filtered_groups();
		if ( null === $groups ) {
			return;
		}

		// 現在地のトップレベルメニュー（WP標準の判定結果）を使ってロック対象グループを決める。
		// REQUEST_URI は /wp-admin/ と index.php の揺れ等があるため避ける。
		$current_top_slug = '';
		if ( function_exists( 'get_admin_page_parent' ) ) {
			$parent = get_admin_page_parent();
			if ( is_string( $parent ) ) {
				$current_top_slug = $parent;
			}
		}
		if ( '' === $current_top_slug && isset( $GLOBALS['parent_file'] ) && is_string( $GLOBALS['parent_file'] ) ) {
			$current_top_slug = (string) $GLOBALS['parent_file'];
		}

		// 現在のメニューをスラッグでインデックス化（メニュー再構築に必要）
		$menu_by_slug = [];
		foreach ( $menu as $position => $item ) {
			$slug                 = $item[2];
			$menu_by_slug[ $slug ] = [
				'position' => $position,
				'item'     => $item,
			];
		}

		// 新しいメニュー配列を構築
		$new_menu     = [];
		$new_position = 0;
		$group_id     = 0;

		foreach ( $groups as $group ) {
			$group_id++;
			$accordion_group_class = '';
			$is_text_separator     = false;

			// セパレーター（グループがある場合のみ）
			if ( ! empty( $group['separator'] ) ) {
				$sep = $group['separator'];
				if ( 'separator' === $sep['type'] ) {
					// 通常のセパレーター
					$new_menu[ $new_position ] = [
						'',
						'read',
						'separator-custom-' . $new_position,
						'',
						'wp-menu-separator',
						'separator-custom-' . $new_position,
					];
				} elseif ( 'separator_text' === $sep['type'] ) {
					// テキスト付きセパレーター
					$id                         = 'separator-group-' . $group_id;
					$locked_class               = '';
					if ( '' !== $current_top_slug && ! empty( $group['menus'] ) ) {
						if ( in_array( $current_top_slug, (array) $group['menus'], true ) ) {
							$locked_class = ' dcm-accordion-locked';
						}
					}
					$new_menu[ $new_position ] = [
						'',
						'read',
						$id,
						'',
						'wp-menu-separator dcm-accordion-separator' . $locked_class,
						$id,
					];
					$is_text_separator          = true;
					$accordion_group_class      = 'dcm-accordion-group-' . $id;
				}
				$new_position++;
			}

			// グループ内のメニュー
			foreach ( $group['menus'] as $slug ) {
				if ( isset( $menu_by_slug[ $slug ] ) ) {
					$item = $menu_by_slug[ $slug ]['item'];

					// アコーディオン対象のメニューにグループ情報をCSSクラスとして付与（JS側の突合を不要にする）
					if ( $is_text_separator && ! empty( $accordion_group_class ) ) {
						$existing_classes = isset( $item[4] ) ? (string) $item[4] : '';
						$item[4]          = trim( $existing_classes . ' dcm-accordion-menu-item ' . $accordion_group_class );
					}

					$new_menu[ $new_position ] = $item;
					unset( $menu_by_slug[ $slug ] );
					$new_position++;
				}
			}
		}

		// 設定に含まれていないメニューを末尾に追加（オプションが無効な場合のみ）
		if ( ! $this->get_hide_unspecified_setting() ) {
			foreach ( $menu_by_slug as $slug => $data ) {
				$new_menu[ $new_position ] = $data['item'];
				$new_position++;
			}
		}

		// メニューを置き換え
		$menu = $new_menu;
	}

	/**
	 * パース結果をグループ構造に変換
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, string>> $lines パース済みの行
	 *
	 * @return array<int, array<string, mixed>> グループ構造
	 */
	private function build_menu_groups( array $lines ): array {
		$groups        = [];
		$current_group = [
			'separator' => null,
			'menus'     => [],
		];

		foreach ( $lines as $line ) {
			if ( 'separator' === $line['type'] || 'separator_text' === $line['type'] ) {
				// 前のグループを保存（メニューがある場合）
				if ( ! empty( $current_group['menus'] ) || null !== $current_group['separator'] ) {
					$groups[] = $current_group;
				}

				// 新しいグループを開始
				$current_group = [
					'separator' => $line,
					'menus'     => [],
				];
			} elseif ( 'menu' === $line['type'] ) {
				$current_group['menus'][] = $line['value'];
			}
		}

		// 最後のグループを追加
		if ( ! empty( $current_group['menus'] ) || null !== $current_group['separator'] ) {
			$groups[] = $current_group;
		}

		return $groups;
	}

	/**
	 * 表示可能なメニューがあるグループのみをフィルタリング
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $groups         グループ構造
	 * @param array<string, array<string, mixed>> $menu_by_slug メニュー配列
	 *
	 * @return array<int, array<string, mixed>> フィルタリングされたグループ
	 */
	private function filter_groups_with_visible_menus( array $groups, array $menu_by_slug ): array {
		$filtered = [];

		foreach ( $groups as $group ) {
			// セパレーターがない場合は常に含める
			if ( null === $group['separator'] ) {
				$filtered[] = $group;
				continue;
			}

			// グループ内に表示可能なメニューがあるかチェック
			$has_visible_menu = false;
			foreach ( $group['menus'] as $slug ) {
				if ( isset( $menu_by_slug[ $slug ] ) ) {
					$has_visible_menu = true;
					break;
				}
			}

			// 表示可能なメニューがあるグループのみ追加
			if ( $has_visible_menu ) {
				$filtered[] = $group;
			}
		}

		return $filtered;
	}

	/**
	 * セパレーター用のスタイルを出力
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_separator_styles(): void {
		// フィルタリング済みグループを取得（キャッシュされている）
		$groups = $this->get_filtered_groups();
		if ( null === $groups ) {
			return;
		}

		$css_rules = [];
		$group_id  = 0;

		foreach ( $groups as $group ) {
			$group_id++;

			if ( empty( $group['separator'] ) ) {
				continue;
			}

			$sep = $group['separator'];
			if ( 'separator_text' !== $sep['type'] ) {
				continue;
			}

			$id           = 'separator-group-' . $group_id;
			$text         = $sep['text'];
			$bg_color     = ! empty( $sep['bg_color'] ) ? $sep['bg_color'] : '';
			$text_color   = ! empty( $sep['text_color'] ) ? $sep['text_color'] : '#a0a5aa';
			$border_color = ! empty( $sep['border_color'] ) ? $sep['border_color'] : '';

			// カラーコードのバリデーション（CSSインジェクション対策）
			$bg_color     = $this->sanitize_color_code( $bg_color );
			$text_color   = $this->sanitize_color_code( $text_color );
			$border_color = $this->sanitize_color_code( $border_color );

			// separatorのli要素自体のスタイル
			$css_rules[] = sprintf(
				'li#%s { height: auto !important; min-height: 36px; padding: 0 !important; margin: 0; }',
				esc_attr( $id )
			);

			// デフォルトのseparator横線を非表示
			$css_rules[] = sprintf(
				'li#%s .separator { display: none; }',
				esc_attr( $id )
			);

			// テキスト表示用のスタイル（背景色と文字色を動的に設定）
			// CSS content内の文字列をエスケープ（二重引用符とバックスラッシュ）
			$escaped_text = addcslashes( $text, '"\\' );

			// 左ボーダーがある場合は左paddingを調整（ボーダー分減らす）
			$padding = ! empty( $border_color ) ? '10px 12px 10px 9px' : '10px 12px';

			$after_styles = sprintf(
				'content: "%s"; display: block; padding: %s; font-size: 14px; font-weight: 600; letter-spacing: 0.3px; line-height: 1.2;',
				$escaped_text,
				$padding
			);

			// 文字色
			if ( ! empty( $text_color ) ) {
				$after_styles .= sprintf( ' color: %s;', $text_color );
			}

			// 背景色（指定がある場合のみ）
			if ( ! empty( $bg_color ) ) {
				$after_styles .= sprintf( ' background-color: %s;', $bg_color );
			}

			// 左ボーダー（指定がある場合のみ）
			if ( ! empty( $border_color ) ) {
				$after_styles .= sprintf( ' border-left: 3px solid %s;', $border_color );
			}

			$css_rules[] = sprintf( 'li#%s::after { %s }', esc_attr( $id ), $after_styles );
		}

		if ( ! empty( $css_rules ) ) {
			$css = implode( "\n", $css_rules );
			wp_add_inline_style( 'common', $css );
		}
	}

	/**
	 * カラーコードをサニタイズ（CSSインジェクション対策）
	 *
	 * @since 1.0.0
	 *
	 * @param string $color カラーコード
	 *
	 * @return string サニタイズされたカラーコード（無効な場合は空文字）
	 */
	private function sanitize_color_code( string $color ): string {
		if ( empty( $color ) ) {
			return '';
		}

		// 16進数カラーコードのみ許可: #RGB, #RRGGBB, #RRGGBBAA
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color ) ) {
			return $color;
		}

		// WordPress標準のカラーネーム（安全な範囲）
		$allowed_color_names = [
			'transparent',
			'inherit',
			'currentColor',
		];

		if ( in_array( strtolower( $color ), $allowed_color_names, true ) ) {
			return strtolower( $color );
		}

		// 無効なカラーコードは空文字を返す
		return '';
	}

	/**
	 * メニュー設定をパース
	 *
	 * @since 1.0.0
	 *
	 * @param string $settings 設定文字列
	 *
	 * @return array<int, array<string, string>> パース結果の配列
	 */
	private function parse_menu_settings( string $settings ): array {
		$lines  = explode( "\n", $settings );
		$parsed = [];

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// 空行・コメントはスキップ
			if ( empty( $line ) || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			// separator: テキスト or separator: テキスト|背景色|文字色|左ボーダー色|アイコン色
			if ( 0 === strpos( $line, 'separator:' ) ) {
				$value = trim( substr( $line, 10 ) );
				$parts = explode( '|', $value );

				$text         = trim( $parts[0] );
				$bg_color     = isset( $parts[1] ) ? trim( $parts[1] ) : '';
				$text_color   = isset( $parts[2] ) ? trim( $parts[2] ) : '';
				$border_color = isset( $parts[3] ) ? trim( $parts[3] ) : '';
				$icon_color   = isset( $parts[4] ) ? trim( $parts[4] ) : '';

				$parsed[] = [
					'type'         => 'separator_text',
					'text'         => $text,
					'bg_color'     => $bg_color,
					'text_color'   => $text_color,
					'border_color' => $border_color,
					'icon_color'   => $icon_color,
				];
				continue;
			}

			// separator
			if ( 'separator' === $line ) {
				$parsed[] = [
					'type' => 'separator',
				];
				continue;
			}

			// 通常のメニュースラッグ
			$parsed[] = [
				'type' => 'menu',
				// 設定は人が認知しやすい「URL形式」を許容する（例: admin.php?page=xxx）。
				// 内部処理（並び替え等）では $menu の slug に正規化して使用する。
				'value' => $line,
			];
		}

		return $parsed;
	}

	/**
	 * 設定値/メニューhrefを、比較用の「wp-admin相対パス+クエリ」に正規化する。
	 *
	 * 例:
	 * - https://example.test/wp-admin/admin.php?page=foo → admin.php?page=foo
	 * - /wp-admin/edit.php?post_type=page → edit.php?post_type=page
	 * - wp_file_manager → admin.php?page=wp_file_manager（後方互換）
	 * - wp-dbmanager/database-manager.php → admin.php?page=wp-dbmanager/database-manager.php（後方互換）
	 *
	 * @since 1.0.0
	 *
	 * @param string $value 設定の1行、またはhref相当
	 * @return string 正規化済みの相対パス+クエリ
	 */
	private function normalize_admin_href_for_matching( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// フルURLなら wp-admin 配下に揃える
		if ( 0 === strpos( $value, 'http://' ) || 0 === strpos( $value, 'https://' ) ) {
			$path  = (string) wp_parse_url( $value, PHP_URL_PATH );
			$query = (string) wp_parse_url( $value, PHP_URL_QUERY );

			$wp_admin_pos = strpos( $path, '/wp-admin/' );
			if ( false !== $wp_admin_pos ) {
				$path = substr( $path, $wp_admin_pos + strlen( '/wp-admin/' ) );
			} else {
				$path = ltrim( $path, '/' );
			}
			$value = $path . ( '' !== $query ? '?' . $query : '' );
		}

		$value = ltrim( $value, '/' );
		if ( 0 === strpos( $value, 'wp-admin/' ) ) {
			$value = substr( $value, strlen( 'wp-admin/' ) );
		}

		// 旧形式（slugのみ）をURL形式へ寄せる
		$has_php   = strpos( $value, '.php' ) !== false;
		$has_q     = strpos( $value, '?' ) !== false;
		$has_slash = strpos( $value, '/' ) !== false;

		if ( ! $has_php && ! $has_q && ! $has_slash && 0 !== strpos( $value, 'admin.php' ) ) {
			return 'admin.php?page=' . $value;
		}

		// wp-dbmanager/database-manager.php のような page 値（スラッシュ＋.php）も admin.php?page= に寄せる
		if ( $has_slash && $has_php && ! $has_q && 0 !== strpos( $value, 'admin.php' ) ) {
			return 'admin.php?page=' . $value;
		}

		return $value;
	}

	/**
	 * WordPressコア（wp-admin/menu-header.php）の判定に合わせて、menu_slug から href を生成する。
	 *
	 * @since 1.0.0
	 *
	 * @param string $menu_slug $menu[*][2] の値
	 * @return string href相当（wp-admin相対）
	 */
	private function get_admin_href_from_menu_slug( string $menu_slug ): string {
		$menu_hook = get_plugin_page_hook( $menu_slug, 'admin.php' );
		$menu_file = $menu_slug;
		$pos       = strpos( $menu_file, '?' );

		if ( false !== $pos ) {
			$menu_file = substr( $menu_file, 0, $pos );
		}

		$is_plugin_page = ! empty( $menu_hook )
			|| ( ( 'index.php' !== $menu_slug )
				&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
				&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" ) );

		return $is_plugin_page ? 'admin.php?page=' . $menu_slug : $menu_slug;
	}

	/**
	 * 設定値（URL）を、現在の $menu に存在する menu_slug に解決する。
	 *
	 * 変換とマッチングをこの1箇所に閉じ込めるためのメソッド。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $groups グループ構造（menusは設定値の配列）
	 * @param array<string, array<string, mixed>> $menu_by_slug $menu をslugで引ける配列
	 * @param array<int, array<int, mixed>> $menu $menu そのもの
	 * @return array<int, array<string, mixed>> menusをmenu_slug配列に解決したグループ
	 */
	private function resolve_groups_to_menu_slugs( array $groups, array $menu_by_slug, array $menu ): array {
		$menu_by_href = [];

		foreach ( $menu as $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$slug = (string) $item[2];
			$href = $this->get_admin_href_from_menu_slug( $slug );
			$key  = $this->normalize_admin_href_for_matching( $href );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $menu_by_href[ $key ] ) ) {
				$menu_by_href[ $key ] = $slug;
			}
		}

		foreach ( $groups as &$group ) {
			if ( empty( $group['menus'] ) ) {
				continue;
			}

			$resolved = [];
			foreach ( (array) $group['menus'] as $value ) {
				$key = $this->normalize_admin_href_for_matching( (string) $value );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $menu_by_href[ $key ] ) ) {
					continue;
				}

				$slug = $menu_by_href[ $key ];
				if ( isset( $menu_by_slug[ $slug ] ) ) {
					$resolved[] = $slug;
				}
			}

			$group['menus'] = $resolved;
		}
		unset( $group );

		return $groups;
	}

	/**
	 * フィルタリング済みのグループ情報を取得（キャッシュ機能付き）
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>|null グループ情報の配列、または null
	 */
	private function get_filtered_groups(): ?array {
		// キャッシュがあればそれを返す
		if ( null !== $this->cached_groups ) {
			return $this->cached_groups;
		}

		// ファイル優先で設定を取得
		$settings = $this->get_menu_order_setting();
		if ( empty( $settings ) ) {
			$this->cached_groups = null;
			return null;
		}

		$lines = $this->parse_menu_settings( $settings );
		if ( empty( $lines ) ) {
			$this->cached_groups = null;
			return null;
		}

		// 現在のメニューをスラッグでインデックス化
		global $menu;
		$menu_by_slug = [];
		foreach ( $menu as $position => $item ) {
			$slug                 = $item[2];
			$menu_by_slug[ $slug ] = [
				'position' => $position,
				'item'     => $item,
			];
		}

		// グループ構造を構築（menusは設定値の配列のまま）
		$groups = $this->build_menu_groups( $lines );

		// 設定値（URL）を現在の $menu の menu_slug に解決（変換+突合を1箇所に集約）
		$groups = $this->resolve_groups_to_menu_slugs( $groups, $menu_by_slug, $menu );

		$groups = $this->filter_groups_with_visible_menus( $groups, $menu_by_slug );

		// キャッシュに保存
		$this->cached_groups = $groups;

		return $groups;
	}

	/**
	 * フィルタリング済みグループからアコーディオン用データを構築
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $groups フィルタリング済みグループ
	 *
	 * @return array<int, array<string, mixed>>|null アコーディオングループ情報、または null
	 */
	private function build_accordion_data( array $groups ): ?array {
		$accordion_groups = [];
		$group_id         = 0;

		foreach ( $groups as $group ) {
			$group_id++;

			if ( empty( $group['separator'] ) || 'separator_text' !== $group['separator']['type'] ) {
				continue;
			}

			$separator_id = 'separator-group-' . $group_id;
			// JS側は a[href]（wp-admin相対）で突合するため、コア同等のhrefに変換して渡す。
			$menu_slugs = [];
			foreach ( (array) $group['menus'] as $slug ) {
				$href        = $this->get_admin_href_from_menu_slug( (string) $slug );
				$menu_slugs[] = $this->normalize_admin_href_for_matching( $href );
			}
			$icon_color   = ! empty( $group['separator']['icon_color'] ) ? $group['separator']['icon_color'] : '';

			$accordion_groups[] = [
				'separator_id' => $separator_id,
				'menu_slugs'   => $menu_slugs,
				'icon_color'   => $icon_color,
			];
		}

		return ! empty( $accordion_groups ) ? $accordion_groups : null;
	}

	/**
	 * アコーディオン用のグループ情報を取得
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>|null グループ情報の配列、または null
	 */
	private function get_accordion_groups(): ?array {
		// アコーディオンが無効の場合は何もしない（ファイル優先）
		if ( ! $this->get_accordion_setting() ) {
			return null;
		}

		// フィルタリング済みグループを取得（キャッシュされている）
		$groups = $this->get_filtered_groups();
		if ( null === $groups ) {
			return null;
		}

		// アコーディオン用データに変換
		return $this->build_accordion_data( $groups );
	}

	/**
	 * アコーディオン機能のCSSを出力
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function output_accordion_styles(): void {
		if ( ! $this->get_accordion_setting() ) {
			return;
		}

		$groups = $this->get_filtered_groups();
		if ( null === $groups ) {
			return;
		}

		?>
		<style id="dcm-accordion-styles">
		/* アコーディオン: セパレーターをクリック可能に */
		.dcm-accordion-separator {
			cursor: pointer !important;
			position: relative;
		}
		
		.dcm-accordion-separator:not(.dcm-accordion-locked):hover::after {
			opacity: 0.8;
		}
		
		/* 現在地を含むグループは閉じられない（クリック不可） */
		.dcm-accordion-separator.dcm-accordion-locked {
			cursor: default !important;
		}
		
		.dcm-accordion-separator.dcm-accordion-locked::before {
			display: none;
		}
		
		/* アコーディオン: 開閉アイコン（デフォルト） */
		.dcm-accordion-separator::before {
			content: '▼';
			position: absolute;
			right: 12px;
			top: 50%;
			transform: translateY(-50%);
			font-size: 10px;
			color: #fff;
			transition: transform 0.2s ease;
			pointer-events: none;
			z-index: 1;
		}
		
		.dcm-accordion-separator.dcm-collapsed::before {
			content: '▶';
		}
		
		<?php
		// 各グループのアイコン色を個別に設定（separator_textのみ）
		$group_id = 0;
		foreach ( $groups as $group ) {
			$group_id++;
			if ( empty( $group['separator'] ) || 'separator_text' !== $group['separator']['type'] ) {
				continue;
			}

			$icon_color = ! empty( $group['separator']['icon_color'] ) ? $this->sanitize_color_code( $group['separator']['icon_color'] ) : '';
			if ( empty( $icon_color ) ) {
				continue;
			}

			$separator_id = esc_attr( 'separator-group-' . $group_id );
			echo sprintf(
				'li#%s.dcm-accordion-separator::before { color: %s !important; }' . "\n\t\t",
				$separator_id,
				$icon_color
			);
		}
		?>
		
		/* アコーディオン: グループメニューの開閉アニメーション */
		.dcm-accordion-menu-item {
			transition: all 0.3s ease;
		}
		
		.dcm-accordion-menu-item.dcm-hidden {
			display: none !important;
		}
		
		/* アコーディオン: 初期化中はメニューを非表示（FOUCを防ぐ） */
		body.dcm-accordion-loading #adminmenu {
			opacity: 0;
		}
		</style>
		<?php
	}

	/**
	 * アコーディオン機能のJavaScriptを出力
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function output_accordion_scripts(): void {
		if ( ! $this->get_accordion_setting() ) {
			return;
		}
		$groups = $this->get_filtered_groups();
		if ( null === $groups ) {
			return;
		}

		$has_text_separator = false;
		foreach ( $groups as $group ) {
			if ( ! empty( $group['separator'] ) && 'separator_text' === $group['separator']['type'] && ! empty( $group['menus'] ) ) {
				$has_text_separator = true;
				break;
			}
		}
		if ( ! $has_text_separator ) {
			return;
		}
		
		// 設定のハッシュ値を生成（設定が変わったら古いデータを無視するため）
		// ファイル設定が有効な場合はファイル内容のハッシュを使用
		$config = $this->load_config_from_file();
		if ( null !== $config ) {
			$config_hash = md5( wp_json_encode( $config ) );
		} else {
			$db_settings = $this->get_db_settings();
			$config_hash = md5( wp_json_encode( $db_settings ) );
		}
		$inline_data = [
			'config_hash' => $config_hash,
		];

		wp_enqueue_script(
			'dcm-admin-accordion',
			plugin_dir_url( __FILE__ ) . 'assets/js/dcm-admin-accordion.js',
			[],
			'1.0.0',
			true
		);

		wp_add_inline_script(
			'dcm-admin-accordion',
			'window.dcmAdminMenuAccordionData = ' . wp_json_encode( $inline_data ) . ';',
			'before'
		);
	}

	/**
	 * プラグイン一覧のアクションリンクに「リセット」を追加
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $links 既存リンク
	 *
	 * @return array<int, string> 追記後のリンク
	 */
	public function add_reset_action_link( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		// plugins.php の検索/絞り込み状態を維持したままリセットできるようにする。
		// サブディレクトリ設置時に REQUEST_URI を site_url() へ渡すとパスが二重になるケースがあるため、
		// admin_url('plugins.php') をベースに $_GET のクエリだけを合成する。
		$current_url  = admin_url( 'plugins.php' );
		$current_args = [];
		foreach ( (array) $_GET as $key => $value ) {
			if ( ! is_string( $key ) || is_array( $value ) ) {
				continue;
			}
			$current_args[ sanitize_key( $key ) ] = wp_unslash( (string) $value );
		}
		if ( ! empty( $current_args ) ) {
			$current_url = add_query_arg( $current_args, $current_url );
		}
		$current_url = remove_query_arg( [ 'dcm_amo_reset', 'dcm_amo_reset_done', '_wpnonce', 'action', 'action2', 'plugin', 'checked' ], $current_url );
		$url         = wp_nonce_url(
			add_query_arg( [ 'dcm_amo_reset' => '1' ], $current_url ),
			'dcm_amo_reset'
		);

		$links[] = sprintf(
			'<a href="%1$s" onclick="return confirm(\'DBに保存された設定を初期化します。ファイル設定(JSON)は変更されません。よろしいですか？\');">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'リセット', 'dcm-admin-menu-organizer' )
		);

		return $links;
	}

	/**
	 * リセットリクエストを処理
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_reset_action(): void {
		if ( ! isset( $_GET['dcm_amo_reset'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset this setting.', 'dcm-admin-menu-organizer' ) );
		}

		check_admin_referer( 'dcm_amo_reset' );

		// 設定を初期状態に戻す
		delete_option( $this->settings_option_name );

		// キャッシュもリセット
		$this->cached_groups = null;
		$this->cached_config = null;

		// リセット後は「元のプラグイン一覧URL（検索/絞り込み含む）」へ戻す（open redirect対策で検証は必須）。
		$default_redirect = admin_url( 'plugins.php' );
		$referer          = wp_get_referer();

		if ( is_string( $referer ) && '' !== $referer ) {
			$redirect_url = add_query_arg(
				[ 'dcm_amo_reset_done' => '1' ],
				remove_query_arg( [ 'dcm_amo_reset', 'dcm_amo_reset_done', '_wpnonce' ], $referer )
			);
		} else {
			$redirect_url = add_query_arg( [ 'dcm_amo_reset_done' => '1' ], $default_redirect );
		}

		wp_safe_redirect( wp_validate_redirect( $redirect_url, $default_redirect ) );
		exit;
	}

	/**
	 * リセット完了の管理画面通知を表示
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_reset_notice(): void {
		if ( ! isset( $_GET['dcm_amo_reset_done'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( sprintf( __( '%s の設定を初期化しました。', 'dcm-admin-menu-organizer' ), $this->plugin_name ) ); ?></p>
		</div>
		<?php
	}
}

// プラグイン初期化
new DCM_Admin_Menu_Organizer();

