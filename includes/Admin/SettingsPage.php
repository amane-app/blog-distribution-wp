<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Admin;

class SettingsPage
{
    private const OPTION_GROUP = 'amane_blog_dist';
    private const PAGE_SLUG    = 'amane-blog-dist';

    public function register(): void
    {
        // API connection section
        add_settings_section(
            'amane_api_section',
            __('API 接続設定', 'amane-blog-dist'),
            null,
            self::PAGE_SLUG,
        );

        register_setting(self::OPTION_GROUP, 'amane_api_url', ['type' => 'string']);
        add_settings_field(
            'amane_api_url',
            __('API URL', 'amane-blog-dist'),
            function (): void {
                $value = esc_attr((string) get_option('amane_api_url', 'https://service.amane.app/api/v1'));
                echo "<input type='text' name='amane_api_url' value='{$value}' class='regular-text' />";
            },
            self::PAGE_SLUG,
            'amane_api_section',
        );

        register_setting(self::OPTION_GROUP, 'amane_api_token', ['type' => 'string']);
        add_settings_field(
            'amane_api_token',
            __('API トークン', 'amane-blog-dist'),
            function (): void {
                $value = esc_attr((string) get_option('amane_api_token', ''));
                echo "<input type='password' name='amane_api_token' value='{$value}' class='regular-text' autocomplete='off' />";
            },
            self::PAGE_SLUG,
            'amane_api_section',
        );

        // Sync section
        add_settings_section(
            'amane_sync_section',
            __('同期設定', 'amane-blog-dist'),
            null,
            self::PAGE_SLUG,
        );

        register_setting(self::OPTION_GROUP, 'amane_auto_publish', ['type' => 'boolean']);
        add_settings_field(
            'amane_auto_publish',
            __('自動公開', 'amane-blog-dist'),
            function (): void {
                $checked = checked(1, get_option('amane_auto_publish', 0), false);
                echo "<input type='checkbox' name='amane_auto_publish' value='1' {$checked} />";
                echo "<span class='description'> " . esc_html__('取得した記事を自動的に公開する', 'amane-blog-dist') . '</span>';
            },
            self::PAGE_SLUG,
            'amane_sync_section',
        );

        register_setting(self::OPTION_GROUP, 'amane_post_category', ['type' => 'integer']);
        add_settings_field(
            'amane_post_category',
            __('投稿カテゴリ ID', 'amane-blog-dist'),
            function (): void {
                $value = (int) get_option('amane_post_category', 0);
                echo "<input type='number' name='amane_post_category' value='{$value}' min='0' class='small-text' />";
            },
            self::PAGE_SLUG,
            'amane_sync_section',
        );

        register_setting(self::OPTION_GROUP, 'amane_post_author', ['type' => 'integer']);
        add_settings_field(
            'amane_post_author',
            __('投稿者 ID', 'amane-blog-dist'),
            function (): void {
                $value = (int) get_option('amane_post_author', 1);
                echo "<input type='number' name='amane_post_author' value='{$value}' min='1' class='small-text' />";
            },
            self::PAGE_SLUG,
            'amane_sync_section',
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->register();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AMANE Blog Distribution 設定', 'amane-blog-dist'); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button(__('変更を保存', 'amane-blog-dist'));
                ?>
            </form>

            <hr />

            <h2><?php echo esc_html__('手動同期', 'amane-blog-dist'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="amane_manual_sync" />
                <?php wp_nonce_field('amane_manual_sync'); ?>
                <?php submit_button(__('今すぐ同期', 'amane-blog-dist'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }
}
