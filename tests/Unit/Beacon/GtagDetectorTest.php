<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Beacon;

use Amane\WpPlugin\Beacon\GtagDetector;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * gtag.js 検出。beacon は window.gtag が無いと何もせず終わるため、
 * 設定画面で先回りして原因を示せるようにする。
 */
final class GtagDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('get_transient')->justReturn(false);   // キャッシュ無し
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function withBody(string $body): GtagDetector
    {
        Functions\when('wp_remote_get')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);

        return new GtagDetector();
    }

    public function test_detects_gtag_and_measurement_ids(): void
    {
        $detector = $this->withBody(
            '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC12345"></script>'
        );

        $result = $detector->detect();

        $this->assertSame(GtagDetector::STATUS_OK, $result['status']);
        $this->assertSame(['G-ABC12345'], $result['measurement_ids']);
    }

    public function test_collects_multiple_measurement_ids_without_duplicates(): void
    {
        // 1 ページに複数プロパティが載っているサイトがある (本番実測: trans-it.net)
        $detector = $this->withBody(
            '<script src="https://www.googletagmanager.com/gtag/js?id=G-AAA11111"></script>'
            . '<script src="https://www.googletagmanager.com/gtag/js?id=G-BBB22222"></script>'
            . '<script src="https://www.googletagmanager.com/gtag/js?id=G-AAA11111"></script>'
        );

        $result = $detector->detect();

        $this->assertSame(GtagDetector::STATUS_OK, $result['status']);
        $this->assertSame(['G-AAA11111', 'G-BBB22222'], $result['measurement_ids']);
    }

    public function test_reports_gtm_only_when_no_gtag(): void
    {
        // GTM だけだと window.gtag が定義されない構成がある
        $detector = $this->withBody(
            '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-XXXX"></script>'
        );

        $result = $detector->detect();

        $this->assertSame(GtagDetector::STATUS_GTM_ONLY, $result['status']);
        $this->assertSame([], $result['measurement_ids']);
    }

    public function test_reports_none_when_no_analytics(): void
    {
        $detector = $this->withBody('<html><body><h1>ただのページ</h1></body></html>');

        $this->assertSame(GtagDetector::STATUS_NONE, $detector->detect()['status']);
    }

    public function test_reports_unknown_on_http_error(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '']);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);

        // 取得できなかったときは「GA4 が無い」と断定しない
        $this->assertSame(GtagDetector::STATUS_UNKNOWN, (new GtagDetector())->detect()['status']);
    }

    public function test_reports_unknown_on_wp_error(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '']);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertSame(GtagDetector::STATUS_UNKNOWN, (new GtagDetector())->detect()['status']);
    }

    public function test_uses_cached_result(): void
    {
        $cached = ['status' => GtagDetector::STATUS_OK, 'measurement_ids' => ['G-CACHED1']];
        Functions\when('get_transient')->justReturn($cached);
        // wp_remote_get を定義しない = 呼ばれたら致命的エラーになるので「叩いていない」ことの証明になる

        $this->assertSame($cached, (new GtagDetector())->detect());
    }
}
