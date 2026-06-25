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
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
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
