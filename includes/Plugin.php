<?php

declare(strict_types=1);

namespace Amane\WpPlugin;

use Amane\BlogSdk\AmaneClient;
use Amane\WpPlugin\Admin\SettingsPage;
use Amane\WpPlugin\Sync\ArticleSyncer;

class Plugin
{
    private const CRON_HOOK = 'amane_sync_articles';

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
        $syncer = new ArticleSyncer();
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

        $apiUrl   = (string) get_option('amane_api_url', 'https://service.amane.app/api/v1');
        $apiToken = (string) get_option('amane_api_token', '');

        if (! $apiToken) {
            return;
        }

        try {
            $client = AmaneClient::make($apiUrl, $apiToken);
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
