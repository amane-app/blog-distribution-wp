<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Admin;

use Amane\WpPlugin\Admin\SettingsPage;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('add_settings_section')->justReturn(null);
        Functions\when('checked')->justReturn("checked='checked'");
        // セクション離脱の設定フィールドが gtag 検出を呼ぶ。キャッシュ済みを返して
        // テスト中に HTTP を叩かせない (検出そのものは GtagDetectorTest で検証)。
        Functions\when('get_transient')->justReturn([
            'status' => \Amane\WpPlugin\Beacon\GtagDetector::STATUS_OK,
            'measurement_ids' => ['G-TEST1234'],
        ]);

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
