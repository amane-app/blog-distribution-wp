<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Beacon;

use Amane\WpPlugin\Beacon\SectionBeacon;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class SectionBeaconTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // 既定は「公開ページを閲覧中」
        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_feed')->justReturn(false);
        Functions\when('is_robots')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function capture(SectionBeacon $beacon): string
    {
        ob_start();
        $beacon->render();

        return (string) ob_get_clean();
    }

    public function test_outputs_beacon_when_enabled(): void
    {
        Functions\when('get_option')->justReturn(1);

        $html = $this->capture(new SectionBeacon());

        $this->assertStringContainsString('amanea_section_view_', $html);
        $this->assertStringContainsString('IntersectionObserver', $html);
        // h2 を上から順に拾う (サーバ側 h2_texts と同じ並びにするため)
        $this->assertStringContainsString("querySelectorAll('h2')", $html);
    }

    public function test_outputs_nothing_when_disabled(): void
    {
        // 既定 OFF: 顧客が明示的に有効化するまで送らない
        Functions\when('get_option')->justReturn(0);

        $this->assertSame('', $this->capture(new SectionBeacon()));
    }

    public function test_outputs_nothing_in_admin(): void
    {
        Functions\when('get_option')->justReturn(1);
        Functions\when('is_admin')->justReturn(true);

        $this->assertSame('', $this->capture(new SectionBeacon()));
    }

    public function test_outputs_nothing_for_feed_and_robots(): void
    {
        Functions\when('get_option')->justReturn(1);

        Functions\when('is_feed')->justReturn(true);
        $this->assertSame('', $this->capture(new SectionBeacon()));

        Functions\when('is_feed')->justReturn(false);
        Functions\when('is_robots')->justReturn(true);
        $this->assertSame('', $this->capture(new SectionBeacon()));
    }

    public function test_script_guards_missing_gtag(): void
    {
        // GTM のみの環境では window.gtag が未定義。何もせず終わる (安全側)
        Functions\when('get_option')->justReturn(1);

        $html = $this->capture(new SectionBeacon());

        $this->assertStringContainsString("typeof window.gtag !== 'function'", $html);
    }

    public function test_script_skips_pages_with_fewer_than_two_headings(): void
    {
        // h2 が 1 個以下だと離脱曲線にならないので送らない
        Functions\when('get_option')->justReturn(1);

        $html = $this->capture(new SectionBeacon());

        $this->assertStringContainsString('headings.length < 2', $html);
    }

    public function test_is_enabled_reflects_option(): void
    {
        Functions\when('get_option')->justReturn(1);
        $this->assertTrue((new SectionBeacon())->isEnabled());

        Functions\when('get_option')->justReturn(0);
        $this->assertFalse((new SectionBeacon())->isEnabled());
    }
}
