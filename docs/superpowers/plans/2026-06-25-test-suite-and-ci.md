# テストスイート + GitHub Actions CI 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WordPress プラグイン `amane/blog-distribution-wp` に単体/結合/E2E の3層テストと GitHub Actions CI を導入し、`includes/` の行カバレッジ 90% 以上をゲートする。

**Architecture:** SDK 生成を `ClientFactory` に集約してコンストラクタ注入（振る舞い不変）でテスト可能にする。単体は Brain Monkey で WP 関数をモック、結合は WP 公式テストスイート（本物の WP/MySQL）、E2E は Playwright + wp-env。ローカルは `docker-compose.yml` で Docker のみで実行。CI は `unit`（PHP 7.4/8.1/8.3）/ `coverage`（90%ゲート）/ `e2e` の3ジョブ。

**Tech Stack:** PHP 7.4–8.3, Composer, PHPUnit 9.6, Brain Monkey, Mockery, yoast/phpunit-polyfills, phpcov, PCOV, WordPress テストスイート, @wordpress/env, Playwright, GitHub Actions, Docker / docker compose。

## Global Constraints

- PHP 互換性: `^7.4 || ^8.0`。**コンストラクタプロパティ昇格（8.0+）禁止**、従来のプロパティ宣言を使う。`declare(strict_types=1);` を全 PHP ファイル先頭に置く。
- 名前空間: 本体 `Amane\WpPlugin\`（PSR-4 → `includes/`）、テスト `Amane\WpPlugin\Tests\`（PSR-4 → `tests/`）。
- 既存の振る舞いは変更しない（リファクタは継ぎ目導入のみ）。
- SDK 注入フィルタ名: `amane_blog_client_factory`。
- カバレッジ計測対象は `includes/` のみ。閾値 **90%**（下回れば CI 失敗）。E2E は PHP カバレッジに算入しない。
- PHP マトリクス: 7.4 / 8.1 / 8.3。
- ローカルは PHP/Composer 無し → すべて `docker compose run --rm php ...` 経由で実行する。
- 依存: `require-dev` は phpunit ^9.6 / brain/monkey ^2.6 / mockery ^1.6 / yoast/phpunit-polyfills ^1.1 / phpunit/phpcov ^8.2。

---

## ファイル構成

新規（インフラ）:
- `docker-compose.yml` / `docker/php/Dockerfile` — ローカル PHP 実行基盤
- `phpunit.xml.dist`（単体）/ `phpunit-integration.xml.dist`（結合）
- `tests/bootstrap.php`（単体: Brain Monkey + WP スタブ）/ `tests/bootstrap-integration.php`（結合: WP テストスイート）
- `bin/install-wp-tests.sh` / `bin/coverage-check.php`
- `.wp-env.json` / `package.json` / `playwright.config.ts`

新規（本体）:
- `includes/Sdk/ClientFactory.php` — SDK 生成の継ぎ目

新規（テスト）:
- `tests/Support/FakeClientFactory.php` — 結合/E2E 用フェイク SDK
- `tests/Unit/Sdk/ClientFactoryTest.php`
- `tests/Unit/Sync/ArticleSyncerTest.php`
- `tests/Unit/PluginTest.php`
- `tests/Unit/Admin/SettingsPageTest.php`
- `tests/Integration/ArticleSyncIntegrationTest.php`
- `tests/e2e-mu-plugin/amane-e2e-fake.php` — E2E 用フェイク注入 mu-plugin
- `e2e/settings.spec.ts` / `e2e/sync.spec.ts`

変更:
- `includes/Sync/ArticleSyncer.php`（ClientFactory 注入）
- `includes/Plugin.php`（ClientFactory/ArticleSyncer 注入・生成一本化）
- `composer.json`（require-dev / autoload-dev / scripts）
- `.gitignore`

削除:
- `includes/Cron/ArticleSyncJob.php`

---

## Task 1: テスト実行基盤（Docker + Composer + PHPUnit スモーク）

**Files:**
- Create: `docker/php/Dockerfile`, `docker-compose.yml`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Unit/SmokeTest.php`
- Modify: `composer.json`, `.gitignore`

**Interfaces:**
- Produces: `docker compose run --rm php composer test:unit` が PHPUnit を起動し緑になる土台。autoload-dev 名前空間 `Amane\WpPlugin\Tests\` → `tests/`。

- [ ] **Step 1: `docker/php/Dockerfile` を作成**

```dockerfile
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip subversion libzip-dev default-mysql-client \
    && docker-php-ext-install mysqli pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
```

- [ ] **Step 2: `docker-compose.yml` を作成**

```yaml
services:
  php:
    build: ./docker/php
    working_dir: /app
    volumes:
      - .:/app
    environment:
      WP_TESTS_DB_HOST: mysql
      WP_TESTS_DB_NAME: wordpress_test
      WP_TESTS_DB_USER: root
      WP_TESTS_DB_PASS: root
    depends_on:
      - mysql
  mysql:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress_test
    tmpfs:
      - /var/lib/mysql
```

- [ ] **Step 3: `composer.json` に require-dev / autoload-dev / scripts を追加**

`composer.json` を以下に置き換える:

```json
{
    "name": "amane/blog-distribution-wp",
    "require": {
        "php": "^7.4 || ^8.0",
        "amane/blog-sdk": "^0.1.2"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "brain/monkey": "^2.6",
        "mockery/mockery": "^1.6",
        "yoast/phpunit-polyfills": "^1.1",
        "phpunit/phpcov": "^8.2"
    },
    "autoload": {
        "psr-4": {
            "Amane\\WpPlugin\\": "includes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Amane\\WpPlugin\\Tests\\": "tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        },
        "sort-packages": true
    },
    "scripts": {
        "test": [
            "@test:unit"
        ],
        "test:unit": "phpunit -c phpunit.xml.dist",
        "test:integration": "phpunit -c phpunit-integration.xml.dist",
        "test:cov": [
            "@php -r \"is_dir('build/cov') || mkdir('build/cov', 0777, true);\"",
            "phpunit -c phpunit.xml.dist --coverage-php build/cov/unit.cov",
            "phpunit -c phpunit-integration.xml.dist --coverage-php build/cov/integration.cov",
            "phpcov merge --clover build/clover.xml build/cov",
            "php bin/coverage-check.php build/clover.xml 90"
        ]
    }
}
```

- [ ] **Step 4: `phpunit.xml.dist`（単体用）を作成**

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheResultFile=".phpunit.cache/test-results"
         failOnWarning="true"
         failOnRisky="true"
         beStrictAboutOutputDuringTests="false">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

- [ ] **Step 5: `tests/bootstrap.php`（単体用）を作成**

```php
<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (! file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php がありません。`composer install` を実行してください。\n");
    exit(1);
}
require $autoload;

// 単体テストで型ヒント / new に必要な最小 WP クラススタブ
if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public function __construct(int $id = 0)
        {
            $this->ID = $id;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private string $message;

        public function __construct($code = '', string $message = '')
        {
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

// have_posts() の戻り値をテストから制御できる WP_Query スタブ。
// 各 new WP_Query() は $haveResults キューから1件 shift して使う。
if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var bool[] */
        public static array $haveResults = [];

        private bool $have;

        public function __construct($args = [])
        {
            $this->have = (bool) (array_shift(self::$haveResults) ?? false);
        }

        public function have_posts(): bool
        {
            return $this->have;
        }
    }
}
```

- [ ] **Step 6: `.gitignore` に成果物を追記**

`.gitignore` の末尾に追記:

```
/build/
/.phpunit.cache/
/coverage/
/playwright-report/
/test-results/
/.wp-env-home/
```

- [ ] **Step 7: スモークテスト `tests/Unit/SmokeTest.php` を作成**

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoloader_and_phpunit_work(): void
    {
        self::assertTrue(class_exists(\Amane\WpPlugin\Plugin::class));
    }
}
```

- [ ] **Step 8: Docker イメージをビルドし composer install**

Run:
```bash
docker compose build php
docker compose run --rm php composer install
```
Expected: 依存が解決し `vendor/` が生成される。

- [ ] **Step 9: スモークテストを実行**

Run: `docker compose run --rm php composer test:unit`
Expected: PASS（`SmokeTest` 1 件成功）。

- [ ] **Step 10: コミット**

```bash
git add docker docker-compose.yml composer.json composer.lock phpunit.xml.dist tests/bootstrap.php tests/Unit/SmokeTest.php .gitignore
git commit -m "chore: テスト実行基盤(Docker/Composer/PHPUnit)を追加"
```

---

## Task 2: ClientFactory 継ぎ目 + ArticleSyncer リファクタと単体テスト

**Files:**
- Create: `includes/Sdk/ClientFactory.php`, `tests/Unit/Sdk/ClientFactoryTest.php`, `tests/Unit/Sync/ArticleSyncerTest.php`
- Modify: `includes/Sync/ArticleSyncer.php`

**Interfaces:**
- Consumes: SDK `Amane\BlogSdk\AmaneClient::make(string $url, string $token)`、`$client->articles()->list(array)`, `->get(string)`, `->reportPublication(string, string)`。
- Produces: `Amane\WpPlugin\Sdk\ClientFactory::make(string $url, string $token): object`。`ArticleSyncer::__construct(?ClientFactory $clientFactory = null)`。`ArticleSyncer::sync(): SyncResult`（`SyncResult` は `int $created`, `int $skipped`, `array $errors`）。

- [ ] **Step 1: ArticleSyncer の失敗する単体テストを作成**

`tests/Unit/Sync/ArticleSyncerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Sync;

use Amane\WpPlugin\Sdk\ClientFactory;
use Amane\WpPlugin\Sync\ArticleSyncer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ArticleSyncerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WP_Query::$haveResults = [];

        // 既定オプション
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            $map = [
                'amane_api_url'      => 'https://service.amane.app',
                'amane_api_token'    => 'amb_token',
                'amane_auto_publish' => false,
                'amane_post_category' => 0,
                'amane_post_author'  => 1,
            ];
            return $map[$key] ?? $default;
        });
        Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('add_post_meta')->justReturn(true);
        Functions\when('get_permalink')->justReturn('https://example.com/p/1');
        Functions\when('wp_insert_post')->justReturn(123);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /** ClientFactory::make が返すクライアントをモックして注入するヘルパ */
    private function factoryReturning($articlesResource): ClientFactory
    {
        $client = Mockery::mock();
        $client->shouldReceive('articles')->andReturn($articlesResource);

        $factory = Mockery::mock(ClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);

        return $factory;
    }

    public function test_returns_error_when_token_missing(): void
    {
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            return $key === 'amane_api_token' ? '' : $default;
        });

        $syncer = new ArticleSyncer(Mockery::mock(ClientFactory::class));
        $result = $syncer->sync();

        self::assertSame(0, $result->created);
        self::assertContains('API token is not configured.', $result->errors);
    }

    public function test_records_error_when_list_throws(): void
    {
        $articles = Mockery::mock();
        $articles->shouldReceive('list')->andThrow(new \RuntimeException('boom'));

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(0, $result->created);
        self::assertStringContainsString('Failed to fetch articles: boom', $result->errors[0]);
    }

    public function test_creates_draft_post_for_new_article(): void
    {
        $articles = Mockery::mock();
        $articles->shouldReceive('list')->andReturn((object) ['data' => [
            (object) ['id' => 'a1', 'title' => 'Hello'],
        ]]);
        $articles->shouldReceive('get')->with('a1')->andReturn((object) ['body_html' => '<p>Body</p>']);

        \WP_Query::$haveResults = [false]; // 既存なし

        Functions\expect('wp_insert_post')->once()->andReturn(123);

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(1, $result->created);
        self::assertSame(0, $result->skipped);
        self::assertSame([], $result->errors);
    }

    public function test_skips_existing_article(): void
    {
        $articles = Mockery::mock();
        $articles->shouldReceive('list')->andReturn((object) ['data' => [
            (object) ['id' => 'a1', 'title' => 'Hello'],
        ]]);

        \WP_Query::$haveResults = [true]; // 既存あり

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->skipped);
    }

    public function test_records_error_when_get_throws(): void
    {
        $articles = Mockery::mock();
        $articles->shouldReceive('list')->andReturn((object) ['data' => [
            (object) ['id' => 'a1', 'title' => 'Hello'],
        ]]);
        $articles->shouldReceive('get')->with('a1')->andThrow(new \RuntimeException('nope'));

        \WP_Query::$haveResults = [false];

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(0, $result->created);
        self::assertStringContainsString('Failed to fetch article a1: nope', $result->errors[0]);
    }

    public function test_records_error_when_insert_returns_wp_error(): void
    {
        $articles = Mockery::mock();
        $articles->shouldReceive('list')->andReturn((object) ['data' => [
            (object) ['id' => 'a1', 'title' => 'Hello'],
        ]]);
        $articles->shouldReceive('get')->with('a1')->andReturn((object) ['body_html' => '<p>x</p>']);

        \WP_Query::$haveResults = [false];
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('err', 'insert failed'));

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(0, $result->created);
        self::assertStringContainsString('Failed to insert post for article a1: insert failed', $result->errors[0]);
    }

    public function test_reports_publication_when_auto_publish_enabled(): void
    {
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            $map = [
                'amane_api_url'      => 'https://service.amane.app',
                'amane_api_token'    => 'amb_token',
                'amane_auto_publish' => true,
                'amane_post_category' => 0,
                'amane_post_author'  => 1,
            ];
            return $map[$key] ?? $default;
        });

        $articles = Mockery::mock();
        $articles->shouldReceive('list')->andReturn((object) ['data' => [
            (object) ['id' => 'a1', 'title' => 'Hello'],
        ]]);
        $articles->shouldReceive('get')->with('a1')->andReturn((object) ['body_html' => '<p>x</p>']);
        $articles->shouldReceive('reportPublication')->once()->with('a1', 'https://example.com/p/1');

        \WP_Query::$haveResults = [false];

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(1, $result->created);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `docker compose run --rm php composer test:unit`
Expected: FAIL（`Amane\WpPlugin\Sdk\ClientFactory` が存在しない / `ArticleSyncer::__construct` が引数を取らない）。

- [ ] **Step 3: `includes/Sdk/ClientFactory.php` を作成**

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Sdk;

use Amane\BlogSdk\AmaneClient;

class ClientFactory
{
    /**
     * AMANE SDK クライアントを生成する。
     * テスト/E2E では本クラスを差し替えてフェイクを返す。
     */
    public function make(string $url, string $token): object
    {
        return AmaneClient::make($url, $token);
    }
}
```

- [ ] **Step 4: `includes/Sync/ArticleSyncer.php` を ClientFactory 注入に改修**

ファイル全体を以下に置き換える（ロジックは不変、生成箇所のみ差し替え）:

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Sync;

use Amane\WpPlugin\Sdk\ClientFactory;

class SyncResult
{
    public int   $created = 0;
    public int   $skipped = 0;
    public array $errors  = [];
}

class ArticleSyncer
{
    private ClientFactory $clientFactory;

    public function __construct(?ClientFactory $clientFactory = null)
    {
        // 未指定時はフィルタ経由で既定生成（E2E では mu-plugin で差し替え可能）
        $this->clientFactory = $clientFactory
            ?? apply_filters('amane_blog_client_factory', new ClientFactory());
    }

    public function sync(): SyncResult
    {
        $result = new SyncResult();

        // trim() で前後空白・改行 (CRLF) を除去 (= 旧プラグイン版で
        // 保存された option に \r\n が混入している場合の防御)
        $apiUrl      = trim((string) get_option('amane_api_url', 'https://service.amane.app'));
        $apiToken    = trim((string) get_option('amane_api_token', ''));
        $autoPublish = (bool)   get_option('amane_auto_publish', false);
        $category    = (int)    get_option('amane_post_category', 0);
        $author      = (int)    get_option('amane_post_author', 1);

        if (! $apiToken) {
            $result->errors[] = 'API token is not configured.';
            return $result;
        }

        try {
            $client   = $this->clientFactory->make($apiUrl, $apiToken);
            $response = $client->articles()->list(['status' => 'completed']);
        } catch (\Throwable $e) {
            $result->errors[] = 'Failed to fetch articles: ' . $e->getMessage();
            return $result;
        }

        $articles = (array) ($response->data ?? []);

        foreach ($articles as $article) {
            $articleId = (string) ($article->id ?? '');

            if (! $articleId) {
                continue;
            }

            $existing = new \WP_Query([
                'post_type'  => 'post',
                'meta_query' => [
                    [
                        'key'   => '_amane_article_id',
                        'value' => $articleId,
                    ],
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);

            if ($existing->have_posts()) {
                $result->skipped++;
                continue;
            }

            try {
                $content = $client->articles()->get($articleId);
            } catch (\Throwable $e) {
                $result->errors[] = "Failed to fetch article {$articleId}: " . $e->getMessage();
                continue;
            }

            $postData = [
                'post_title'    => (string) ($article->title ?? $article->target_keyword ?? ''),
                'post_content'  => (string) ($content->body_html ?? ''),
                'post_status'   => $autoPublish ? 'publish' : 'draft',
                'post_author'   => $author,
                'post_category' => $category > 0 ? [$category] : [],
            ];

            $postId = wp_insert_post($postData, true);

            if (is_wp_error($postId)) {
                $result->errors[] = "Failed to insert post for article {$articleId}: " . $postId->get_error_message();
                continue;
            }

            add_post_meta($postId, '_amane_article_id', $articleId, true);

            if ($autoPublish) {
                try {
                    $permalink = get_permalink($postId);
                    if ($permalink) {
                        $client->articles()->reportPublication($articleId, $permalink);
                    }
                } catch (\Throwable $e) {
                    $result->errors[] = "reportPublication failed for article {$articleId}: " . $e->getMessage();
                }
            }

            $result->created++;
        }

        return $result;
    }
}
```

- [ ] **Step 5: ClientFactory の単体テストを作成**

`tests/Unit/Sdk/ClientFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Sdk;

use Amane\WpPlugin\Sdk\ClientFactory;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    public function test_make_returns_sdk_client_object(): void
    {
        $client = (new ClientFactory())->make('https://service.amane.app', 'amb_dummy_token');

        self::assertIsObject($client);
        self::assertTrue(method_exists($client, 'articles'));
    }
}
```

- [ ] **Step 6: 単体テストを実行して成功を確認**

Run: `docker compose run --rm php composer test:unit`
Expected: PASS（`ArticleSyncerTest` 7 件 + `ClientFactoryTest` 1 件 + `SmokeTest`）。

- [ ] **Step 7: コミット**

```bash
git add includes/Sdk/ClientFactory.php includes/Sync/ArticleSyncer.php tests/Unit/Sdk tests/Unit/Sync
git commit -m "refactor: ArticleSyncer に ClientFactory 継ぎ目を導入し単体テスト追加"
```

---

## Task 3: Plugin リファクタ + ArticleSyncJob 削除と単体テスト

**Files:**
- Create: `tests/Unit/PluginTest.php`
- Modify: `includes/Plugin.php`
- Delete: `includes/Cron/ArticleSyncJob.php`

**Interfaces:**
- Consumes: `ClientFactory`（Task 2）, `ArticleSyncer`（Task 2）。
- Produces: `Plugin::__construct(?ClientFactory $clientFactory = null, ?ArticleSyncer $syncer = null)`。メソッド: `register()`, `registerPostMeta()`, `addAdminMenu()`, `runSync()`, `onPostPublished(string,string,\WP_Post)`, 静的 `activate()`, `deactivate()`。

- [ ] **Step 1: 失敗する PluginTest を作成**

`tests/Unit/PluginTest.php`:

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit;

use Amane\WpPlugin\Plugin;
use Amane\WpPlugin\Sdk\ClientFactory;
use Amane\WpPlugin\Sync\ArticleSyncer;
use Amane\WpPlugin\Sync\SyncResult;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_register_wires_expected_hooks(): void
    {
        Functions\expect('add_action')->once()->with('init', Mockery::type('array'));
        Functions\expect('add_action')->once()->with('admin_menu', Mockery::type('array'));
        Functions\expect('add_action')->once()->with('amane_sync_articles', Mockery::type('array'));
        Functions\expect('add_action')->once()->with('transition_post_status', Mockery::type('array'), 10, 3);

        (new Plugin())->register();
    }

    public function test_register_post_meta(): void
    {
        Functions\expect('register_post_meta')->once()->with('post', '_amane_article_id', Mockery::type('array'));

        (new Plugin())->registerPostMeta();
    }

    public function test_add_admin_menu(): void
    {
        Functions\expect('add_submenu_page')->once();

        (new Plugin())->addAdminMenu();
    }

    public function test_run_sync_uses_injected_syncer_and_logs(): void
    {
        $result = new SyncResult();
        $result->created = 2;
        $result->skipped = 1;
        $result->errors  = ['oops'];

        $syncer = Mockery::mock(ArticleSyncer::class);
        $syncer->shouldReceive('sync')->once()->andReturn($result);

        Functions\expect('error_log')->twice(); // サマリ1 + エラー1

        (new Plugin(Mockery::mock(ClientFactory::class), $syncer))->runSync();
    }

    public function test_on_post_published_ignores_non_publish_transition(): void
    {
        // get_post_meta が呼ばれなければ早期 return できている
        Functions\expect('get_post_meta')->never();

        (new Plugin())->onPostPublished('draft', 'draft', new \WP_Post(1));
    }

    public function test_on_post_published_returns_when_no_article_id(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('get_option')->never();

        (new Plugin())->onPostPublished('publish', 'draft', new \WP_Post(1));
    }

    public function test_on_post_published_returns_when_no_token(): void
    {
        Functions\when('get_post_meta')->justReturn('a1');
        Functions\when('get_option')->justReturn('');

        $factory = Mockery::mock(ClientFactory::class);
        $factory->shouldReceive('make')->never();

        (new Plugin($factory))->onPostPublished('publish', 'draft', new \WP_Post(1));
        $this->addToAssertionCount(1);
    }

    public function test_on_post_published_reports_publication(): void
    {
        Functions\when('get_post_meta')->justReturn('a1');
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            $map = ['amane_api_url' => 'https://service.amane.app', 'amane_api_token' => 'amb_token'];
            return $map[$key] ?? $default;
        });
        Functions\when('get_permalink')->justReturn('https://example.com/p/1');

        $articles = Mockery::mock();
        $articles->shouldReceive('reportPublication')->once()->with('a1', 'https://example.com/p/1');
        $client = Mockery::mock();
        $client->shouldReceive('articles')->andReturn($articles);
        $factory = Mockery::mock(ClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);

        (new Plugin($factory))->onPostPublished('publish', 'draft', new \WP_Post(1));
    }

    public function test_on_post_published_swallows_exception(): void
    {
        Functions\when('get_post_meta')->justReturn('a1');
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            $map = ['amane_api_url' => 'https://service.amane.app', 'amane_api_token' => 'amb_token'];
            return $map[$key] ?? $default;
        });
        Functions\when('get_permalink')->justReturn('https://example.com/p/1');
        Functions\expect('error_log')->once();

        $factory = Mockery::mock(ClientFactory::class);
        $factory->shouldReceive('make')->andThrow(new \RuntimeException('down'));

        (new Plugin($factory))->onPostPublished('publish', 'draft', new \WP_Post(1));
    }

    public function test_activate_schedules_event_when_not_scheduled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('time')->justReturn(1000);
        Functions\expect('wp_schedule_event')->once()->with(1000, 'hourly', 'amane_sync_articles');

        Plugin::activate();
    }

    public function test_deactivate_unschedules_event(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(5000);
        Functions\expect('wp_unschedule_event')->once()->with(5000, 'amane_sync_articles');

        Plugin::deactivate();
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `docker compose run --rm php composer test:unit`
Expected: FAIL（`Plugin::__construct` が引数を取らない / `AmaneClient` 直接生成で make モックが効かない）。

- [ ] **Step 3: `includes/Plugin.php` を改修**

ファイル全体を以下に置き換える:

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin;

use Amane\WpPlugin\Admin\SettingsPage;
use Amane\WpPlugin\Sdk\ClientFactory;
use Amane\WpPlugin\Sync\ArticleSyncer;

class Plugin
{
    private const CRON_HOOK = 'amane_sync_articles';

    private ClientFactory $clientFactory;
    private ?ArticleSyncer $syncer;

    public function __construct(?ClientFactory $clientFactory = null, ?ArticleSyncer $syncer = null)
    {
        $this->clientFactory = $clientFactory
            ?? apply_filters('amane_blog_client_factory', new ClientFactory());
        $this->syncer = $syncer;
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerPostMeta']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action(self::CRON_HOOK, [$this, 'runSync']);
        add_action('transition_post_status', [$this, 'onPostPublished'], 10, 3);
    }

    public function registerPostMeta(): void
    {
        register_post_meta('post', '_amane_article_id', [
            'show_in_rest' => false,
            'single'       => true,
            'type'         => 'string',
        ]);
    }

    public function addAdminMenu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('AMANE Blog Distribution', 'amane-blog-dist'),
            __('AMANE Blog', 'amane-blog-dist'),
            'manage_options',
            'amane-blog-dist',
            function (): void {
                (new SettingsPage())->render();
            },
        );
    }

    public function runSync(): void
    {
        $syncer = $this->syncer ?? new ArticleSyncer($this->clientFactory);
        $result = $syncer->sync();

        error_log(sprintf(
            'AMANE sync: created=%d skipped=%d errors=%d',
            $result->created,
            $result->skipped,
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            error_log('AMANE sync error: ' . $error);
        }
    }

    public function onPostPublished(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus !== 'publish' || $oldStatus === 'publish') {
            return;
        }

        $articleId = get_post_meta($post->ID, '_amane_article_id', true);
        if (! $articleId) {
            return;
        }

        // trim() で前後空白・改行 (CRLF) を除去 (= 旧プラグイン版で
        // 保存された option に \r\n が混入している場合の防御)
        $apiUrl   = trim((string) get_option('amane_api_url', 'https://service.amane.app'));
        $apiToken = trim((string) get_option('amane_api_token', ''));

        if (! $apiToken) {
            return;
        }

        try {
            $client = $this->clientFactory->make($apiUrl, $apiToken);
            $client->articles()->reportPublication($articleId, get_permalink($post->ID) ?: '');
        } catch (\Throwable $e) {
            error_log('AMANE reportPublication failed: ' . $e->getMessage());
        }
    }

    public static function activate(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}
```

- [ ] **Step 4: 重複デッドコードを削除**

Run: `git rm includes/Cron/ArticleSyncJob.php`
（`includes/Cron/` が空になる。`grep -rn ArticleSyncJob includes` で参照ゼロを確認すること。）

- [ ] **Step 5: 単体テストを実行して成功を確認**

Run: `docker compose run --rm php composer test:unit`
Expected: PASS（`PluginTest` 12 件含め全緑）。

- [ ] **Step 6: コミット**

```bash
git add includes/Plugin.php tests/Unit/PluginTest.php
git rm includes/Cron/ArticleSyncJob.php
git commit -m "refactor: Plugin に DI 継ぎ目を導入・ArticleSyncJob 削除・単体テスト追加"
```

---

## Task 4: SettingsPage 単体テスト

**Files:**
- Create: `tests/Unit/Admin/SettingsPageTest.php`
- Modify: なし（`SettingsPage` は変更不要）

**Interfaces:**
- Consumes: `Amane\WpPlugin\Admin\SettingsPage::register()`, `::render()`。WP 関数: `add_settings_section`, `register_setting`, `add_settings_field`, `current_user_can`, `settings_fields`, `do_settings_sections`, `submit_button`, `admin_url`, `esc_url`, `wp_nonce_field`, `esc_attr`, `esc_html__`, `checked`, `get_option`。

- [ ] **Step 1: 失敗する SettingsPageTest を作成**

`tests/Unit/Admin/SettingsPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Admin;

use Amane\WpPlugin\Admin\SettingsPage;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    /** @var array<string, callable> */
    private array $fieldCallbacks = [];
    /** @var array<string, callable> */
    private array $sanitizeCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->fieldCallbacks = [];
        $this->sanitizeCallbacks = [];

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('add_settings_section')->justReturn(null);
        Functions\when('checked')->justReturn("checked='checked'");

        Functions\when('register_setting')->alias(function ($group, $name, $args = []): void {
            if (isset($args['sanitize_callback'])) {
                $this->sanitizeCallbacks[$name] = $args['sanitize_callback'];
            }
        });
        Functions\when('add_settings_field')->alias(function ($id, $title, $cb): void {
            $this->fieldCallbacks[$id] = $cb;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_declares_sections_and_fields(): void
    {
        Functions\when('get_option')->returnArg(2);

        (new SettingsPage())->register();

        self::assertArrayHasKey('amane_api_url', $this->fieldCallbacks);
        self::assertArrayHasKey('amane_api_token', $this->fieldCallbacks);
        self::assertArrayHasKey('amane_auto_publish', $this->fieldCallbacks);
        self::assertArrayHasKey('amane_post_category', $this->fieldCallbacks);
        self::assertArrayHasKey('amane_post_author', $this->fieldCallbacks);
    }

    public function test_field_renderers_emit_inputs(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => $default);

        (new SettingsPage())->register();

        foreach ($this->fieldCallbacks as $cb) {
            ob_start();
            $cb();
            $html = ob_get_clean();
            self::assertStringContainsString('<input', $html);
        }
    }

    public function test_sanitize_callbacks_trim_whitespace(): void
    {
        Functions\when('get_option')->returnArg(2);

        (new SettingsPage())->register();

        self::assertSame('value', ($this->sanitizeCallbacks['amane_api_url'])("  value\r\n"));
        self::assertSame('amb_t', ($this->sanitizeCallbacks['amane_api_token'])(" amb_t \n"));
    }

    public function test_render_returns_early_without_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        (new SettingsPage())->render();
        $html = ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_render_outputs_form_when_capable(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(static fn ($key, $default = false) => $default);
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-post.php');
        Functions\when('wp_nonce_field')->justReturn(null);

        ob_start();
        (new SettingsPage())->render();
        $html = ob_get_clean();

        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('amane_manual_sync', $html);
    }
}
```

- [ ] **Step 2: テストを実行**

Run: `docker compose run --rm php composer test:unit`
Expected: PASS（`SettingsPageTest` 5 件）。`SettingsPage` は変更不要。落ちる場合は WP 関数モックの過不足を調整。

- [ ] **Step 3: コミット**

```bash
git add tests/Unit/Admin/SettingsPageTest.php
git commit -m "test: SettingsPage の単体テストを追加"
```

---

## Task 5: 結合テスト（WP 公式テストスイート + MySQL）

**Files:**
- Create: `phpunit-integration.xml.dist`, `tests/bootstrap-integration.php`, `bin/install-wp-tests.sh`, `tests/Support/FakeClientFactory.php`, `tests/Integration/ArticleSyncIntegrationTest.php`

**Interfaces:**
- Consumes: `ArticleSyncer`, `ClientFactory`, WP テストスイート `WP_UnitTestCase`。
- Produces: `Amane\WpPlugin\Tests\Support\FakeClientFactory`（`__construct(array $list, array $contents)`、`make()` がフェイククライアントを返す）。

- [ ] **Step 1: フェイク SDK `tests/Support/FakeClientFactory.php` を作成**

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Support;

use Amane\WpPlugin\Sdk\ClientFactory;

final class FakeArticlesResource
{
    /** @var object[] */
    private array $list;
    /** @var array<string, object> */
    private array $contents;
    /** @var array<int, array{0:string,1:string}> */
    public array $reported = [];

    public function __construct(array $list, array $contents)
    {
        $this->list = $list;
        $this->contents = $contents;
    }

    public function list(array $params = []): object
    {
        return (object) ['data' => $this->list];
    }

    public function get(string $id): object
    {
        return $this->contents[$id] ?? (object) ['body_html' => ''];
    }

    public function reportPublication(string $id, string $url): void
    {
        $this->reported[] = [$id, $url];
    }
}

final class FakeClient
{
    private FakeArticlesResource $articles;

    public function __construct(FakeArticlesResource $articles)
    {
        $this->articles = $articles;
    }

    public function articles(): FakeArticlesResource
    {
        return $this->articles;
    }
}

final class FakeClientFactory extends ClientFactory
{
    private FakeArticlesResource $articles;

    /**
     * @param object[]               $list     list() が返す記事メタ（stdClass: id,title,...）
     * @param array<string, object>  $contents get() が返す本文（id => stdClass: body_html）
     */
    public function __construct(array $list = [], array $contents = [])
    {
        $this->articles = new FakeArticlesResource($list, $contents);
    }

    public function make(string $url, string $token): object
    {
        return new FakeClient($this->articles);
    }
}
```

- [ ] **Step 2: `bin/install-wp-tests.sh` を作成**

WP-CLI scaffold 標準スクリプト（svn 使用、Dockerfile に subversion 同梱済み）:

```bash
#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
    if command -v curl >/dev/null; then
        curl -s "$1" >"$2"
    else
        wget -nv -O "$2" "$1"
    fi
}

if [[ "$WP_VERSION" == "latest" ]]; then
    download http://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
    WP_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | sed 's/"version":"//' | head -1)
fi
WP_TESTS_TAG="tags/$WP_VERSION"

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then return; fi
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$TMPDIR/wordpress.tar.gz"
    tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
    download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
    if [ ! -d "$WP_TESTS_DIR" ]; then
        mkdir -p "$WP_TESTS_DIR"
        svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
        svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    fi

    if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
        local cfg="$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$cfg"
        sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$cfg"
        sed -i "s/yourusernamehere/$DB_USER/" "$cfg"
        sed -i "s/yourpasswordhere/$DB_PASS/" "$cfg"
        sed -i "s|localhost|${DB_HOST}|" "$cfg"
    fi
}

install_wp
install_test_suite
echo "WP test suite installed: WP_TESTS_DIR=$WP_TESTS_DIR WP_CORE_DIR=$WP_CORE_DIR"
```

実行ビットを付与: `git update-index --chmod=+x bin/install-wp-tests.sh`（または `chmod +x`）。

- [ ] **Step 3: `tests/bootstrap-integration.php` を作成**

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$testsDir = getenv('WP_TESTS_DIR') ?: (sys_get_temp_dir() . '/wordpress-tests-lib');

require $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/amane-blog-distribution.php';
});

require $testsDir . '/includes/bootstrap.php';
```

- [ ] **Step 4: `phpunit-integration.xml.dist` を作成**

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="tests/bootstrap-integration.php"
         colors="true"
         cacheResultFile=".phpunit.cache/integration-results">
    <testsuites>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

注意: 本体プラグイン `amane-blog-distribution.php` は `require __DIR__ . '/vendor/autoload.php'` を行う。結合 bootstrap では composer autoload を先に読むため二重 require になるが、`require_once` 相当で問題ないことを確認する（必要なら本体側を `require_once` のままに保つ）。

- [ ] **Step 5: 結合テスト `tests/Integration/ArticleSyncIntegrationTest.php` を作成**

```php
<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Integration;

use Amane\WpPlugin\Sync\ArticleSyncer;
use Amane\WpPlugin\Tests\Support\FakeClientFactory;
use WP_UnitTestCase;

final class ArticleSyncIntegrationTest extends WP_UnitTestCase
{
    public function test_creates_draft_post_with_meta(): void
    {
        update_option('amane_api_token', 'amb_token');
        update_option('amane_auto_publish', false);

        $factory = new FakeClientFactory(
            [(object) ['id' => 'a1', 'title' => 'Integration Title']],
            ['a1' => (object) ['body_html' => '<p>Integration body</p>']],
        );

        $result = (new ArticleSyncer($factory))->sync();

        self::assertSame(1, $result->created);
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'numberposts' => -1]);
        self::assertCount(1, $posts);
        self::assertSame('Integration Title', $posts[0]->post_title);
        self::assertSame('a1', get_post_meta($posts[0]->ID, '_amane_article_id', true));
    }

    public function test_skips_already_imported_article(): void
    {
        update_option('amane_api_token', 'amb_token');

        $postId = self::factory()->post->create(['post_status' => 'draft']);
        add_post_meta($postId, '_amane_article_id', 'a1', true);

        $factory = new FakeClientFactory(
            [(object) ['id' => 'a1', 'title' => 'Dup']],
            ['a1' => (object) ['body_html' => '<p>x</p>']],
        );

        $result = (new ArticleSyncer($factory))->sync();

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->skipped);
    }

    public function test_publishes_and_reports_when_auto_publish_enabled(): void
    {
        update_option('amane_api_token', 'amb_token');
        update_option('amane_auto_publish', true);

        $factory = new FakeClientFactory(
            [(object) ['id' => 'a2', 'title' => 'Auto']],
            ['a2' => (object) ['body_html' => '<p>auto</p>']],
        );

        $result = (new ArticleSyncer($factory))->sync();

        self::assertSame(1, $result->created);
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1]);
        self::assertCount(1, $posts);
    }
}
```

- [ ] **Step 6: MySQL を起動し WP テストスイートを用意して結合テスト実行**

Run:
```bash
docker compose up -d mysql
docker compose run --rm php bash -lc "bin/install-wp-tests.sh wordpress_test root root mysql latest && composer test:integration"
```
Expected: PASS（結合 3 件）。失敗時は DB 接続情報（host=mysql）と `WP_TESTS_DIR` を確認。

- [ ] **Step 7: コミット**

```bash
git add phpunit-integration.xml.dist tests/bootstrap-integration.php bin/install-wp-tests.sh tests/Support tests/Integration
git commit -m "test: WP テストスイートによる結合テストを追加"
```

---

## Task 6: カバレッジ合算 + 90% ゲート

**Files:**
- Create: `bin/coverage-check.php`
- Modify: なし（`composer.json` の `test:cov` は Task 1 で定義済み）

**Interfaces:**
- Consumes: `build/cov/unit.cov`, `build/cov/integration.cov`（PHPUnit `--coverage-php` 出力）→ `phpcov merge` で `build/clover.xml`。
- Produces: `php bin/coverage-check.php <clover.xml> <min%>` が閾値未満で exit 1。

- [ ] **Step 1: `bin/coverage-check.php` を作成**

```php
<?php

declare(strict_types=1);

// Usage: php bin/coverage-check.php build/clover.xml 90
$file = $argv[1] ?? 'build/clover.xml';
$min  = (float) ($argv[2] ?? '90');

if (! is_file($file)) {
    fwrite(STDERR, "clover が見つかりません: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "clover の metrics を解析できません: {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$covered = (int) $metrics['coveredstatements'];
$total   = (int) $metrics['statements'];
$pct     = $total > 0 ? ($covered / $total) * 100 : 0.0;

printf("Coverage: %.2f%% (%d/%d statements)\n", $pct, $covered, $total);

if ($pct + 1e-9 < $min) {
    fwrite(STDERR, sprintf("FAIL: カバレッジ %.2f%% < 閾値 %.1f%%\n", $pct, $min));
    exit(1);
}

echo "PASS\n";
```

- [ ] **Step 2: カバレッジ合算を実行（MySQL 起動済み + WP テストスイート用意済みが前提）**

Run:
```bash
docker compose up -d mysql
docker compose run --rm php bash -lc "bin/install-wp-tests.sh wordpress_test root root mysql latest && composer test:cov"
```
Expected: 最終行 `PASS`、表示カバレッジが 90% 以上。下回る場合は不足分のテストを Task 2–4 のパターンで追加してから再実行。

- [ ] **Step 3: コミット**

```bash
git add bin/coverage-check.php
git commit -m "test: 単体+結合の合算カバレッジ90%ゲートを追加"
```

---

## Task 7: E2E（Playwright + wp-env）

**Files:**
- Create: `.wp-env.json`, `package.json`, `playwright.config.ts`, `tests/e2e-mu-plugin/amane-e2e-fake.php`, `e2e/settings.spec.ts`, `e2e/sync.spec.ts`

**Interfaces:**
- Consumes: 本体プラグイン（wp-env でマウント・有効化）、フィルタ `amane_blog_client_factory`。
- Produces: `npm run env:start` で WP 起動、`npm run test:e2e` で Playwright 実行。

- [ ] **Step 1: `.wp-env.json` を作成**

```json
{
  "core": "WordPress/WordPress#master",
  "plugins": ["."],
  "mappings": {
    "wp-content/mu-plugins/amane-e2e-fake.php": "./tests/e2e-mu-plugin/amane-e2e-fake.php"
  },
  "config": {
    "WP_DEBUG": true
  }
}
```

- [ ] **Step 2: E2E 用フェイク mu-plugin を作成**

`tests/e2e-mu-plugin/amane-e2e-fake.php`:

```php
<?php

/**
 * Plugin Name: AMANE E2E Fake SDK
 * Description: E2E テスト時に SDK をフェイクへ差し替える（外部 API 不要）。
 */

declare(strict_types=1);

add_filter('amane_blog_client_factory', static function () {
    return new class {
        public function make(string $url, string $token): object
        {
            return new class {
                public function articles(): object
                {
                    return new class {
                        public function list(array $params = []): object
                        {
                            return (object) ['data' => [
                                (object) ['id' => 'e2e-1', 'title' => 'E2E Sample Article'],
                            ]];
                        }

                        public function get(string $id): object
                        {
                            return (object) ['body_html' => '<p>E2E body for ' . $id . '</p>'];
                        }

                        public function reportPublication(string $id, string $url): void
                        {
                        }
                    };
                }
            };
        }
    };
});
```

- [ ] **Step 3: `package.json` を作成**

```json
{
  "name": "amane-blog-distribution-e2e",
  "private": true,
  "scripts": {
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "test:e2e": "playwright test"
  },
  "devDependencies": {
    "@playwright/test": "^1.45.0",
    "@wordpress/env": "^10.0.0"
  }
}
```

- [ ] **Step 4: `playwright.config.ts` を作成**

```ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  use: {
    baseURL: 'http://localhost:8888',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  reporter: [['list'], ['html', { open: 'never' }]],
});
```

- [ ] **Step 5: 設定画面 E2E `e2e/settings.spec.ts` を作成**

```ts
import { test, expect, type Page } from '@playwright/test';

async function login(page: Page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page).toHaveURL(/wp-admin/);
}

test('管理者は設定を保存できる', async ({ page }) => {
  await login(page);
  await page.goto('/wp-admin/options-general.php?page=amane-blog-dist');

  await expect(page.locator('input[name="amane_api_url"]')).toBeVisible();
  await page.fill('input[name="amane_api_token"]', 'amb_e2e_token');
  await page.click('input[type="submit"][name="submit"], #submit');

  await expect(page.locator('input[name="amane_api_token"]')).toHaveValue('amb_e2e_token');
});
```

- [ ] **Step 6: 手動同期 E2E `e2e/sync.spec.ts` を作成**

`SettingsPage::render()` の手動同期フォームは `action=amane_manual_sync` を `admin-post.php` に送る。E2E では `Plugin::runSync` を直接叩くため、wp-env の WP-CLI で cron フックを実行して投稿生成を確認する。

```ts
import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

test('同期フックでフェイク記事から下書きが生成される', async ({ page }) => {
  // トークン設定 → cron フック実行（フェイク SDK が記事を返す）
  execSync('npx wp-env run cli wp option update amane_api_token amb_e2e_token', { stdio: 'inherit' });
  execSync('npx wp-env run cli wp eval "do_action(\'amane_sync_articles\');"', { stdio: 'inherit' });

  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');

  await page.goto('/wp-admin/edit.php?post_status=draft&post_type=post');
  await expect(page.getByText('E2E Sample Article')).toBeVisible();
});
```

- [ ] **Step 7: E2E をローカル実行（Node + Docker）**

Run:
```bash
npm install
npx playwright install --with-deps chromium
npm run env:start
npm run test:e2e
```
Expected: 2 スペック PASS。終了後 `npm run env:stop`。

- [ ] **Step 8: コミット**

```bash
git add .wp-env.json package.json package-lock.json playwright.config.ts tests/e2e-mu-plugin e2e
git commit -m "test: Playwright + wp-env による E2E を追加"
```

---

## Task 8: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: 全 composer scripts、`bin/install-wp-tests.sh`、wp-env、Playwright。

- [ ] **Step 1: `.github/workflows/ci.yml` を作成**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  unit:
    name: Unit (PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.1', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
          tools: composer:v2
      - name: Install dependencies
        run: composer update --prefer-dist --no-progress
      - name: Unit tests
        run: composer test:unit

  coverage:
    name: Coverage gate (90%)
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports: ['3306:3306']
        options: >-
          --health-cmd="healthcheck.sh --connect --innodb_initialized"
          --health-interval=10s --health-timeout=5s --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: pcov
          tools: composer:v2
          extensions: mysqli, pdo_mysql
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
      - name: Install WP test suite
        run: |
          chmod +x bin/install-wp-tests.sh
          bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest
      - name: Run coverage gate
        run: composer test:cov

  e2e:
    name: E2E (Playwright + wp-env)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - name: Install npm deps
        run: npm ci || npm install
      - name: Install Playwright browsers
        run: npx playwright install --with-deps chromium
      - name: Start wp-env
        run: npm run env:start
      - name: Run E2E
        run: npm run test:e2e
      - name: Stop wp-env
        if: always()
        run: npm run env:stop
      - name: Upload Playwright report
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: playwright-report
          retention-days: 7
```

注意: `coverage` ジョブの WP テストスイートは DB ホスト `127.0.0.1:3306` を使う（services の mysql はランナーの localhost にポート公開される）。`install-wp-tests.sh` の `localhost` 置換が `127.0.0.1:3306` を受けられることを Step での実行で確認する。

- [ ] **Step 2: ワークフロー構文をローカル検証（任意）**

Run: `docker compose run --rm php php -r "echo 'yaml ok';"`（YAML 専用 linter が無ければ目視確認。push 後に Actions タブで確認）

- [ ] **Step 3: コミットして push**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: GitHub Actions に unit/coverage/e2e ワークフローを追加"
git push -u origin feat/test-suite-and-ci
```

- [ ] **Step 4: GitHub Actions の結果を確認**

PR を作成し、`unit`(×3) / `coverage` / `e2e` が全て緑になることを確認する。`coverage` が 90% 未満なら不足テストを追加。

---

## Self-Review メモ（spec との対応）

- 継ぎ目リファクタ（ClientFactory + 注入）→ Task 2, 3。振る舞い不変・PHP7.4対応（プロパティ昇格不使用）→ 全コードで遵守。
- 単体/結合/E2E の3層 → Task 2–4 / Task 5 / Task 7。
- 90% カバレッジ合算ゲート → Task 6（unit.cov + integration.cov を phpcov merge → coverage-check.php）。
- docker-compose によるローカル実行 → Task 1（php/mysql サービス）。
- ArticleSyncJob 削除 → Task 3 Step 4。
- PHP 7.4/8.1/8.3 マトリクス → Task 8 `unit` ジョブ。
- 命名（filter `amane_blog_client_factory`, 名前空間, メソッド名）は全タスクで一貫。
