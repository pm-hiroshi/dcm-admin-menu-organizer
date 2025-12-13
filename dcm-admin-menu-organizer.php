<?php
/**
 * Plugin Name: DCM Admin Menu Organizer
 * Plugin URI: https://example.com
 * Description: 管理画面の親メニューの表示順を制御し、セパレーターを追加できます
 * Version: 1.2.3
 * Author: Your Name
 * Author URI: https://example.com
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
	 * オプション名
	 *
	 * @var string
	 */
	private string $option_name = 'dcm_admin_menu_order';

	/**
	 * アコーディオン機能の有効/無効オプション名
	 *
	 * @var string
	 */
	private string $accordion_option_name = 'dcm_admin_menu_accordion_enabled';

	/**
	 * 未指定メニューを非表示にするオプション名
	 *
	 * @var string
	 */
	private string $hide_unspecified_option_name = 'dcm_admin_menu_hide_unspecified';

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
	 * コンストラクタ
	 *
	 * WordPress のアクションフックに登録
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// デフォルトの設定ファイルパスを保存
		$this->default_config_file = WP_CONTENT_DIR . '/dcm-admin-menu-organizer/settings.json';
		
		/**
		 * 設定ファイルのパスをフィルターで変更可能にする
		 *
		 * @since 1.2.0
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
			$this->option_name,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_menu_order' ],
				'default'           => '',
			]
		);

		register_setting(
			'dcm_menu_organizer_group',
			$this->accordion_option_name,
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_boolean_option' ],
				'default'           => false,
			]
		);

		register_setting(
			'dcm_menu_organizer_group',
			$this->hide_unspecified_option_name,
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_boolean_option' ],
				'default'           => false,
			]
		);
	}

	/**
	 * 設定値をサニタイズ
	 *
	 * @since 1.0.0
	 *
	 * @param string $input 入力値
	 *
	 * @return string サニタイズされた文字列
	 */
	public function sanitize_menu_order( string $input ): string {
		// 権限チェック（明示的なセキュリティ対策）
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( $this->option_name, '' );
		}

		return sanitize_textarea_field( $input );
	}

	/**
	 * 真偽値オプション（アコーディオン/未指定メニュー非表示）をサニタイズ
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input 入力値
	 *
	 * @return bool サニタイズされた真偽値
	 */
	public function sanitize_boolean_option( $input ): bool {
		// 権限チェック（明示的なセキュリティ対策）
		if ( ! current_user_can( 'manage_options' ) ) {
			// 現在の値を返す（変更を拒否）
			$option_name = isset( $_POST[ $this->accordion_option_name ] ) ? $this->accordion_option_name : $this->hide_unspecified_option_name;
			return (bool) get_option( $option_name, false );
		}

		return (bool) $input;
	}

	/**
	 * 設定ファイルのパスが有効かどうかを検証
	 *
	 * パスの妥当性（セキュリティ）、ファイルの存在、読み取り可能性の両方をチェックします。
	 *
	 * @since 1.2.2
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @return bool ファイル設定が有効な場合は true
	 */
	private function is_file_config_active(): bool {
		return null !== $this->load_config_from_file();
	}

	/**
	 * メニュー順序の設定を取得（ファイル優先）
	 *
	 * @since 1.2.0
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
		return get_option( $this->option_name, '' );
	}

	/**
	 * アコーディオン設定を取得（ファイル優先）
	 *
	 * @since 1.2.0
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
		return (bool) get_option( $this->accordion_option_name, false );
	}

	/**
	 * 未指定メニュー非表示設定を取得（ファイル優先）
	 *
	 * @since 1.2.2
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
		return (bool) get_option( $this->hide_unspecified_option_name, false );
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
		$current_value       = $is_file_config ? $this->get_menu_order_setting() : get_option( $this->option_name, '' );
		$accordion_enabled   = $is_file_config ? $this->get_accordion_setting() : (bool) get_option( $this->accordion_option_name, false );
		$hide_unspecified    = $is_file_config ? $this->get_hide_unspecified_setting() : (bool) get_option( $this->hide_unspecified_option_name, false );
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
							<label for="<?php echo esc_attr( $this->option_name ); ?>">
								親メニュー表示順
							</label>
						</th>
						<td>
							<textarea 
								name="<?php echo esc_attr( $this->option_name ); ?>" 
								id="<?php echo esc_attr( $this->option_name ); ?>"
								rows="20" 
								cols="80"
								class="large-text code"
								<?php echo $is_file_config ? 'readonly' : ''; ?>
							><?php echo esc_textarea( $current_value ); ?></textarea>
							
							<?php if ( ! $is_file_config ) : ?>
								<p style="margin-top: 10px;">
									<button type="button" class="button" id="import-current-menu">
										現在のメニューをインポート
									</button>
									<span style="margin-left: 10px; color: #666; font-size: 12px;">
										※ 現在のメニュー順序をテキストエリアに挿入します
									</span>
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
						<label for="<?php echo esc_attr( $this->accordion_option_name ); ?>">
							アコーディオン機能
						</label>
					</th>
					<td>
						<label>
							<input 
								type="checkbox" 
								name="<?php echo esc_attr( $this->accordion_option_name ); ?>" 
								id="<?php echo esc_attr( $this->accordion_option_name ); ?>"
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
						<label for="<?php echo esc_attr( $this->hide_unspecified_option_name ); ?>">
							未指定メニューの表示
						</label>
					</th>
					<td>
						<label>
							<input 
								type="checkbox" 
								name="<?php echo esc_attr( $this->hide_unspecified_option_name ); ?>" 
								id="<?php echo esc_attr( $this->hide_unspecified_option_name ); ?>"
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
			const textarea = document.getElementById('<?php echo esc_js( $this->option_name ); ?>');
			
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
						$menu_slugs[] = $slug;
					}
					$menu_slugs_json = wp_json_encode( $menu_slugs );
					?>
					
					const menuSlugs = <?php echo esc_js( $menu_slugs_json ); ?>;
					textarea.value = '# 現在のメニュー順序\n' + menuSlugs.join('\n');
					
					alert('メニューをインポートしました！\n必要に応じてseparatorを追加してください。');
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

		// 現在のメニューをスラッグでインデックス化（メニュー再構築に必要）
		$menu_by_slug = [];
		foreach ( $menu as $position => $item ) {
			$slug                 = $item[2];
			$menu_by_slug[ $slug ] = [
				'position' => $position,
				'item'     => $item,
			];

			// admin.php?page=xxx 形式とプレーンslugの両方で引けるようにエイリアスを追加
			if ( strpos( $slug, 'admin.php?page=' ) === 0 ) {
				$page_slug = substr( $slug, strlen( 'admin.php?page=' ) );
				if ( ! empty( $page_slug ) ) {
					$menu_by_slug[ $page_slug ] = [
						'position' => $position,
						'item'     => $item,
					];
				}
			} elseif ( false === strpos( $slug, '.php' ) && false === strpos( $slug, '?' ) && false === strpos( $slug, '/' ) ) {
				$admin_slug = 'admin.php?page=' . $slug;
				$menu_by_slug[ $admin_slug ] = [
					'position' => $position,
					'item'     => $item,
				];
			}
		}

		// 新しいメニュー配列を構築
		$new_menu     = [];
		$new_position = 0;
		$group_id     = 0;

		foreach ( $groups as $group ) {
			$group_id++;

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
					$new_menu[ $new_position ] = [
						'',
						'read',
						$id,
						'',
						'wp-menu-separator',
						$id,
					];
				}
				$new_position++;
			}

			// グループ内のメニュー
			foreach ( $group['menus'] as $slug ) {
				if ( isset( $menu_by_slug[ $slug ] ) ) {
					$new_menu[ $new_position ] = $menu_by_slug[ $slug ]['item'];
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
	 * @since 1.1.0
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
				$current_group['menus'][] = $line['slug'];
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
	 * @since 1.1.0
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
				'slug' => $line,
			];
		}

		return $parsed;
	}

	/**
	 * フィルタリング済みのグループ情報を取得（キャッシュ機能付き）
	 *
	 * @since 1.2.0
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

			// admin.php?page=xxx 形式とプレーンslugの両方で引けるようにエイリアスを追加
			if ( strpos( $slug, 'admin.php?page=' ) === 0 ) {
				$page_slug = substr( $slug, strlen( 'admin.php?page=' ) );
				if ( ! empty( $page_slug ) ) {
					$menu_by_slug[ $page_slug ] = [
						'position' => $position,
						'item'     => $item,
					];
				}
			} elseif ( false === strpos( $slug, '.php' ) && false === strpos( $slug, '?' ) && false === strpos( $slug, '/' ) ) {
				$admin_slug = 'admin.php?page=' . $slug;
				$menu_by_slug[ $admin_slug ] = [
					'position' => $position,
					'item'     => $item,
				];
			}
		}

		// グループ構造を構築してフィルタリング
		$groups = $this->build_menu_groups( $lines );
		$groups = $this->filter_groups_with_visible_menus( $groups, $menu_by_slug );

		// キャッシュに保存
		$this->cached_groups = $groups;

		return $groups;
	}

	/**
	 * フィルタリング済みグループからアコーディオン用データを構築
	 *
	 * @since 1.2.0
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
			$menu_slugs   = $group['menus'];
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function output_accordion_styles(): void {
		$accordion_groups = $this->get_accordion_groups();
		if ( null === $accordion_groups ) {
			return;
		}

		?>
		<style id="dcm-accordion-styles">
		/* アコーディオン: セパレーターをクリック可能に */
		.dcm-accordion-separator {
			cursor: pointer !important;
			position: relative;
		}
		
		.dcm-accordion-separator:hover::after {
			opacity: 0.8;
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
		// 各グループのアイコン色を個別に設定
		foreach ( $accordion_groups as $group ) {
			if ( ! empty( $group['icon_color'] ) ) {
				$icon_color = $this->sanitize_color_code( $group['icon_color'] );
				if ( ! empty( $icon_color ) ) {
					$separator_id = esc_attr( $group['separator_id'] );
					echo sprintf(
						'li#%s.dcm-accordion-separator::before { color: %s !important; }' . "\n\t\t",
						$separator_id,
						$icon_color
					);
				}
			}
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
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function output_accordion_scripts(): void {
		$accordion_groups = $this->get_accordion_groups();
		if ( null === $accordion_groups ) {
			return;
		}

		$accordion_groups_json = wp_json_encode( $accordion_groups );
		
		// 設定のハッシュ値を生成（設定が変わったら古いデータを無視するため）
		// ファイル設定が有効な場合はファイル内容のハッシュを使用
		$config = $this->load_config_from_file();
		if ( null !== $config ) {
			$config_hash = md5( wp_json_encode( $config ) );
		} else {
			$settings    = get_option( $this->option_name, '' );
			$config_hash = md5( $settings );
		}
		?>
		<script id="dcm-accordion-script">
		(function() {
			// 初期化中はメニューを非表示（FOUCを防ぐ）
			document.body.classList.add('dcm-accordion-loading');
			
			const accordionGroups = <?php echo esc_js( $accordion_groups_json ); ?>;
			const storageKey = 'dcm_accordion_state';
			const configHash = '<?php echo esc_js( $config_hash ); ?>';
			
			// localStorage から開閉状態を取得
			function getAccordionState() {
				try {
					const stored = localStorage.getItem(storageKey);
					if (!stored) {
						return {};
					}
					
					const data = JSON.parse(stored);
					
					// 設定ハッシュが一致しない場合は古いデータなので無視
					if (data.config_hash !== configHash) {
						console.info('DCM Accordion: Configuration changed, resetting accordion state');
						return {};
					}
					
					return data.states || {};
				} catch (e) {
					return {};
				}
			}
			
			// localStorage に開閉状態を保存
			function saveAccordionState(states) {
				try {
					const data = {
						config_hash: configHash,
						states: states
					};
					localStorage.setItem(storageKey, JSON.stringify(data));
				} catch (e) {
					// エラーは無視
				}
			}
			
			// アコーディオンを初期化
			function initAccordion() {
				const state = getAccordionState();
				
				accordionGroups.forEach(function(group) {
					const separatorId = group.separator_id;
					const menuSlugs = group.menu_slugs;
					
					const separatorLi = document.getElementById(separatorId);
					if (!separatorLi) {
						console.warn('DCM Accordion: Separator not found:', separatorId);
						return;
					}
					
					// セパレーターにクラスを追加
					separatorLi.classList.add('dcm-accordion-separator');
					
					// グループ内のメニュー要素を取得
					const menuItems = [];
					menuSlugs.forEach(function(slug) {
						// admin.php?page=xxx のようなケース用に page スラッグも計算
						const pageSlug = slug.startsWith('admin.php?page=') ? slug.split('admin.php?page=').pop() : null;

						// 複数の方法でメニュー要素を検索
						let menuLi = document.querySelector('#adminmenu > li#menu-' + CSS.escape(slug));
						
						if (!menuLi && pageSlug) {
							menuLi = document.querySelector('#adminmenu > li#toplevel_page_' + CSS.escape(pageSlug));
						}

						if (!menuLi && pageSlug) {
							menuLi = document.querySelector('#adminmenu > li#menu-' + CSS.escape(pageSlug));
						}

						if (!menuLi) {
							// post_type などの場合
							const escapedSlug = slug.replace(/[^\w-]/g, function(c) {
								return '-' + c.charCodeAt(0);
							});
							menuLi = document.querySelector('#adminmenu > li[id*="' + escapedSlug + '"]');
						}
						
						if (!menuLi) {
							// 部分一致で検索
							const allMenuItems = document.querySelectorAll('#adminmenu > li');
							for (let i = 0; i < allMenuItems.length; i++) {
								const item = allMenuItems[i];
								const href = item.querySelector('a')?.getAttribute('href');
								if (href && (href.indexOf(slug) !== -1 || (pageSlug && href.indexOf(pageSlug) !== -1))) {
									menuLi = item;
									break;
								}
							}
						}
						
						if (menuLi) {
							menuLi.classList.add('dcm-accordion-menu-item');
							menuLi.dataset.accordionGroup = separatorId;
							menuItems.push(menuLi);
						}
					});
					
					if (menuItems.length === 0) {
						console.warn('DCM Accordion: No menu items found for group:', separatorId);
						return;
					}
					
					// 初期状態を適用（デフォルトは開）
					const isCollapsed = state[separatorId] === 'collapsed';
					if (isCollapsed) {
						separatorLi.classList.add('dcm-collapsed');
						menuItems.forEach(function(item) {
							item.classList.add('dcm-hidden');
						});
					}
					
					// クリックイベント
					separatorLi.addEventListener('click', function(e) {
						e.preventDefault();
						e.stopPropagation();
						
						const nowCollapsed = separatorLi.classList.toggle('dcm-collapsed');
						
						menuItems.forEach(function(item) {
							item.classList.toggle('dcm-hidden');
						});
						
						// 状態を保存
						const newState = getAccordionState();
						newState[separatorId] = nowCollapsed ? 'collapsed' : 'expanded';
						saveAccordionState(newState);
					});
				});
				
				// 初期化完了：メニューを表示
				document.body.classList.remove('dcm-accordion-loading');
			}
			
			// DOM読み込み後に初期化
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initAccordion);
			} else {
				initAccordion();
			}
		})();
		</script>
		<?php
	}
}

// プラグイン初期化
new DCM_Admin_Menu_Organizer();

