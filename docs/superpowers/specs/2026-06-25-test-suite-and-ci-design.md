# テストスイート + GitHub Actions CI 設計

- 日付: 2026-06-25
- 対象: `amane/blog-distribution-wp`（WordPress プラグイン, PHP 7.4 / 8.x）
- 目的: 単体 / 結合 / E2E の3層テストを整備し、GitHub Actions に CI を登録する。`includes/` の行カバレッジ 90% 以上をゲートとする。ローカルは Docker のみでテスト実行できるようにする。

## 背景・現状

- 4 クラス構成の小規模プラグイン:
  - `includes/Plugin.php` — フック登録、cron 同期 (`runSync`)、公開時の `reportPublication` (`onPostPublished`)、activate/deactivate。
  - `includes/Sync/ArticleSyncer.php` — `SyncResult` と `ArticleSyncer::sync()`（AMANE API から記事取得→WP 投稿生成）。
  - `includes/Cron/ArticleSyncJob.php` — どのフックにも未接続で `Plugin::runSync` と重複する**デッドコード**。
  - `includes/Admin/SettingsPage.php` — 管理画面の設定フォーム登録・描画。
- 外部依存は AMANE SDK (`Amane\BlogSdk\AmaneClient`) のみ。`AmaneClient::make($url, $token)` を**メソッド内部で直接生成**しており、差し替えできずテストしにくい。
- テスト・CI は現状ゼロ。`composer.json` は `require` のみ、`composer.lock` 無し。
- ローカル環境: **PHP / Composer なし**、**Docker / Node あり**（Windows）。CI（GitHub Actions）には `setup-php` で PHP を用意する。

## 決定事項（確定）

| 項目 | 決定 |
|---|---|
| テスト基盤 | フル構成（単体=Brain Monkey, 結合=WP公式テストスイート, E2E=Playwright+wp-env） |
| リファクタ | `ClientFactory` の継ぎ目を導入（振る舞い不変） |
| PHP マトリクス | 7.4 / 8.1 / 8.3 |
| ローカル実行 | `docker-compose.yml` で Docker のみで単体・結合テストを実行可能にする |
| ArticleSyncJob | **削除**し `Plugin::runSync` に一本化 |
| カバレッジ | 単体+結合の合算で `includes/` 行カバレッジ **90% 以上**を CI ゲート |

## アーキテクチャ — テスタビリティの継ぎ目

振る舞いを変えずに SDK 生成を差し替え可能にする。PHP 7.4 を対象とするためコンストラクタプロパティ昇格（8.0+）は使わない。

### 新規 `includes/Sdk/ClientFactory.php`

```php
namespace Amane\WpPlugin\Sdk;

use Amane\BlogSdk\AmaneClient;

class ClientFactory
{
    public function make(string $url, string $token): AmaneClient
    {
        return AmaneClient::make($url, $token);
    }
}
```

### `ArticleSyncer` — コンストラクタ注入 + フィルタ既定

```php
private ClientFactory $clientFactory;

public function __construct(?ClientFactory $clientFactory = null)
{
    // 未指定時はフィルタ経由で既定生成（E2E では mu-plugin で差し替え可能）
    $this->clientFactory = $clientFactory ?? apply_filters('amane_blog_client_factory', new ClientFactory());
}
```

`sync()` 内の `AmaneClient::make(...)` を `$this->clientFactory->make($apiUrl, $apiToken)` に置換。
ロジック（trim、分岐、ループ）は不変。

### `Plugin` — ClientFactory + ArticleSyncer 注入

```php
private ClientFactory $clientFactory;
private ?ArticleSyncer $syncer;

public function __construct(?ClientFactory $clientFactory = null, ?ArticleSyncer $syncer = null)
{
    $this->clientFactory = $clientFactory ?? apply_filters('amane_blog_client_factory', new ClientFactory());
    $this->syncer        = $syncer;
}
```

- `runSync()`: `$this->syncer ?? new ArticleSyncer($this->clientFactory)` を使って `sync()` を呼ぶ（生成箇所を1本化）。
- `onPostPublished()`: `AmaneClient::make(...)` を `$this->clientFactory->make(...)` に置換。
- メインの `amane-blog-distribution.php` は `new Plugin()` のまま（既定生成）。振る舞い不変。

### 削除: `includes/Cron/ArticleSyncJob.php`

未接続の重複コードのため削除。参照箇所が無いことを確認した上で削除する。

## テスト3層と担当範囲

### 単体テスト（`tests/Unit/`）— PHPUnit + Brain Monkey + Mockery

WordPress 関数を Brain Monkey でモックし、HTTP を発生させない。`ClientFactory` はフェイクを注入する。

- `ArticleSyncerTest`: トークン未設定 / `list()` 例外 / 空データ / 記事 ID 空（continue）/ `get()` 例外 / `wp_insert_post` の `is_wp_error` 分岐 / `reportPublication` 失敗分岐。`WP_Query` を伴う正常系は結合テスト側で担保する。
- `PluginTest`: `register()` が想定フックを `add_action` する / `registerPostMeta` / `addAdminMenu` / `activate` `deactivate`（`wp_next_scheduled` `wp_schedule_event` `wp_unschedule_event` をモック）/ `onPostPublished` の各分岐（status 条件、meta 無し、token 無し、成功、例外）/ `runSync` がログ出力する。
- `SettingsPageTest`: `register()` が `add_settings_section` / `register_setting` / `add_settings_field` を呼ぶ。捕捉したフィールド描画クロージャと `sanitize_callback`（trim）を実行して網羅。`render()` の権限分岐。
- `ClientFactoryTest`: SDK インストール下で `make()` が `AmaneClient` を返すこと（または結合側で担保）。

`WP_Post` / `WP_Error` など型ヒントで必要なクラスは `tests/bootstrap.php` で軽量スタブを定義する。

### 結合テスト（`tests/Integration/`）— PHPUnit + WP 公式テストスイート（本物の WP/MySQL）

`bin/install-wp-tests.sh` で用意した WP テストライブラリ（`WP_UnitTestCase`）を使い、実 DB で副作用を検証する。SDK はフェイク `ClientFactory` を注入。

- `ArticleSyncIntegrationTest`: フェイクが返す記事から `wp_insert_post` で実際に投稿が作られる / `_amane_article_id` メタが付く / 同一 ID は `WP_Query` でスキップ / `auto_publish` true で `publish`・false で `draft` / カテゴリ・著者の反映。

### E2E テスト（`e2e/`）— Playwright + wp-env（Docker 上の本物の WP）

`@wordpress/env` で本物の WordPress を起動しプラグインを有効化。`tests/e2e/` の mu-plugin が `amane_blog_client_factory` フィルタでフェイク SDK を注入（外部 API 不要）。

- 管理者ログイン → 設定 → 「AMANE Blog」→ 各項目を保存 → 永続化を確認。
- 手動同期フォーム実行（または cron 相当トリガ）→ フェイク記事から投稿が生成されることを確認。

E2E は別ランタイムのため PHP カバレッジには算入しない（受け入れ確認の位置づけ）。

## カバレッジ戦略（90% ゲート）

- 計測対象は `includes/` のみ（`vendor/` 除外）。
- **単体 + 結合を1回の PHPUnit 実行にまとめ**、PCOV で単一 Clover を生成。
- `includes/` の行カバレッジが 90% 未満なら CI 失敗。判定は小スクリプト（Clover をパースして閾値比較）または既存ツールで実施。

## ローカル実行基盤 — `docker-compose.yml`

ローカルに PHP / Composer が無いため、Docker だけで単体・結合テストを実行できるようにする。

```yaml
services:
  php:                      # 単体・結合テスト実行
    build: ./docker/php     # php:8.3-cli + composer + pcov + mysqli/pdo_mysql
    working_dir: /app
    volumes: [".:/app"]
    depends_on: [mysql]
  mysql:                    # 結合テスト用 WP テスト DB
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress_test
    tmpfs: ["/var/lib/mysql"]
```

- `docker/php/Dockerfile`: `php:8.3-cli` ベースに Composer、PCOV、`mysqli` / `pdo_mysql` を導入。CI の `setup-php` と PHP バージョン・拡張をそろえる。
- 単体: `docker compose run --rm php composer test:unit`（MySQL 不要）。
- 結合: `docker compose run --rm php composer test:integration`（mysql サービス使用、`install-wp-tests.sh` 実行）。
- カバレッジ: `docker compose run --rm php composer test:cov`（90% ゲート込み）。
- E2E は wp-env（Node + Docker）で別途 `npm run env:start && npm run test:e2e`。

## `composer.json` 追加

- `require-dev`: `phpunit/phpunit`（PHP 7.4 互換のため 9.x）、`brain/monkey`、`mockery/mockery`、`yoast/phpunit-polyfills`。
- `autoload-dev`: `Amane\\WpPlugin\\Tests\\` → `tests/`。
- `scripts`: `test`, `test:unit`, `test:integration`, `test:cov`, `lint`（任意で `php -l` / phpcs）。

## CI ワークフロー — `.github/workflows/ci.yml`

| ジョブ | 環境 | 内容 |
|---|---|---|
| `unit` | matrix PHP 7.4 / 8.1 / 8.3 | `composer install` → `phpunit --testsuite unit`。後方互換・高速・DB 不要。 |
| `coverage` | PHP 8.3 + `services: mysql` | `install-wp-tests.sh` → 単体+結合を PCOV で一括実行 → `includes/` 90% ゲート。 |
| `e2e` | Node + Docker | `wp-env start` → `playwright test`。本物の WP での受け入れ。 |

- トリガ: `push` / `pull_request`（`main`）。
- 役割で基盤を使い分け: 結合/カバレッジは MySQL サービス + 公式テストスイート（PCOV 直計測が容易）、E2E は wp-env（Docker）。

## 生成・変更ファイル一覧

新規:
- `includes/Sdk/ClientFactory.php`
- `phpunit.xml.dist`
- `tests/bootstrap.php`（単体用、Brain Monkey + WP スタブ）
- `tests/bootstrap-integration.php`（結合用、WP テストスイート読み込み）
- `tests/Unit/Sync/ArticleSyncerTest.php`
- `tests/Unit/PluginTest.php`
- `tests/Unit/Admin/SettingsPageTest.php`
- `tests/Unit/Sdk/ClientFactoryTest.php`
- `tests/Integration/ArticleSyncIntegrationTest.php`
- `tests/Support/FakeClientFactory.php` ほかフェイク
- `tests/e2e/` mu-plugin（フェイク SDK 注入）
- `bin/install-wp-tests.sh`
- `bin/coverage-check.php`（Clover 閾値判定）
- `docker-compose.yml`
- `docker/php/Dockerfile`
- `.wp-env.json`
- `package.json`
- `playwright.config.ts`
- `e2e/settings.spec.ts`, `e2e/sync.spec.ts`
- `.github/workflows/ci.yml`

変更:
- `includes/Sync/ArticleSyncer.php`（ClientFactory 注入）
- `includes/Plugin.php`（ClientFactory / ArticleSyncer 注入、生成一本化）
- `composer.json`（require-dev / autoload-dev / scripts）
- `.gitignore`（`/.phpunit.cache`, `/coverage`, `playwright-report`, `test-results` など）

削除:
- `includes/Cron/ArticleSyncJob.php`

## 非対象（YAGNI）

- 既存の振る舞い変更・機能追加。
- E2E の網羅的シナリオ（主要フロー1〜2本に限定）。
- カバレッジ 100% やミューテーションテスト。
