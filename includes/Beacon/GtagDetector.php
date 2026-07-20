<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Beacon;

/**
 * サイトに gtag.js (GA4) が入っているかを検出する (2026-07-20)。
 *
 * beacon は window.gtag が無いと何もせず終わる。顧客からは「有効にしたのにデータが来ない」
 * としか見えず、原因が分からないまま放置される。設定画面で検出結果を出して先回りする。
 *
 * 判定はプラグインの有無ではなく **フロントページの HTML を実際に見る**。テーマ直書き /
 * GTM / 各種 GA プラグインのどれで入れていても正しく判定できるのはこの方法だけ。
 *
 * 毎回 HTTP を叩くと設定画面が遅くなるので transient にキャッシュする。
 */
final class GtagDetector
{
    public const STATUS_OK = 'ok';               // gtag.js あり → beacon が動く
    public const STATUS_GTM_ONLY = 'gtm_only';   // GTM のみ → window.gtag が無い可能性
    public const STATUS_NONE = 'none';           // GA4 の痕跡なし
    public const STATUS_UNKNOWN = 'unknown';     // 取得失敗 (判定できない)

    private const TRANSIENT = 'amane_gtag_detection';
    private const TTL = 3600;   // 1 時間

    /**
     * @return array{status: string, measurement_ids: array<int, string>}
     */
    public function detect(bool $force = false): array
    {
        if (! $force) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached) && isset($cached['status'])) {
                return $cached;
            }
        }

        $result = $this->probe();
        set_transient(self::TRANSIENT, $result, self::TTL);

        return $result;
    }

    /**
     * @return array{status: string, measurement_ids: array<int, string>}
     */
    private function probe(): array
    {
        $response = wp_remote_get(home_url('/'), [
            'timeout' => 10,
            // 一部環境で bot 扱いされないよう通常の UA を名乗る (自サイトの取得なので問題ない)
            'user-agent' => 'Mozilla/5.0 (compatible; AmaneBeaconCheck/1.0)',
        ]);

        if (is_wp_error($response)) {
            return ['status' => self::STATUS_UNKNOWN, 'measurement_ids' => []];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            return ['status' => self::STATUS_UNKNOWN, 'measurement_ids' => []];
        }

        // gtag.js のロード (= window.gtag が定義される) を測定 ID ごとに拾う
        $ids = [];
        if (preg_match_all('#gtag/js\?id=(G-[A-Z0-9]+)#i', $body, $m)) {
            $ids = array_values(array_unique($m[1]));
        }

        if ($ids !== []) {
            return ['status' => self::STATUS_OK, 'measurement_ids' => $ids];
        }

        // GTM のみ = gtag が定義されないことがある (dataLayer だけの構成)
        if (str_contains($body, 'googletagmanager.com/gtm.js') || str_contains($body, 'GTM-')) {
            return ['status' => self::STATUS_GTM_ONLY, 'measurement_ids' => []];
        }

        return ['status' => self::STATUS_NONE, 'measurement_ids' => []];
    }

    /** 設定変更時などにキャッシュを捨てる。 */
    public function flush(): void
    {
        delete_transient(self::TRANSIENT);
    }
}
