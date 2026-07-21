<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Beacon;

/**
 * セクション到達 beacon (2026-07-20)。
 *
 * 記事内の H2 見出しが画面に入った時点で、**このサイト自身の GA4** に
 * amanea_section_view_N というイベントを送る。AMANEA 側は顧客の GA4 を既存 OAuth で
 * 読み取り、「どのセクションで読者が離脱したか」をカルテに表示する。
 *
 * 送信先が AMANEA のサーバではなく顧客自身の GA4 なので、**新規のデータ収集は発生しない**
 * (= 顧客のプライバシーポリシー改訂や同意基盤の変更が要らない)。これがこの方式を選んだ理由。
 *
 * イベント名に index を埋めるのは、パラメータ方式だと顧客ごとに GA4 管理画面で
 * カスタムディメンションを登録してもらう必要があり、「顧客の設定作業ゼロ」という
 * 前提が崩れるため。eventName は標準ディメンションなので登録不要で読める。
 */
final class SectionBeacon
{
    /** 有効化フラグ (既定 OFF)。設定画面のトグルで切り替える。 */
    public const OPTION_ENABLED = 'amane_section_beacon_enabled';

    /**
     * 追跡する H2 の上限。**AMANEA 側の config('section_engagement.max_sections') と揃える**。
     * ここがズレると、超過分が取り込み時に捨てられる (= 送信が無駄になる)。
     *
     * 2026-07-21 に 10 → 20 へ引き上げ。本番実測で LP のトップページは h2 が 14 個あり、
     * 上限 10 では料金セクション (12 番目) が計測できていなかった。
     */
    private const MAX_SECTIONS = 20;

    public function isEnabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, 0);
    }

    /**
     * wp_footer で beacon を出力する。
     *
     * 出力しないケース:
     *  - 設定が OFF
     *  - 管理画面 / フィード / robots.txt など、閲覧者のスクロールが存在しない文脈
     *
     * gtag が無い環境 (GTM 経由のみ等) では JS 側が何もせず終わる (安全側に倒す)。
     */
    public function render(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (is_admin() || is_feed() || is_robots()) {
            return;
        }

        // gtag / IntersectionObserver 未対応環境では no-op。
        // h2 が 1 個以下のページは離脱曲線にならないので送らない (無駄なイベントを増やさない)。
        $max = self::MAX_SECTIONS;
        echo <<<HTML
<script>
(function () {
  if (typeof window.gtag !== 'function' || !('IntersectionObserver' in window)) return;
  var headings = Array.prototype.slice.call(document.querySelectorAll('h2'), 0, {$max});
  if (headings.length < 2) return;
  var sent = {};
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      var idx = headings.indexOf(entry.target);
      if (idx < 0 || sent[idx]) return;
      sent[idx] = true;
      window.gtag('event', 'amanea_section_view_' + idx);
      observer.unobserve(entry.target);
    });
  }, { threshold: 0 });
  headings.forEach(function (h) { observer.observe(h); });
})();
</script>

HTML;
    }
}
