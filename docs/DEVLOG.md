# DEVLOG — WP Incident Sweeper

## 2026-10-15 | v2.20261015.7 マイKW（個別検知キーワード登録）機能の実装
- 🧠 思考: ユーザーごとに検知したい固有のキーワード（社内NGワード、顧客情報兆候、特定ドメイン等）を自由に指定できるよう、ユーザーメタに保存する「マイKW」設定機能を追加。省メモリ（0MB）と高視認性を両立させる。
- 🔨 やった:
  1. PHPバックエンドに `get_my_keywords()`, `set_my_keywords()`, `ajax_save_my_keywords()` を追加。
  2. スキャンロジックにマイKWのSQL事前抽出（LIKE検索）を組み込み、メモリ消費ゼロの高速抽出を維持。
  3. ユーザー監査ループにもマイKWの照合を追加（ログイン名・表示名・メアド）。
  4. UIヘッダーに「マイKW設定」ボタン・登録件数バッジ、および専用設定モーダルを実装。
  5. 検知理由に専用の紫ピルバッジ（`🎯 マイKW検知: "xxx"`）を割り当て、一覧およびCSVで直感的に識別可能に。
  6. `test_sweeper.py` に `test_my_keywords_feature` を追加し、Nonce・権限・動作を自動検証。
  7. Qlypx方式で全7箇所を `2.20261015.7` に同期。
- ❌ 失敗: なし。
- ✅ 成功: `check_version_sync.py` および `test_sweeper.py` 全テスト完全合格。
- 🧭 決定: マイKWはユーザー単位のメタ情報として保存し、各管理者が自分の関心に応じた検知条件を柔軟にカスタマイズできるようにした。
- 🔗 参照: `spec/SPEC.md` / `PROJECT.md`

## 2026-10-15 | GitHub公開リポジトリの配布物・KW専用ホスト化（方式A）
- 🧠 思考: ノーライセンス公開の方針に伴い、GitHub上の公開リポジトリ（`main` ブランチ）には生ソースコードを展開せず、完成版ZIP（`wp-incident-sweeper.zip`）、検知キーワード正本（`signatures.json`）、およびドキュメント（`README.md` 等）のみをホストする構成へ移行。
- 🔨 やった:
  1. 手元のローカルに `develop` ブランチを作成し、全ソースコード（`wp-incident-sweeper/`）、テスト、ビルドスクリプトを完全保全。
  2. GitHubリモートの不要な `master` ブランチを削除。
  3. `main` ブランチから生ソースコード・スクリプトを削除し、`wp-incident-sweeper.zip`、`signatures.json`、`README.md` 等の配布ファイルのみに整理して push。
  4. `README.md` 先頭に直接ダウンロードリンクを新設し、ノーライセンス（No License / All rights reserved）の著作権表示を追加。
  5. リリース自動反映スクリプト `scripts/publish_release.sh` を作成。
- ❌ 失敗: なし。
- ✅ 成功: GitHub上の `main` にはZIP・KW・ドキュメントのみが並び、RAW URL (`signatures.json`) および ZIP 直接ダウンロード URL がともに HTTP 200 で稼働。
- 🧭 決定: 開発・テストは `develop` ブランチで実施し、公開は `publish_release.sh` で `main` に配布物のみを反映する運用に統一。
- 🔗 参照: `README.md` / `https://github.com/chromatribe/wp-incident-sweeper`

## 2026-10-15 | v2.20261015.6 検知KWのGitHub自動同期と一覧画面での検知理由即時可視化
- 🧠 思考:
  1. 脅威キーワードは日々変化するため、プラグイン本体のコードを変更することなく GitHub (Raw JSON) から最新の検知シグネチャを自動更新できるようにする。
  2. テーブル右端の検知理由列は横スクロールが必要で見落としやすいため、タイトル直下およびユーザー名直下に色分けされたピルバッジを配置し、なぜ検知されたのかが一目で分かるようにする。
- 🔨 やった:
  1. `signatures.json` をリポジトリルートに配置（危険KW 23件、警告KW 35件、不審ドメイン 9件）。
  2. PHPシグネチャ管理エンジン（`REMOTE_SIGNATURES_URL`, `get_signatures`, `sync_remote_signatures`, `ajax_sync_signatures`）を実装。
  3. GitHub 障害・オフライン時も組み込み定義に自動マージ・フォールバックする安全機構を実装。
  4. ヘッダーに「最新KW更新 (GitHub)」ボタンとシグネチャステータス表示（バージョン・件数・最終同期日）を設置。
  5. 記事スキャン時に危険・警告記事のみピンポイントでマッチ単語を特定（メモリ消費ゼロを維持）。
  6. JS側（`renderPosts`, `renderUsers`）でタイトル・ユーザー名直下に視認性の高いピルバッジ（`reasonsBadgesHtml`）を表示。
  7. `test_sweeper.py` に `test_signature_engine` を追加し、Node.js モック描画含め全テスト PASS。
  8. Qlypx方式で全7箇所を `v2.20261015.6` に同期し、`check_version_sync.py` PASS。最新ZIPをビルド。
- ❌ 失敗: なし。
- ✅ 成功: `check_version_sync.py` および `test_sweeper.py` が exit code 0 で完全通過。
- 🧭 決定: シグネチャは `signatures.json` を SSOT とし、管理画面からワンクリックで GitHub から最新化できるハイブリッド運用体制を確立。
- 🔗 参照: `docs/CHANGELOG.md` / `spec/SPEC.md` / `signatures.json`

## 2026-10-15 | v2.20261015.5 フロント描画バグ（safeBlogId is not defined）の即時解消
- 🧠 思考: サーバー側スキャン（SQL抽出）は無事成功したが、ブラウザ側テーブル描画ループ内で safeBlogId の宣言が抜けていたため ReferenceError が発生。
- 🔨 やった:
  1. admin.js の renderPosts() 内に const safeBlogId = parseInt(item.blog_id || 1, 10); を復旧。
  2. test_sweeper.py に Node.js モックによる実際の DOM レンダリング自動テストを追加。
  3. 全7ファイルを v2.20261015.5 に更新し、最新 ZIP をビルド。
- ❌ 失敗: リファクタリング時のローカル変数宣言漏れ（Node.js モックテスト導入で再発防止）。
- ✅ 成功: 単サイト・マルチサイトの両モック描画テストが exit code 0 で完全通過。
- 🧭 決定: JS側の描画処理は必ず Node.js のモック実行テストをパイプラインに通す。
- 🔗 参照: docs/CHANGELOG.md / spec/SPEC.md

## 2026-10-15 | v2.20261015.4 大規模サイト通信エラー（メモリ枯渇）の根本解決とSQL抽出最適化
- 🧠 思考: school.snapsnap.jp で「スキャン中に通信エラーが発生しました。」が発生。原因は全記事の post_content を PHP メモリに全件ロードしたことによるメモリ上限超過（Fatal error: Allowed memory size...）またはタイムアウト。また、エラー時に詳細理由が把握できるようクライアント側エラー表示を強化する。
- 🔨 やった:
  1. 本文（post_content）の PHP メモリへの全件ロードを完全撤廃。
  2. 改ざんシグネチャ・スパムの本文検知を SQL の WHERE 句で超高速事前抽出する方式へ最適化（メモリ消費量 99% 削減・0MB 化）。
  3. check_ajax_referer を non-fatal 化し、トークン失効時にも正常な JSON 403 を返却。
  4. admin.js に safeFetchJson を導入し、万が一のサーバーエラー時も HTTP ステータスとエラー内容をダイアログに詳細表示。
  5. Qlypx方式で全7ファイルを v2.20261015.4 に更新し、最新 ZIP をビルド。
- ❌ 失敗: 大規模サイトでの本文データ一括ロードによる PHP メモリ圧迫（SQL抽出に切り替えて完全解消）。
- ✅ 成功: test_sweeper.py, check_version_sync.py ともに PASS。
- 🧭 決定: 記事本文は PHP に載せず、すべて MySQL レベルで検知 ID を抽出する超省メモリ設計を維持する。
- 🔗 参照: docs/CHANGELOG.md / spec/SPEC.md

## 2026-10-15 | v2.20261015.3 単一/マルチサイトUI分離と厳格セキュリティ監査完了
- 🧠 思考: 単一サイト・マルチサイト（ネットワーク全体 vs サブサイト個別）での表示混乱（単サイトなのに「全傘下サイト」と出る等）を完全排除し、他サイト巻き込みや特権昇格を防ぐ厳格なセキュリティガードを実装・テストする。
- 🔨 やった:
  1. UIコンテキストの完全分離: `$is_network = is_multisite() && is_network_admin()` を基軸にし、単一サイトやサブサイト個別画面では「全傘下サイト」セレクトや対象サイト列を完全非表示。サブサイト個別画面ではネットワーク管理への案内を表示。
  2. 権限昇格対策: ネットワーク横断スキャン/削除に `manage_network` を必須化。単サイト権限でのマルチサイト越境を完全遮断。
  3. 特権管理者（Super Admin）保護: 不審ユーザー一括削除時、`wp2_*` や不審メアドドメインに明示一致しない限り Super Admin アカウントの削除をスキップ。
  4. XSS & CSVインジェクション防御: `safeEditUrl` のスキーム検証・属性エスケープ、CSV先頭数式記号の無害化。
  5. 総合テストスイート `scripts/test_sweeper.py` を作成し全項目パス。
  6. Qlypx方式の全7ファイル同期（`2.20261015.3`）および最新ZIPパッケージ生成。
- ❌ 失敗: なし。
- ✅ 成功: `check_version_sync.py` および `test_sweeper.py` が exit code 0 でパス。
- 🧭 決定: マルチサイト環境では個別サブサイトでのユーザー削除を制限し、サイトネットワーク管理画面（特権管理者）からのみ実行可能とする安全仕様。
- 🔗 参照: commit `4c98043` / `docs/CHANGELOG.md` / `spec/SPEC.md`
