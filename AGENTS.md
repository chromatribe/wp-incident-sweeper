# WP Incident Sweeper — AIエージェント開発・運用行動規範

本ファイルは、本プロジェクト（`wp-incident-sweeper`）を担当するAIエージェント向けの行動規範・開発・リリース手順書です。作業開始前に必ず一読してください。

---

## 1. 最重要原則（ブランチ構成の厳格分離）

本プロジェクトは**ノーライセンス（No License / All rights reserved）**の方針に基づき、GitHub公開リポジトリには生ソースコードを展開せず、完成版ZIP・シグネチャ辞書・ドキュメントのみをホストします。

| ブランチ | 役割・公開範囲 | 含まれるファイル | 目的 |
|---|---|---|---|
| **`develop`** (Local) | **開発・保守の正本**<br>（手元ローカル専用） | `wp-incident-sweeper/` (PHP/JS/CSS)<br>`scripts/` (テスト・ビルド・同期)<br>`signatures.json`<br>`wp-incident-sweeper.zip`<br>各種ドキュメント | すべての実装・修正・テスト・ビルドを実行する作業ブランチ。 |
| **`main`** (Public) | **GitHub配布・シグネチャ配信**<br>（GitHubリモート origin） | `README.md`<br>`signatures.json`<br>`wp-incident-sweeper.zip`<br>`PROJECT.md`, `version.json`<br>`docs/`, `spec/` | **生ソースコード（`wp-incident-sweeper/`）やスクリプト（`scripts/`）を含めない**。プラグインのダウンロードとシグネチャ自動更新（Raw URL）を提供する。 |

> [!CAUTION]
> **生ソースコードを `main` ブランチにコミット・プッシュしてはならない。**
> 実装作業は必ず `develop` ブランチで行い、GitHub への反映は必ずリリース用スクリプト（`publish_release.sh`）を経由すること。

---

## 2. 開発・リリースワークフロー（5ステップ）

### Step 1: 現在のブランチを確認
作業開始時は必ず `develop` ブランチであることを確認する。
```bash
git branch --show-current
# => develop でない場合は git checkout develop
```

### Step 2: ソースコードの修正・機能追加
`wp-incident-sweeper/` 配下の PHP、JS、CSS を修正する。
- 🚨 **メモリ枯渇防止**: 大規模サイトでのスキャン時に `post_content` を PHP メモリに全件ロードしないこと（SQLのWHERE句による事前抽出を維持する）。
- 🛡️ **多重安全ガード**: 自身（現在のユーザー）および初期管理者（ID: 1）の保護、公開中通常記事の保護（ゴミ箱フォールバック）を崩さないこと。

### Step 3: バージョン採番（Qlypx方式）
`PROJECT.md` の `**Current Version:**` をインクリメントし、全7ファイルを同期する。
```bash
python3 scripts/check_version_sync.py
# => OK: version=2.YYYYMMDD.BUILD となること
```

### Step 4: テスト・ビルド・公開の自動実行
手動でブランチを切り替えてファイルをコピー・削除する必要はありません。
以下のワンライナーを実行すると、全工程が安全・自動で完了します：
```bash
bash scripts/publish_release.sh
```

**`publish_release.sh` が自動実行する内容:**
1. `check_version_sync.py` による全7箇所のバージョン整合性チェック
2. `test_sweeper.py` によるセキュリティ（Nonce/SQLi/XSS/CSV/権限）および Node.js モックDOMレンダリング検証
3. `build_zip.sh` による最新 `wp-incident-sweeper.zip` パッケージ生成
4. `main` ブランチへ一時切り替え、配布物（ZIP, signatures.json, ドキュメント）のみをチェックアウトしてコミット
5. `git push origin main` で GitHub へ最新配布物を反映
6. 作業ブランチ（`develop`）へ自動復帰

### Step 5: 開発日誌（DEVLOG）の記録
Rule 10-devlog に従い、作業終了時に `docs/DEVLOG.md` の先頭へエントリを1件追記する。

---

## 3. 主要ファイル一覧と役割

| ファイル / ディレクトリ | 役割 |
|---|---|
| `wp-incident-sweeper/` | プラグイン本体ソース（PHP, JS, CSS, readme.txt）。**develop のみで管理**。 |
| `signatures.json` | 脅威キーワード・不審ドメイン辞書正本。GitHub Raw URL から各サイトへ配信される。 |
| `wp-incident-sweeper.zip` | 完成版配布用ZIP。 |
| `scripts/test_sweeper.py` | 構文・セキュリティ・モック描画の総合テストスイート。 |
| `scripts/check_version_sync.py` | Qlypx方式のバージョン横断同期バリデータ。 |
| `scripts/build_zip.sh` | 不要ファイル（.DS_Store等）を除外した安全なZIPパッケージャ。 |
| `scripts/publish_release.sh` | テスト・ビルド・mainブランチ更新・GitHubプッシュの一括実行スクリプト。 |
| `PROJECT.md` | プロジェクト設計思想・運用ルールの正本（A層）。 |
| `spec/SPEC.md` | 機能仕様および DoD の正本（A層）。 |
| `docs/CHANGELOG.md` | リリースノート（A層）。 |
| `docs/DEVLOG.md` | 開発日誌（A層）。 |

---

## 4. 禁止事項（地雷チェックリスト）

- ❌ 親ディレクトリ（`自社PJ_local/` 直下）で `git` コマンドを実行しない（必ず `wp-incident-sweeper/` 内で実行する）。
- ❌ `main` ブランチに `wp-incident-sweeper/` ディレクトリを直接追加・プッシュしない。
- ❌ `check_version_sync.py` と `test_sweeper.py` の検証をパスせずにリリースしない。
- ❌ `signatures.json` の形式（JSON構文・必須キー）を壊さない（各サイトの同期処理がパニックを起こすため）。
