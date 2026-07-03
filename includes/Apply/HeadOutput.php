<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Apply;

/**
 * SEO プラグイン無し (self-managed) のときに、AMANEA 適用で保存した head 要素を
 * wp_head / document_title で出力する。
 *
 * Yoast / Rank Math が有効なときは title/meta/canonical をそれらの postmeta に書いて
 * いる (= 自前 _amane_head_* は空) ので、本クラスは何も出力しない (= 二重出力なし)。
 */
class HeadOutput
{
    /** wp_head フックで自前 head 要素を出力する。 */
    public function render(): void
    {
        if (! is_singular()) {
            return;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0) {
            return;
        }

        // meta description
        $desc = (string) get_post_meta($postId, '_amane_head_meta_description', true);
        if ($desc !== '') {
            printf('<meta name="description" content="%s" />' . "\n", esc_attr($desc));
        }

        // canonical
        $canonical = (string) get_post_meta($postId, '_amane_head_canonical', true);
        if ($canonical !== '') {
            printf('<link rel="canonical" href="%s" />' . "\n", esc_url($canonical));
        }

        // OGP
        foreach ($this->decodeMap($postId, SeoPluginAdapter::META_OG) as $property => $content) {
            printf(
                '<meta property="%s" content="%s" />' . "\n",
                esc_attr((string) $property),
                esc_attr((string) $content),
            );
        }

        // Twitter Card
        foreach ($this->decodeMap($postId, SeoPluginAdapter::META_TWITTER) as $name => $content) {
            printf(
                '<meta name="%s" content="%s" />' . "\n",
                esc_attr((string) $name),
                esc_attr((string) $content),
            );
        }

        // JSON-LD (配列の各エントリを 1 script として出力)
        foreach ($this->decodeList($postId, SeoPluginAdapter::META_JSONLD) as $entry) {
            echo '<script type="application/ld+json">' . wp_json_encode($entry) . '</script>' . "\n";
        }
    }

    /**
     * document_title_parts フィルタ: self-managed title があれば置換する。
     *
     * @param array<string,string> $parts
     * @return array<string,string>
     */
    public function filterTitleParts(array $parts): array
    {
        if (! is_singular()) {
            return $parts;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0) {
            return $parts;
        }

        $title = (string) get_post_meta($postId, '_amane_head_title', true);
        if ($title !== '') {
            $parts['title'] = $title;
        }

        return $parts;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMap(int $postId, string $key): array
    {
        $raw = (string) get_post_meta($postId, $key, true);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeList(int $postId, string $key): array
    {
        $decoded = $this->decodeMap($postId, $key);

        return array_values($decoded);
    }
}
