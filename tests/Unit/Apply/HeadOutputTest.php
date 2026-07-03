<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Apply;

use Amane\WpPlugin\Apply\HeadOutput;
use Amane\WpPlugin\Apply\SeoPluginAdapter;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class HeadOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_render_outputs_self_managed_tags(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('get_post_meta')->alias(static function ($id, $key) {
            $map = [
                '_amane_head_meta_description' => '説明文',
                '_amane_head_canonical'        => 'https://x/',
                SeoPluginAdapter::META_OG      => json_encode(['og:title' => 'T']),
                SeoPluginAdapter::META_JSONLD  => json_encode([['@type' => 'FAQPage']]),
            ];

            return $map[$key] ?? '';
        });

        ob_start();
        (new HeadOutput())->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('meta name="description" content="説明文"', $html);
        $this->assertStringContainsString('rel="canonical" href="https://x/"', $html);
        $this->assertStringContainsString('property="og:title" content="T"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('FAQPage', $html);
    }

    public function test_render_noop_when_not_singular(): void
    {
        Functions\when('is_singular')->justReturn(false);

        ob_start();
        (new HeadOutput())->render();
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
    }

    public function test_filter_title_parts_overrides_when_meta_present(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('get_post_meta')->alias(
            static fn ($id, $key) => $key === '_amane_head_title' ? 'MyTitle' : '',
        );

        $parts = (new HeadOutput())->filterTitleParts(['title' => 'Old']);

        $this->assertSame('MyTitle', $parts['title']);
    }
}
