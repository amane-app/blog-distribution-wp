<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Apply;

use Amane\WpPlugin\Apply\ApplyClient;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ApplyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_jobs_parses_data(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['data' => [['job_id' => 1]]]));
        Functions\expect('wp_remote_get')->once()->andReturn(['dummy']);

        $jobs = (new ApplyClient('https://service.amane.app', 'amb_t'))->listJobs('queued');

        $this->assertSame(1, $jobs[0]['job_id']);
    }

    public function test_base_url_normalization_strips_trailing_api_v1(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"data":[]}');
        Functions\expect('wp_remote_get')->once()
            ->with(
                Mockery::pattern('#^https://service\.amane\.app/api/v1/wp/apply-jobs\?status=queued$#'),
                Mockery::type('array'),
            )
            ->andReturn(['dummy']);

        (new ApplyClient('https://service.amane.app/api/v1/', 'amb_t'))->listJobs('queued');

        $this->assertTrue(true);
    }

    public function test_report_result_posts_to_result_endpoint(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\expect('wp_remote_post')->once()
            ->with(Mockery::pattern('#/api/v1/wp/apply-jobs/5/result$#'), Mockery::type('array'))
            ->andReturn(['dummy']);

        (new ApplyClient('https://service.amane.app', 'amb_t'))->reportResult(5, ['status' => 'applied']);

        $this->assertTrue(true);
    }

    public function test_throws_on_error_status(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(403);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"detail":"nope"}');
        Functions\when('wp_remote_get')->justReturn(['dummy']);

        $this->expectException(\RuntimeException::class);

        (new ApplyClient('https://service.amane.app', 'amb_t'))->listJobs('queued');
    }

    public function test_throws_on_wp_error(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        $err = Mockery::mock();
        $err->shouldReceive('get_error_message')->andReturn('conn refused');
        Functions\when('wp_remote_get')->justReturn($err);

        $this->expectException(\RuntimeException::class);

        (new ApplyClient('https://service.amane.app', 'amb_t'))->listJobs('queued');
    }
}
