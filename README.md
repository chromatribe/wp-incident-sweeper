# WP Incident Sweeper

> **Emergency incident response, mass defacement auditing, and safe one-click cleanup plugin for WordPress (Single & Multisite).**  
> WordPress サイト内の全投稿・固定ページ・ユーザーを一括スキャンし、改ざん痕跡・不正侵入を安全に特定・駆除する緊急セキュリティ監査ツール。

**Version:** `2.20261015.6`  
📥 **[Download Latest Plugin (`wp-incident-sweeper.zip`)](https://github.com/chromatribe/wp-incident-sweeper/raw/main/wp-incident-sweeper.zip)**

---

## 🌟 Key Features / 主な機能

1. **Full Multisite & Single-Site Support / マルチサイト完全対応**
   - Automatically adapts between standard Single-site and Network Administration (`/wp-admin/network/`).
   - Cross-scans all child sub-sites with site-level filtering and direct edit links.
   - *（単一サイトおよびサイトネットワーク管理の両方に対応し、全傘下サイトを横断クロール）*

2. **Automated Signature Synchronization via GitHub / シグネチャ自動同期**
   - Fetches the latest threat signatures (`signatures.json`) directly from GitHub with 1-click or automated daily cache.
   - Zero-disruption: safely merges with and falls back to built-in keywords if offline.
   - *（最新の脅威KW・不審ドメイン辞書を GitHub からワンクリックで取得・更新）*

3. **Prominent Reason Badges / 検知理由の即時可視化**
   - Instant visual identification tags (`🚨 Defacement: "hacked by"`, `⚠️ SEO Spam: "casino"`) rendered directly below titles and usernames without horizontal scrolling.
   - *（横スクロール不要で、タイトル直下の色分けピルバッジにより検知理由を一目で特定）*

4. **Zero-Memory Ultra-Fast Scanning / 省メモリ・超高速スキャン**
   - Stream-extracts threat matches directly at MySQL level via optimized SQL queries without loading huge `post_content` into PHP memory.
   - Zero PHP memory crashes (no HTTP 500 / 504 timeouts) even on huge enterprise databases.
   - *（SQL事前抽出によりメモリ消費量 0MB を実現し、大規模サイトでも安定動作）*

5. **Multi-Layered Safeguards / 巻き込み事故防止ガード**
   - System-critical page protection (homepage, posts page, privacy policy).
   - Prevents accidental hard-deletion of legitimate published articles (safely fallbacks to trash).
   - Safe user deletion with post reassignment (`wp_delete_user($uid, $reassign)`).
   - Hard lock on current admin account and Master Admin (ID: 1).
   - *（システム重要ページ、公開中記事、初期特権管理者を多重ガードで完全保護）*

6. **Audit Evidence CSV Export / エビデンスCSVエクスポート**
   - One-click export of complete scan results in UTF-8 BOM CSV format, protected against CSV Formula Injection.
   - *（監査報告書用CSVダウンロード機能。数式インジェクション対策済み）*

7. **Zero-Footprint Clean Removal / ゼロ・フットプリント**
   - No external CDN dependencies, no custom database tables or leftover options. Clean removal on deactivation.
   - *（データベースにゴミを残さず、巡回完了後は停止・削除するだけで完全撤去可能）*

---

## 🚀 Getting Started / 使い方

### 1. Installation / インストール
- Download [**`wp-incident-sweeper.zip`**](https://github.com/chromatribe/wp-incident-sweeper/raw/main/wp-incident-sweeper.zip).
- In WordPress Admin: Go to `Plugins > Add New > Upload Plugin` and activate.
- For Multisite: Go to `Network Admin > Plugins > Add New > Upload Plugin` and click **Network Activate**.
- *（本リポジトリから ZIP をダウンロードし、プラグイン新規追加からアップロードして有効化）*

### 2. Run Scan / スキャン実行
- Click **"巡回スイーパー" (Incident Sweeper)** with the shield icon in the admin sidebar.
- Crawling begins automatically across all content types and users.
- *（左メニューの「巡回スイーパー」を開くと、全投稿・ユーザーの自動スキャンが開始されます）*

### 3. Review & Remediate / 確認と駆除
- Flagged threats are highlighted in red with prominent reason badges.
- Click **"🚨 危険項目を一括選択" (Select All Threats)** to bulk delete or move to trash.
- Switch to the **"ユーザー監査" (User Audit)** tab to review and purge suspicious accounts.
- *（赤くハイライトされた不審項目を一括選択し、安全に完全削除またはゴミ箱退避を実行）*

### 4. Export Report / レポート出力
- Click **"結果をCSV出力" (Export CSV)** in the top right to save an audit log for incident reporting.
- *（右上のボタンから調査結果のCSVレポートをダウンロード）*

### 5. Cleanup / 完了後の撤去
- Once the incident audit and remediation is complete, simply deactivate and delete the plugin from the Plugins screen.
- *（対応完了後は、プラグイン一覧から安全に停止・削除してください）*

---

## 📄 License / ライセンス

**No License / All Rights Reserved.**  
This repository and its distribution packages are proprietary software. Unauthorized copying, modification, distribution, or commercial reuse is strictly prohibited.  
Copyright © CHROMATRIBE. All rights reserved.

*本リポジトリおよび配布ファイルは**ノーライセンス（No License / All rights reserved）**です。ソースコードおよび成果物の無断複製・再配布・商用転載を禁じます。著作権は CHROMATRIBE に帰属します。*
