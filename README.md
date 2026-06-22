# AMANE Blog Distribution — WordPress Plugin

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](LICENSE)

公式 WordPress プラグイン for the [AMANE Blog Distribution API](https://amane.app).

AMANE が AI で自動生成した SEO 記事を、WordPress サイトに自動配信できる公式プラグインです。

## 特徴

- 🤖 AMANE で生成された記事を WordPress に自動 pull
- 📝 下書き状態で投入 (= 編集者がレビュー)
- ✅ 編集者が「公開」を押すと AMANE に自動報告
- 📊 効果計測 (= 公開後 14 日の GSC 順位/clicks/impressions 推移) を AMANE 側で自動計測
- 🎯 AMANE 提案トピックの承認 / 却下を WP 管理画面から操作

## 必要要件

- WordPress 6.0+
- PHP 8.1+
- AMANE Blog Distribution Pack 契約 (= Small / Large / Custom のいずれか)

## インストール

### 方法 1: WordPress.org Plugin Directory (= 公開後)

```
WordPress 管理画面 → プラグイン → 新規追加 → 「AMANE Blog Distribution」を検索 → インストール
```

### 方法 2: 手動インストール (= 現在の方法)

1. このリポジトリの最新リリース zip をダウンロード
2. WordPress 管理画面 → プラグイン → 新規追加 → プラグインのアップロード → zip を選択
3. 有効化

## 初期設定

1. 有効化後、サイドメニュー「AMANE」が出現
2. AMANE 管理画面 (https://service.amane.app) で API トークンを発行
3. WP 管理画面 → AMANE → 設定 で:
   - API URL: `https://service.amane.app`
   - API Token: `amb_xxxxxxxxxxxxx`
4. 「接続テスト」ボタンで疎通確認

## 使い方

### 記事の取り込み

1. WP 管理画面 → AMANE → 記事取込
2. 「AMANE 配信記事を取得」ボタン押下
3. AMANE から配信可能な記事が WordPress 下書きとして投入される
4. 通常の「投稿」管理画面で内容をレビュー / 編集
5. 公開ボタンを押すと AMANE に自動で公開報告

### トピック提案の承認

1. WP 管理画面 → AMANE → トピック提案
2. AMANE が GSC / 競合分析から提案したトピックを一覧表示
3. 承認 → AMANE 側で生成キューに投入
4. 却下 → 再提案を抑制

### 効果計測の確認

1. AMANE 配信記事の投稿編集画面に「AMANE 効果計測」メタボックス
2. 公開後 14 日経過するとデータ表示:
   - 公開前 28 日 vs 公開後 14 日の順位推移
   - clicks / impressions の変化
   - 判定 (= improved / neutral / declined)

## API トークンの発行

1. AMANE 管理画面 (https://service.amane.app) にログイン
2. Site 詳細 → 「📝 ブログ配信」タブ
3. API トークン発行 (= 表示は 1 度だけ、コピーしておく)

トークンの形式: `amb_` プレフィックス + 48 桁の hex

## 関連リンク

- [API 仕様 (OpenAPI 3.0)](https://service.amane.app/api/v1/docs)
- [PHP SDK](https://github.com/amane-app/blog-sdk-php) (= 内部利用)
- [JavaScript/TypeScript SDK](https://github.com/amane-app/blog-sdk-js)
- [プロダクトサイト](https://amane.app)

## ライセンス

GPL v2 or later — see [LICENSE](LICENSE)

WordPress 公式のライセンス要件 (= GPL v2+ 必須) に準拠しています。

Copyright (c) 2026 Transonic Software Corporation
