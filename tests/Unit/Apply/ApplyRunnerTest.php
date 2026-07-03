<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Apply;

use Amane\WpPlugin\Apply\ApplyClient;
use Amane\WpPlugin\Apply\ApplyRunner;
use Amane\WpPlugin\Apply\SeoPluginAdapter;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ApplyRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<int,array<string,mixed>> $queued @param array<int,array<string,mixed>> $revert */
    private function clientReturning(array $queued, array $revert = []): ApplyClient
    {
        $client = Mockery::mock(ApplyClient::class);
        $client->shouldReceive('listJobs')->with('queued')->andReturn($queued);
        $client->shouldReceive('listJobs')->with('revert_requested')->andReturn($revert);

        return $client;
    }

    public function test_applies_queued_job_and_reports_applied(): void
    {
        Functions\when('url_to_postid')->justReturn(42);

        $adapter = Mockery::mock(SeoPluginAdapter::class);
        $adapter->shouldReceive('detect')->andReturn('yoast');
        $adapter->shouldReceive('readCurrent')->with(42, 'yoast')->andReturn(['title' => '旧タイトル']);
        $adapter->shouldReceive('apply')->once()->with(42, Mockery::type('array'), 'yoast');

        $client = $this->clientReturning([
            ['job_id' => 7, 'url' => 'https://wp.example.com/post/1', 'changes' => ['title' => '新タイトル']],
        ]);
        $client->shouldReceive('reportResult')->once()->with(7, Mockery::on(function ($p) {
            return $p['status'] === 'applied'
                && $p['seo_plugin'] === 'yoast'
                && $p['target_post_id'] === 42
                && $p['before_snapshot'] === ['title' => '旧タイトル'];
        }));

        $result = (new ApplyRunner($client, $adapter))->run();

        $this->assertSame(1, $result->applied);
        $this->assertSame(0, $result->failed);
    }

    public function test_reports_failed_when_url_not_resolved(): void
    {
        Functions\when('url_to_postid')->justReturn(0);

        $adapter = Mockery::mock(SeoPluginAdapter::class);
        $adapter->shouldNotReceive('apply');

        $client = $this->clientReturning([
            ['job_id' => 8, 'url' => 'https://wp.example.com/missing', 'changes' => ['title' => 'x']],
        ]);
        $client->shouldReceive('reportResult')->once()->with(8, Mockery::on(function ($p) {
            return $p['status'] === 'failed' && $p['error'] === 'post_not_resolved';
        }));

        $result = (new ApplyRunner($client, $adapter))->run();

        $this->assertSame(0, $result->applied);
        $this->assertSame(1, $result->failed);
    }

    public function test_reports_failed_when_apply_throws(): void
    {
        Functions\when('url_to_postid')->justReturn(42);

        $adapter = Mockery::mock(SeoPluginAdapter::class);
        $adapter->shouldReceive('detect')->andReturn('none');
        $adapter->shouldReceive('readCurrent')->andReturn([]);
        $adapter->shouldReceive('apply')->andThrow(new \RuntimeException('boom'));

        $client = $this->clientReturning([
            ['job_id' => 9, 'url' => 'https://wp.example.com/post/1', 'changes' => ['title' => 'x']],
        ]);
        $client->shouldReceive('reportResult')->once()->with(9, Mockery::on(function ($p) {
            return $p['status'] === 'failed' && str_contains($p['error'], 'boom');
        }));

        $result = (new ApplyRunner($client, $adapter))->run();

        $this->assertSame(1, $result->failed);
        $this->assertNotEmpty($result->errors);
    }

    public function test_reverts_requested_job_and_restores_before(): void
    {
        $adapter = Mockery::mock(SeoPluginAdapter::class);
        $adapter->shouldReceive('detect')->andReturn('rankmath');
        $adapter->shouldReceive('restore')->once()->with(42, ['title' => '旧タイトル'], 'rankmath');

        $client = $this->clientReturning([], [
            [
                'job_id'          => 10,
                'target_post_id'  => 42,
                'before_snapshot' => ['title' => '旧タイトル'],
            ],
        ]);
        $client->shouldReceive('reportRevertResult')->once()->with(10, Mockery::on(function ($p) {
            return isset($p['reverted_at']);
        }));

        $result = (new ApplyRunner($client, $adapter))->run();

        $this->assertSame(1, $result->reverted);
    }

    public function test_returns_error_when_no_token_configured(): void
    {
        Functions\when('get_option')->alias(static fn ($k, $d = false) => $k === 'amane_api_token' ? '' : $d);

        $result = (new ApplyRunner())->run();

        $this->assertNotEmpty($result->errors);
        $this->assertSame(0, $result->applied);
    }
}
