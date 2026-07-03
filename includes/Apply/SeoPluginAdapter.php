<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Apply;

/**
 * 検出した SEO プラグインに応じて head 要素 (title / meta description / canonical) を
 * postmeta に読み書きするアダプタ。og / twitter / JSON-LD は SEO プラグインごとの差異が
 * 大きいので、常にプラグイン自前の postmeta (_amane_head_*) に保存し wp_head で出力する
 * ([HeadOutput])。
 *
 * 対応:
 * - Yoast SEO / Rank Math … title / meta / canonical をそれぞれの postmeta キーへ
 * - AIOSEO / なし          … title / meta / canonical も自前 postmeta (_amane_head_*) へ
 *   (AIOSEO は独自 DB テーブル管理で postmeta 非対応のため、自前出力にフォールバック)
 */
class SeoPluginAdapter
{
    public const META_OG       = '_amane_head_og';
    public const META_TWITTER  = '_amane_head_twitter';
    public const META_JSONLD   = '_amane_head_jsonld';

    /** 自前フォールバックの head postmeta キー (plugin=none/aioseo 用)。 */
    private const SELF_KEYS = [
        'title'            => '_amane_head_title',
        'meta_description' => '_amane_head_meta_description',
        'canonical'        => '_amane_head_canonical',
    ];

    /** SEO プラグイン別の postmeta キー。 */
    private const PLUGIN_KEYS = [
        'yoast' => [
            'title'            => '_yoast_wpseo_title',
            'meta_description' => '_yoast_wpseo_metadesc',
            'canonical'        => '_yoast_wpseo_canonical',
        ],
        'rankmath' => [
            'title'            => 'rank_math_title',
            'meta_description' => 'rank_math_description',
            'canonical'        => 'rank_math_canonical_url',
        ],
    ];

    /** 検出した SEO プラグイン (yoast / rankmath / aioseo / none)。 */
    public function detect(): string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }
        if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            return 'aioseo';
        }

        return 'none';
    }

    /**
     * 現在の head 値を読み取る (before スナップショット用)。
     *
     * @return array<string,mixed>
     */
    public function readCurrent(int $postId, string $plugin): array
    {
        $keys = $this->scalarKeys($plugin);
        $snapshot = [];

        foreach ($keys as $field => $metaKey) {
            $val = get_post_meta($postId, $metaKey, true);
            if ($val !== '' && $val !== false && $val !== null) {
                $snapshot[$field] = (string) $val;
            }
        }

        foreach (
            [
                'og'      => self::META_OG,
                'twitter' => self::META_TWITTER,
                'jsonld'  => self::META_JSONLD,
            ] as $field => $metaKey
        ) {
            $val = get_post_meta($postId, $metaKey, true);
            if ($val !== '' && $val !== false && $val !== null) {
                $decoded = json_decode((string) $val, true);
                if (is_array($decoded) && $decoded !== []) {
                    $snapshot[$field] = $decoded;
                }
            }
        }

        return $snapshot;
    }

    /**
     * 正規化 head-change を postmeta へ適用する。
     *
     * og / twitter / JSON-LD は Yoast / Rank Math が自前で OGP・スキーマを出力するため、
     * それらが有効なときは二重出力を避けて適用しない (= title/meta/canonical のみ)。
     * SEO プラグイン無し (none/aioseo フォールバック) のときだけ自前 postmeta に保存し
     * [HeadOutput] で出力する。
     *
     * @param array<string,mixed> $changes title / meta_description / canonical / og / twitter / jsonld
     */
    public function apply(int $postId, array $changes, string $plugin): void
    {
        $keys = $this->scalarKeys($plugin);

        foreach ($keys as $field => $metaKey) {
            if (isset($changes[$field]) && $changes[$field] !== '') {
                update_post_meta($postId, $metaKey, (string) $changes[$field]);
            }
        }

        if (! $this->managesRichMeta($plugin)) {
            return;
        }

        foreach ($this->richMetaKeys() as $field => $metaKey) {
            if (! empty($changes[$field])) {
                update_post_meta($postId, $metaKey, wp_json_encode($changes[$field]));
            }
        }
    }

    /**
     * before スナップショットの値へ書き戻す。before に無いフィールドは削除して
     * 「未設定だった」状態へ戻す。
     *
     * @param array<string,mixed> $before
     */
    public function restore(int $postId, array $before, string $plugin): void
    {
        $keys = $this->scalarKeys($plugin);

        foreach ($keys as $field => $metaKey) {
            if (isset($before[$field]) && $before[$field] !== '') {
                update_post_meta($postId, $metaKey, (string) $before[$field]);
            } else {
                delete_post_meta($postId, $metaKey);
            }
        }

        if (! $this->managesRichMeta($plugin)) {
            return;
        }

        foreach ($this->richMetaKeys() as $field => $metaKey) {
            if (! empty($before[$field])) {
                update_post_meta($postId, $metaKey, wp_json_encode($before[$field]));
            } else {
                delete_post_meta($postId, $metaKey);
            }
        }
    }

    /**
     * title / meta_description / canonical の postmeta キー map を返す。
     *
     * @return array<string,string>
     */
    private function scalarKeys(string $plugin): array
    {
        return self::PLUGIN_KEYS[$plugin] ?? self::SELF_KEYS;
    }

    /** @return array<string,string> */
    private function richMetaKeys(): array
    {
        return [
            'og'      => self::META_OG,
            'twitter' => self::META_TWITTER,
            'jsonld'  => self::META_JSONLD,
        ];
    }

    /** Yoast / Rank Math は og/schema を自前で持つので、それ以外のときだけ自前管理する。 */
    private function managesRichMeta(string $plugin): bool
    {
        return ! isset(self::PLUGIN_KEYS[$plugin]);
    }
}
