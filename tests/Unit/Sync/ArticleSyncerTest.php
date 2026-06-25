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
        Functions\when('wp_insert_post')->justReturn(123);

        $syncer = new ArticleSyncer($this->factoryReturning($articles));
        $result = $syncer->sync();

        self::assertSame(1, $result->created);
    }
}
