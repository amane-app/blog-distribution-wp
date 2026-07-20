# WordPress.org 公開準備チェックリスト

> **状態 (2026-07-20)**: 未公開。GitHub (`amane-app/blog-distribution-wp`) のみで配布。
> **公開時にバージョンを 1.0.0 にする**方針。それまでは 0.x を維持する。

WordPress.org の公式ディレクトリに公開する際、審査で確実に問われる項目をまとめる。
公開を決めた時点でまとめて対応すればよく、それまでは着手不要。

---

## 1. `readme.txt` の作成 (必須・未着手)

WordPress.org は `README.md` を読まない。**専用フォーマットの `readme.txt`** が必要。

```
=== AMANE Blog Distribution ===
Contributors: (wordpress.org のユーザー名)
Tags: seo, analytics, content
Requires at least: 6.0
Tested up to: (公開時点の最新 WP)
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
== Installation ==
== Frequently Asked Questions ==
== Screenshots ==
== Changelog ==
```

- **`Stable tag` が実際のリリースタグと一致していないと配布が壊れる**。ここが最頻出の事故
- `Tested up to` は公開のたびに更新が要る (古いと「メンテされていない」表示になる)

## 2. 外部通信の明示 (必須・要記載)

プラグインガイドラインで、外部サービスへの通信は**用途・送信先・プライバシーポリシー**の
明示が求められる。本プラグインの該当箇所は 3 つ。

| 通信 | 送信先 | 用途 |
|---|---|---|
| 記事 pull / 公開報告 | `service.amane.app` (顧客が設定した API URL) | 配信記事の取得と公開報告 |
| 自動適用ジョブの pull / 結果報告 | 同上 | AI 提案の head 反映 |
| **gtag 検出** (`GtagDetector`) | **自サイト** (`home_url()`) | 設定画面で GA4 の有無を判定 |

**セクション到達 beacon は外部通信に該当しない**。送信先は顧客自身の GA4 であり、
プラグインから AMANEA へは何も送らない。この点は審査上むしろ有利なので、
Description で明確に書くとよい (「計測データはお客様自身の Google アナリティクスに記録され、
本プラグインが外部に送信することはありません」)。

## 3. インラインスクリプト出力の見直し (要検討)

`SectionBeacon::render()` は `echo '<script>...'` で直接出力している。
WordPress 流儀は `wp_add_inline_script()` だが、**登録済みハンドルへの依存が必要**で、
gtag のハンドルは環境依存 (テーマ直書き / GTM / 各種 GA プラグインでバラバラ) のため、
確実に動く保証がない。

現状は**動作確実性を優先して直接出力**を選んでいる。審査で指摘された場合の代替案:

- `wp_print_footer_scripts` に紐づける
- `wp_register_script('amane-section-beacon', false)` でハンドルレス登録してから
  `wp_add_inline_script` する (依存を持たない形)

**指摘されるまで変えない**判断でよいが、変更すると gtag より先に出力されて壊れる恐れが
あるので、変える場合は実サイトでの発火確認が必須。

## 4. テキストドメインとスラッグの整合 (要調整)

現在のテキストドメインは `amane-blog-dist`。WordPress.org では**プラグインスラッグと
テキストドメインを一致させる**必要がある。

- 公開スラッグを確定させる (例: `amane-blog-distribution`)
- 全ファイルのテキストドメインを一括置換
- `Text Domain:` ヘッダを本体ファイルに明記

スラッグは公開後に変更できないので、**申請前に確定させること**。

## 5. その他の定番項目

- **`License` ヘッダ**: 本体ファイルに `License: GPL v2 or later` を明記 (LICENSE ファイルは既にある)
- **直接アクセス防止**: 各 PHP ファイル先頭の `if (!defined('ABSPATH')) exit;`。
  現在は Composer オートロード配下なので実害は低いが、審査で見られる
- **`vendor/` の同梱**: WordPress.org は Composer を実行しないので、
  配布 ZIP に `vendor/` を含める必要がある (`.gitignore` の扱いと配布ビルドの整理)
- **国際化**: `load_plugin_textdomain()` の呼び出しと `languages/` の同梱
- **アンインストール処理**: `uninstall.php` でオプションと postmeta を掃除するか、
  「残す」方針を明記する

## 6. 公開時の作業手順 (想定)

1. スラッグ確定 → テキストドメイン一括置換
2. `readme.txt` 作成
3. バージョンを **1.0.0** に更新 (本体ファイルヘッダ + `readme.txt` の `Stable tag`)
4. `vendor/` を含む配布 ZIP を作る
5. WordPress.org にプラグイン申請 (審査は数日〜数週間)
6. 承認後、SVN リポジトリに初回コミット + タグ打ち

## 7. 関連

- セクション到達 beacon の設計・意図: AMANEA 本体の
  `docs/superpowers/specs/2026-07-17-section-dropoff-tracking-design.md`
- 自動適用の設計: AMANEA 本体の
  `docs/superpowers/specs/2026-07-03-wp-auto-apply-design.md`
