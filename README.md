# DCM Admin Menu Organizer

WordPress管理画面の親メニューの表示順を制御し、カスタムセパレーターを追加できるプラグインです。

## 特徴

- **シンプルな設定方法** - テキストエリアにメニュースラッグを記載するだけ
- **テキスト付きセパレーター** - メニューを視覚的にグループ化
- **カスタムカラー対応** - セパレーターの背景色・文字色を指定可能
- **アコーディオン機能** - メニューグループをクリックで開閉可能
- **ワンクリックインポート** - デフォルト状態のメニュー構成を即座に取り込み
- **未指定メニューの制御** - 設定外のメニューを末尾に追加するか、非表示にするかを選択可能
- **ファイル設定対応** - JSONファイルで設定を管理可能（エンタープライズ向け）
- **ワンクリックリセット** - プラグイン一覧から設定を初期化可能（DBの設定値を削除）

## 機能一覧

### 1. メニューの並び替え
親メニューの表示順を自由に変更できます。

### 2. セパレーター
- **通常のセパレーター**: 横線のみ（WordPress標準）
- **テキスト付きセパレーター**: ラベル付きで視覚的にグループ化
- **カラーカスタマイズ**: 背景色・文字色・左ボーダー色を個別に指定可能

### 3. アコーディオン機能
- テキストセパレーター（`separator: xxx`）をクリックで開閉可能
- 開閉状態はブラウザに記憶されます（localStorage使用、Cookieは使用しません）
- 設定画面で一括ON/OFF切り替え可能
- すべてのテキストセパレーターに適用されます
- キーボード操作: Tabでセパレーターにフォーカス → Enter/Spaceで開閉
- UX保護のため、**現在表示中の画面を含むグループは初期表示で必ず展開**されます（ユーザー操作で閉じることは可能）

### 4. 未指定メニューの制御
- デフォルト: 設定に含まれないメニューは、元の順序を保持したまま末尾に追加されます
- オプション有効時: 設定に含まれないメニューを非表示にできます
- 設定画面でON/OFF切り替え可能

### 5. デフォルトメニューのインポート
ボタン一つでデフォルト状態のメニュー構成を設定画面に取り込めます。

## インストール

1. プラグインフォルダを `wp-content/plugins/` にアップロード
```bash
wp-content/plugins/dcm-admin-menu-organizer/
```

2. WordPress管理画面からプラグインを有効化

3. `設定 → メニュー表示順` から設定

## 使い方

### 基本的な設定方法

`設定 → メニュー表示順` にアクセスし、テキストエリアにメニュースラッグを1行ずつ記載します。

```
# ダッシュボード
index.php

# 投稿
edit.php

separator

# メディア
upload.php
```

### 設定記法

#### 1. メニュースラッグ
メニューのスラッグをそのまま記載します。

```
index.php
edit.php
edit.php?post_type=page
options-general.php
```

#### 2. コメント
`#` で始まる行はコメントとして無視されます。

```
# これはコメントです
index.php
```

#### 3. 空行
空行は無視されます（見やすさのために自由に使えます）。

```
index.php

edit.php
```

#### 4. 通常のセパレーター
横線のみを表示します。

```
separator
```

#### 5. テキスト付きセパレーター
ラベルを付けてメニューをグループ化します。

```
separator: コンテンツ管理
```

#### 6. カラー付きセパレーター
背景色と文字色を指定できます。

```
separator: 入稿関連|#f0f6fc|#0969da
```

フォーマット: `separator: テキスト|背景色|文字色|左ボーダー色|アイコン色`
- 背景色は省略可能（省略時は透明）
- 文字色は省略可能（省略時は `#a0a5aa`）
- 左ボーダー色は省略可能（指定時は3pxの左ボーダーを表示）
- アイコン色は省略可能（アコーディオン有効時のみ。開閉アイコンの色を指定）
- カラーコードは `#` から始まる16進数形式

```
separator: 入稿関連|#f0f6fc|#0969da|#0969da
```

```
separator: 入稿関連|#f0f6fc|#0969da|#0969da|#fff
```

#### 7. アコーディオン機能
設定画面で「アコーディオンを有効にする」にチェックを入れると、すべてのテキストセパレーターがクリックで開閉可能になります。

- セパレーターをクリックするとグループが折りたたまれます
- 開閉状態はブラウザに記憶されます（再読み込み後も維持）
- 現在表示中の画面を含むグループは、UX保護のため初期表示で必ず展開されます（ユーザー操作で閉じることは可能）
- 通常のセパレーター（`separator` のみ）はアコーディオンになりません
- localStorageを使用して保存されます（Cookieは使用しません）

#### 8. 未指定メニューの制御
設定画面で「未指定のメニューを非表示にする」にチェックを入れると、設定に含まれないメニューが非表示になります。

- デフォルト: 設定に含まれないメニューは末尾に追加されます
- オプション有効時: 設定に含まれないメニューは非表示になります
- 注意: 設定に含め忘れたメニューは表示されなくなります

#### 9. profile.phpについて
`profile.php`（プロフィールページ）は一般ユーザーの左メニューにのみ表示されるメニューです。設定に含めないと、一般ユーザーがログインした際に表示されなくなります。

## 設定例

### 例1: 基本的な並び替え

```
# ダッシュボードと投稿
index.php
edit.php

separator: コンテンツ管理

# カスタム投稿タイプ
edit.php?post_type=product
edit.php?post_type=news

separator

# 設定系
options-general.php
tools.php
```

### 例2: カラー付きセパレーター

```
index.php
edit.php

separator: 入稿関連|#fff3cd|#856404

edit.php?post_type=article
edit.php?post_type=manuscript

separator: 管理機能|#d1ecf1|#0c5460

options-general.php
users.php
```

### 例3: 複雑な構成

```
# ダッシュボード
index.php

separator: コンテンツ

edit.php
upload.php
edit.php?post_type=page

separator: カスタム投稿タイプ|#f0f6fc|#0969da

edit.php?post_type=product
edit.php?post_type=news
edit.php?post_type=event

separator

# 外観とプラグイン
themes.php
plugins.php

separator: システム管理|#fff3cd|#856404

users.php
tools.php
options-general.php
```

### 例4: アコーディオン機能を使った構成

設定画面で「アコーディオンを有効にする」にチェックを入れると、テキストセパレーターをクリックで開閉できます。

```
index.php

separator: 入稿関連|#f0f6fc|#0969da|#0969da

edit.php
upload.php
edit-comments.php
edit.php?post_type=page

separator: マスター系|#fff3cd|#856404|#856404

edit.php?post_type=custom-css-js
themes.php
plugins.php

separator: その他|#d1ecf1|#0c5460|#0c5460

users.php
tools.php
options-general.php
```

各グループをクリックすると折りたたむことができ、開閉状態はブラウザに記憶されます。

## 便利な機能

### デフォルト状態のメニューを挿入

設定画面の **「デフォルト状態のメニュー順序を挿入」** ボタンをクリックすると、初期表示時点のメニュー構成がテキストエリアに自動的に挿入されます。

1. ボタンをクリック
2. 自動的にメニュースラッグが入力される
3. 必要に応じて `separator` を追加
4. 「設定を保存」で反映

### 設定をリセットする

- 管理画面の「プラグイン」一覧（`/wp-admin/plugins.php`）で本プラグイン行の「リセット」をクリック
- 確認ダイアログでOKすると、保存済みの設定（メニュー順・アコーディオン・未指定メニュー非表示）が初期化されます
- JSONファイルを使っている場合は読み取り専用のため、リセットリンクはDB設定のみを削除します

## 仕様詳細

### 設定に含まれないメニューの扱い

- **デフォルト**: 設定に記載されていないメニューは、**元の順序を保持したまま末尾に追加**されます。これにより、新しいプラグインが追加した時でもメニューが消失することはありません。
- **オプション有効時**: 「未指定のメニューを非表示にする」オプションを有効にすると、設定に含まれないメニューは非表示になります。設定に含め忘れたメニューは表示されなくなるため、注意が必要です。

### セパレーターのID生成

テキスト付きセパレーターには、グループIDに基づいたIDが自動生成されます。

```
separator-group-1
separator-group-2
separator-group-3
...
```

このIDを使って、独自のCSSでさらにカスタマイズすることも可能です。

### アコーディオン機能の仕組み

- **開閉状態の記憶**: `localStorage` を使用して、各セパレーターの開閉状態を保存（Cookieは使用しません）
- **グループ化**: セパレーターから次のセパレーターまでのメニューを1つのグループとして扱う
- **権限考慮**: ユーザーに表示権限がないメニューのみのグループは自動的に非表示
- **現在地グループの初期展開**: 現在表示中の画面を含むグループは初期表示で必ず展開される（ユーザー操作で閉じることは可能）
- **アニメーション**: CSS transition を使用したスムーズな開閉
- **セキュリティー**: 保存されるのは開閉状態のみで、個人情報や機密情報は含まれません

### カラーコードの指定

カラーコードは以下の形式に対応しています：

- `#RGB` (例: `#fff`)
- `#RRGGBB` (例: `#ffffff`)
- その他CSS有効なカラーコード

## トラブルシューティング

### メニューが表示されない

1. プラグインが有効化されているか確認
2. 設定画面で「現在の親メニュー一覧」を表示して、正しいスラッグを確認
3. スラッグに余計なスペースが入っていないか確認

### セパレーターのテキストが表示されない

1. `separator:` の後にスペースがあるか確認（例: `separator: テキスト`）
2. ブラウザのキャッシュをクリア
3. 設定を保存し直す

### カラーが反映されない

1. カラーコードの形式を確認（`#` から始まる16進数）
2. 区切り文字 `|` が正しく使われているか確認
3. 例: `separator: テキスト|#f0f0f0|#333`

### 設定が保存されない

1. `manage_options` 権限を持つユーザーでログインしているか確認
2. WordPressのファイルパーミッションを確認

### アコーディオンが動作しない

1. 設定画面で「アコーディオンを有効にする」にチェックが入っているか確認
2. テキストセパレーター（`separator: xxx`）を使用しているか確認（通常の `separator` では動作しません）
3. ブラウザのJavaScriptが有効になっているか確認
4. ブラウザのコンソールでエラーが出ていないか確認

### アコーディオンの開閉状態をリセットしたい

ブラウザの開発者ツールを開き、コンソールで以下を実行：

```javascript
localStorage.removeItem('dcm_accordion_state');
location.reload();
```

## JSON設定ファイルサンプル

`wp-content/dcm-admin-menu-organizer/settings.json` を配置すると、ファイル設定がDBより優先され、管理画面は読み取り専用になります。

```json
{
  "menu_order": [
    "index.php",
    "separator: コンテンツ|#f0f6fc|#0969da|#0969da|#0969da",
    "edit.php",
    "upload.php",
    "options-general.php",
    "themes.php",
    "plugins.php"
  ],
  "accordion_enabled": true,
  "hide_unspecified": false
}
```

- `menu_order`: 並び順。`separator` / `separator: テキスト` / `separator: テキスト|背景色|文字色|左ボーダー色|アイコン色` の記法が使えます。
- `accordion_enabled`: trueでアコーディオン有効。
- `hide_unspecified`: trueで設定に含まれないメニューを非表示（デフォルトは末尾追加）。
- 設定ファイルのパスはフィルター `dcm_admin_menu_organizer_config_file` で変更可能。

## 技術仕様

### 要件

- **WordPress**: 5.0以上
- **PHP**: 8.0以上（型指定を使用）

### WordPress Coding Standards

このプラグインは WordPress Coding Standards に準拠しています。

### ファイル構成

```
dcm-admin-menu-organizer/
├── dcm-admin-menu-organizer.php  # メインファイル
└── README.md                      # このファイル
```

### フックとフィルター

プラグインは以下のWordPressフックを使用しています：

#### アクションフック

- `admin_menu` (優先度: 10) - 設定ページを追加
- `admin_init` - 設定を登録
- `admin_menu` (優先度: 999) - メニューを並び替え
- `admin_enqueue_scripts` - セパレーター用のCSSを出力
- `admin_enqueue_scripts` - アコーディオン用のJavaScript/CSSを出力

#### フィルターフック

- `dcm_admin_menu_organizer_config_file` - 設定ファイルのパスを変更可能にする

**使用例:**

```php
// functions.php などに追加
add_filter( 'dcm_admin_menu_organizer_config_file', function( $config_file ) {
    // カスタムパスに変更
    return '/path/to/custom/settings.json';
    
    // プラグインディレクトリ内に配置する例
    // return WP_PLUGIN_DIR . '/my-plugin/menu-settings.json';
} );
```

**デフォルト値:** `wp-content/dcm-admin-menu-organizer/settings.json`

### データベース

設定は WordPress の `wp_options` テーブルに保存されます。

- **オプション名**: `dcm_admin_menu_organizer_settings`
  - **データ型**: `ARRAY`
  - **保存形式**: 連想配列（以下のキー）
    - `menu_order` (string): プレーンテキスト（改行区切り）
    - `accordion_enabled` (bool)
    - `hide_unspecified` (bool)

## ライセンス

GPL v2 or later

## サポート

このプラグインはエンタープライズプロジェクト向けに開発されました。問題が発生した場合は、開発チームにお問い合わせください。

## 開発者向け情報

### カスタムCSSの追加

生成されるセパレーターIDを使って、さらにカスタマイズできます：

```css
/* 特定のセパレーターをカスタマイズ */
li#separator-text-1::after {
    font-size: 16px !important;
    padding: 15px !important;
}

/* すべてのテキストセパレーターに適用 */
li[id^="separator-text-"]::after {
    text-transform: uppercase;
    letter-spacing: 2px;
}
```

### メニュースラッグの取得

```php
global $menu;
foreach ($menu as $item) {
    $slug = $item[2];
    $title = wp_strip_all_tags($item[0]);
    echo "Slug: $slug, Title: $title\n";
}
```

---

**Made for enterprise WordPress projects**

