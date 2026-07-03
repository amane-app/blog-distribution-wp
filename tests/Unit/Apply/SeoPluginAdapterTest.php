<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Apply;

use Amane\WpPlugin\Apply\SeoPluginAdapter;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class SeoPluginAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_detect_returns_none_without_seo_plugins(): void
    {
        $this->assertSame('none', (new SeoPluginAdapter())->detect());
    }

    public function test_apply_under_yoast_writes_only_scalar_keys(): void
    {
        // Yoast 有効時は og/twitter/jsonld を書かない (= 二重出力回避)
        Functions\expect('update_post_meta')->once()->with(42, '_yoast_wpseo_title', '新タイトル');
        Functions\expect('update_post_meta')->once()->with(42, '_yoast_wpseo_metadesc', '新説明');
        Functions\expect('update_post_meta')->once()->with(42, '_yoast_wpseo_canonical', 'https://x/');

        (new SeoPluginAdapter())->apply(42, [
            'title'            => '新タイトル',
            'meta_description' => '新説明',
            'canonical'        => 'https://x/',
            'og'               => ['og:title' => 'X'], // yoast では無視される
        ], 'yoast');

        $this->assertTrue(true);
    }

    public function test_apply_under_none_writes_self_and_rich_meta(): void
    {
        Functions\expect('update_post_meta')->once()->with(42, '_amane_head_title', 'T');
        Functions\expect('update_post_meta')->once()
            ->with(42, SeoPluginAdapter::META_OG, json_encode(['og:title' => 'X']));

        (new SeoPluginAdapter())->apply(42, [
            'title' => 'T',
            'og'    => ['og:title' => 'X'],
        ], 'none');

        $this->assertTrue(true);
    }

    public function test_restore_deletes_scalar_meta_when_before_absent(): void
    {
        Functions\expect('update_post_meta')->once()->with(42, '_yoast_wpseo_title', '旧');
        Functions\expect('delete_post_meta')->once()->with(42, '_yoast_wpseo_metadesc');
        Functions\expect('delete_post_meta')->once()->with(42, '_yoast_wpseo_canonical');

        (new SeoPluginAdapter())->restore(42, ['title' => '旧'], 'yoast');

        $this->assertTrue(true);
    }

    public function test_read_current_collects_scalar_and_rich_meta(): void
    {
        Functions\when('get_post_meta')->alias(static function ($id, $key) {
            $map = [
                '_yoast_wpseo_title'         => 'T',
                SeoPluginAdapter::META_OG    => json_encode(['og:title' => 'X']),
            ];

            return $map[$key] ?? '';
        });

        $snap = (new SeoPluginAdapter())->readCurrent(42, 'yoast');

        $this->assertSame('T', $snap['title']);
        $this->assertSame(['og:title' => 'X'], $snap['og']);
    }
}
