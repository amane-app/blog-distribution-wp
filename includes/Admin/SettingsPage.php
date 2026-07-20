<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Admin;

use Amane\WpPlugin\Beacon\GtagDetector;
use Amane\WpPlugin\Beacon\SectionBeacon;

class SettingsPage
{
    private const OPTION_GROUP = 'amane_blog_dist';
    private const PAGE_SLUG    = 'amane-blog-dist';

    private GtagDetector $gtagDetector;

    public function __construct(?GtagDetector $gtagDetector = null)
    {
        $this->gtagDetector = $gtagDetector ?? new GtagDetector();
    }

    public function register(): void
    {
        // API connection section
        add_settings_section(
            'amane_api_section',
            __('API 接続設定', 'amane-blog-dist'),
            null,
            self::PAGE_SLUG,
        );

        register_setting(self::OPTION_GROUP, 'amane_api_url', [
            'type'              => 'string',
            // 前後の空白・改行を保存時に除去 (= base_uri 組立て事故防止)
            'sanitize_callback' => static fn ($v) => trim((string) $v),
        ]);
        add_settings_field(
            'amane_api_url',
            __('API URL', 'amane-blog-dist'),
            function (): void {
                // SDK >= 0.1.2 は baseUrl 末尾に /api/v1 が無くても自動付与するため、
                // 'https://service.amane.app' でも 'https://service.amane.app/api/v1' でも動作する。
                // デフォルトは記述が短くて済む形に統一。
                $value = esc_attr((string) get_option('amane_api_url', 'https://service.amane.app'));
                echo "<input type='text' name='amane_api_url' value='{$value}' class='regular-text' />";
                echo '<p class="description">' . esc_html__('例: https://service.amane.app ( /api/v1 は SDK が自動付与します)', 'amane-blog-dist') . '</p>';
            },
            self::PAGE_SLUG,
            'amane_api_section',
        );

        register_setting(self::OPTION_GROUP, 'amane_api_token', [
            'type'              => 'string',
            // 前後の空白・改行 (CRLF) を保存時に除去 (= Authorization ヘッダ壊れ防止)
            'sanitize_callback' => static fn ($v) => trim((string) $v),
        ]);
        add_settings_field(
            'amane_api_token',
            __('API トークン', 'amane-blog-dist'),
            function (): void {
                $value = esc_attr((string) get_option('amane_api_token', ''));
                echo "<input type='password' name='amane_api_token' value='{$value}' class='regular-text' autocomplete='off' />";
                echo '<p class="description">' . esc_html__('amb_ で始まる平文トークン。AMANE 管理画面 → サイト詳細 → 「📝 ブログ配信」タブから発行', 'amane-blog-dist') . '</p>';
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

        $this->registerBeaconSettings();
    }

    /**
     * セクション到達 beacon の設定 (2026-07-20)。
     *
     * 有効にすると、記事の H2 が読者の画面に入った時点で **このサイト自身の GA4** に
     * イベントを送る。AMANEA はそれを読み取って「どのセクションで離脱したか」を表示する。
     * 既定 OFF (= 顧客が明示的に有効化する)。
     */
    private function registerBeaconSettings(): void
    {
        add_settings_section(
            'amane_beacon_section',
            __('セクション離脱の計測', 'amane-blog-dist'),
            function (): void {
                echo '<p class="description">'
                    . esc_html__(
                        '記事の見出し（H2）がどこまで読まれたかを計測します。データはこのサイト自身の Google アナリティクスに記録され、AMANEA がそれを読み取って表示します。',
                        'amane-blog-dist'
                    )
                    . '</p>';
            },
            self::PAGE_SLUG,
        );

        register_setting(self::OPTION_GROUP, SectionBeacon::OPTION_ENABLED, ['type' => 'boolean']);
        add_settings_field(
            SectionBeacon::OPTION_ENABLED,
            __('セクション離脱を計測する', 'amane-blog-dist'),
            function (): void {
                $checked = checked(1, get_option(SectionBeacon::OPTION_ENABLED, 0), false);
                $name = esc_attr(SectionBeacon::OPTION_ENABLED);
                echo "<input type='checkbox' name='{$name}' value='1' {$checked} />";
                echo "<span class='description'> "
                    . esc_html__('H2 が 2 個以上ある記事で計測します', 'amane-blog-dist')
                    . '</span>';
                $this->renderGtagStatus();
            },
            self::PAGE_SLUG,
            'amane_beacon_section',
        );
    }

    /**
     * gtag.js の検出結果を出す。
     *
     * beacon は window.gtag が無いと何もせず終わるため、顧客からは「有効にしたのに
     * データが来ない」としか見えない。原因を先回りして示す。
     */
    private function renderGtagStatus(): void
    {
        $result = $this->gtagDetector->detect();
        $status = $result['status'] ?? GtagDetector::STATUS_UNKNOWN;

        if ($status === GtagDetector::STATUS_OK) {
            $ids = implode(', ', array_map('esc_html', $result['measurement_ids'] ?? []));
            echo '<p style="color:#116329;margin-top:8px;">'
                . esc_html__('✓ Google アナリティクス（gtag.js）を検出しました', 'amane-blog-dist')
                . ' <code>' . $ids . '</code></p>';

            return;
        }

        if ($status === GtagDetector::STATUS_GTM_ONLY) {
            echo '<p style="color:#8a6100;margin-top:8px;">'
                . esc_html__(
                    '⚠ Google タグマネージャーのみを検出しました。構成によっては計測できない場合があります（gtag.js が必要です）。有効化後にデータが届かない場合はご連絡ください。',
                    'amane-blog-dist'
                )
                . '</p>';

            return;
        }

        if ($status === GtagDetector::STATUS_NONE) {
            echo '<p style="color:#8a2424;margin-top:8px;">'
                . esc_html__(
                    '⚠ Google アナリティクス（gtag.js）が見つかりませんでした。この状態では計測できません。先に GA4 を設置してください。',
                    'amane-blog-dist'
                )
                . '</p>';

            return;
        }

        echo '<p style="color:#666;margin-top:8px;">'
            . esc_html__('（サイトの状態を確認できませんでした。時間をおいて再度お試しください）', 'amane-blog-dist')
            . '</p>';
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

            <hr />

            <h2><?php echo esc_html__('AI 提案の WordPress 自動適用', 'amane-blog-dist'); ?></h2>
            <p class="description">
                <?php echo esc_html__('AMANEA で採用した AI 提案の head 変更 (title / meta / canonical / OGP / JSON-LD) を、この WordPress に反映します。本文やレイアウトには触れません。', 'amane-blog-dist'); ?>
            </p>
            <?php $this->renderApplyNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="amane_manual_apply" />
                <?php wp_nonce_field('amane_manual_apply'); ?>
                <?php submit_button(__('今すぐ適用', 'amane-blog-dist'), 'primary'); ?>
            </form>
        </div>
        <?php
    }

    /** 「今すぐ適用」実行後のリダイレクトで付与された結果クエリを通知として描画する。 */
    private function renderApplyNotice(): void
    {
        if (! isset($_GET['amane_apply'])) {
            return;
        }

        $applied  = isset($_GET['applied']) ? (int) $_GET['applied'] : 0;
        $failed   = isset($_GET['failed']) ? (int) $_GET['failed'] : 0;
        $reverted = isset($_GET['reverted']) ? (int) $_GET['reverted'] : 0;

        $message = sprintf(
            /* translators: 1: applied, 2: reverted, 3: failed */
            esc_html__('適用 %1$d 件 / 取消 %2$d 件 / 失敗 %3$d 件', 'amane-blog-dist'),
            $applied,
            $reverted,
            $failed,
        );
        $class = $failed > 0 ? 'notice-warning' : 'notice-success';

        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), $message);
    }
}
