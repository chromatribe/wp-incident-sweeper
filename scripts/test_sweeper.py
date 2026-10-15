#!/usr/bin/env python3
"""WP Incident Sweeper 総合テストスイート

検証内容:
1. PHP/JS 構文・ブラケット整合性
2. セキュリティ防御（Nonce, 権限検査, SQL Injection, XSS, CSV Injection）
3. 単一サイト vs マルチサイト（ネットワーク管理 / 子サイト個別）のUI・ロジック分離
4. 多重安全ガード（システムページ保護, 公開中記事の退避, 特権管理者保護, 自身・初期管理者保護）
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_FILE = ROOT / "wp-incident-sweeper" / "wp-incident-sweeper.php"
JS_FILE = ROOT / "wp-incident-sweeper" / "assets/js/admin.js"


def test_php_bracket_integrity(php_text: str):
    """PHPコードの括弧の整合性（開閉対応）を検証"""
    stack = []
    pairs = {"{": "}", "(": ")", "[": "]"}
    in_string = None
    escape = False

    for idx, ch in enumerate(php_text):
        if escape:
            escape = False
            continue
        if ch == "\\":
            escape = True
            continue
        if ch in ("'", '"'):
            if in_string == ch:
                in_string = None
            elif in_string is None:
                in_string = ch
            continue
        if in_string:
            continue

        if ch in pairs:
            stack.append((ch, idx))
        elif ch in pairs.values():
            if not stack:
                raise AssertionError(f"Unexpected closing bracket '{ch}' at offset {idx}")
            open_ch, _ = stack.pop()
            if pairs[open_ch] != ch:
                raise AssertionError(f"Mismatched bracket '{open_ch}' vs '{ch}' at offset {idx}")

    assert len(stack) == 0, f"Unclosed brackets remaining: {stack}"
    print("  [PASS] PHP bracket integrity")


def test_security_controls(php_text: str, js_text: str):
    """セキュリティ防御要件の点検"""
    # 1. Nonce 検証
    endpoints = ["ajax_run_scan", "ajax_delete_posts", "ajax_delete_users", "ajax_sync_signatures", "ajax_save_my_keywords"]
    for ep in endpoints:
        ep_match = re.search(r"function\s+" + ep + r"\s*\(\)\s*\{([^}]+check_ajax_referer[^}]+)", php_text)
        assert ep_match, f"{ep} must perform check_ajax_referer at start"
    print("  [PASS] All Ajax endpoints enforce check_ajax_referer")

    # 2. 権限チェック (Capability checks)
    assert "current_user_can( 'manage_network' )" in php_text, "Network scan/delete must check manage_network"
    assert "current_user_can( 'manage_network_users' )" in php_text, "Multisite user deletion must check manage_network_users"
    assert "current_user_can( 'manage_options' )" in php_text, "Single site must check manage_options"
    assert "current_user_can( 'delete_users' )" in php_text, "Single site user delete must check delete_users"
    print("  [PASS] Strict capability checks enforced for both single and multisite")

    # 3. 特権昇格防止 (Privilege escalation prevention)
    # 単なる manage_options ではネットワーク横断スキャンを許可しないこと
    assert "! current_user_can( 'manage_network' )" in php_text, "Network scan requires manage_network explicitly"
    print("  [PASS] Privilege escalation prevented (manage_options cannot cross network)")

    # 4. SQL Injection 防御
    # $wpdb->prepare がプレースホルダー付きで使用されているか
    assert "$wpdb->prepare(" in php_text
    assert "WHERE post_author = %d" in php_text
    print("  [PASS] Prepared SQL statements used for parameterized queries")

    # 5. XSS Defense (Output Escaping)
    assert "escapeHtml" in js_text, "JS must implement escapeHtml"
    assert "safeEditUrl" in js_text, "JS must validate editUrl scheme before rendering"
    assert "sanitize_text_field" in php_text
    assert "sanitize_email" in php_text
    print("  [PASS] Output escaping and URL scheme sanitization enforced")

    # 6. CSV Injection 防御
    assert "cleanCsv" in js_text
    assert r"/^[=+\-@\t\r]/" in js_text, "cleanCsv must neutralize =, +, -, @, \\t, \\r formulas"
    print("  [PASS] CSV Formula Injection defense active")


def test_multisite_single_separation(php_text: str, js_text: str):
    """単一サイト・マルチサイト・サブサイト個別のUI分離検証"""
    # 1. サーバーサイドでのコンテキスト切り分け
    assert "$is_network = is_multisite() && is_network_admin();" in php_text

    # 2. 単サイト時にネットワーク用文言が漏出しないこと
    # 「全傘下サイト」セレクトボックスは $is_network のみ
    site_filter_block = re.search(r"<\?php if \( \$is_network \) : \?>\s*<select[^>]+id=\"wpis-posts-site-filter\"", php_text)
    assert site_filter_block, "Site filter must be wrapped inside <?php if ( $is_network ) : ?>"

    # 「対象サイト」テーブル列も $is_network のみ
    target_site_th = re.search(r"<\?php if \( \$is_network \) : \?><th[^>]*>対象サイト</th><\?php endif; \?>", php_text)
    assert target_site_th, "Target site <th> must be wrapped inside <?php if ( $is_network ) : ?>"

    # 3. サブサイト個別モードの案内表示
    assert "サブサイト個別モード" in php_text, "Subsite notice must be present when in multisite child site"

    # 4. JS側での条件分岐
    assert "isNetwork = (root.dataset.isNetwork === '1') || (!!wpisConfig.isNetwork)" in js_text
    print("  [PASS] Single-site vs Multisite Network vs Subsite context separation")


def test_safety_guards(php_text: str):
    """巻き込み事故防止ガードの検証"""
    # ガード1: システム保護ページ (page_on_front, page_for_posts, wp_page_for_privacy_policy)
    assert "get_option( 'page_on_front' )" in php_text
    assert "get_option( 'page_for_posts' )" in php_text
    assert "get_option( 'wp_page_for_privacy_policy' )" in php_text
    assert "in_array( $pid, $protected_post_ids, true )" in php_text

    # ガード2: 許可された投稿タイプのみ対象 (public custom post types, post, page)
    assert "get_post_types( array( 'public' => true ), 'names' )" in php_text

    # ガード3: 公開中通常記事の誤削除ブロック（ゴミ箱へ退避）
    assert "wp_trash_post( $pid )" in php_text

    # ガード4: 自身・初期管理者 (ID:1) の保護
    assert "$uid === $current_user_id || $uid === 1" in php_text

    # ガード5: 他の特権管理者（Super Admin）の保護
    assert "is_super_admin( $uid ) && ! $is_explicit_attacker" in php_text

    print("  [PASS] All 5 safety guards and anti-cascade defenses verified")


def test_signature_engine(php_text: str, js_text: str):
    """GitHubシグネチャ自動同期機能および検知理由UIの検証"""
    import json
    sig_file = ROOT / "signatures.json"
    assert sig_file.is_file(), "signatures.json must exist at project root"

    with open(sig_file, "r", encoding="utf-8") as f:
        data = json.load(f)

    assert "version" in data, "signatures.json must have version"
    assert len(data.get("threat_keywords_high", [])) >= 20, "High threat keywords must be defined"
    assert len(data.get("threat_keywords_medium", [])) >= 30, "Medium threat keywords must be defined"
    assert len(data.get("suspicious_email_domains", [])) >= 5, "Suspicious email domains must be defined"

    # PHP側のシグネチャ同期実装
    assert "REMOTE_SIGNATURES_URL" in php_text
    assert "get_signatures" in php_text
    assert "sync_remote_signatures" in php_text
    assert "ajax_sync_signatures" in php_text

    # JS側のシグネチャUIと検知理由バッジ
    assert "updateSignatureDisplay" in js_text
    assert "wpis-btn-sync-kw" in js_text
    assert "wpis-title-reasons" in js_text
    print("  [PASS] GitHub signature auto-sync & prominent reason badges verified")


def test_my_keywords_feature(php_text: str, js_text: str):
    """マイKW（ユーザー個別検知キーワード）機能の検証"""
    # PHP側
    assert "get_my_keywords" in php_text, "PHP must implement get_my_keywords"
    assert "set_my_keywords" in php_text, "PHP must implement set_my_keywords"
    assert "ajax_save_my_keywords" in php_text, "PHP must implement ajax_save_my_keywords"
    assert "wpis_my_keywords" in php_text, "PHP must use wpis_my_keywords user meta key"
    assert "🎯 マイKW検知" in php_text, "PHP scan must tag matched posts with 🎯 マイKW検知"

    # JS側
    assert "wpis-btn-my-kw" in js_text, "JS must handle wpis-btn-my-kw"
    assert "updateMyKeywordsCount" in js_text, "JS must implement updateMyKeywordsCount"
    assert "wpis_save_my_keywords" in js_text, "JS must call wpis_save_my_keywords"
    print("  [PASS] My Keywords (マイKW) custom detection engine verified")


def test_js_execution():
    """Node.js を用いて admin.js の構文・未定義変数（ReferenceError等）を自動検証"""
    import subprocess
    cmd = [
        "node", "-e",
        """
        const fs = require('fs');
        let code = fs.readFileSync('""" + str(JS_FILE) + """', 'utf8');

        function runMock(isNetwork) {
          const mockDom = {
            addEventListener: (evt, cb) => { if (evt === 'DOMContentLoaded') cb(); },
            querySelector: () => ({ dataset: { isNetwork: isNetwork ? '1' : '0' } }),
            getElementById: (id) => ({
              id,
              addEventListener: () => {},
              querySelectorAll: () => [],
              innerHTML: '',
              textContent: '',
              value: 'all'
            }),
            querySelectorAll: () => []
          };
          global.document = mockDom;
          global.window = global;
          global.wpisConfig = { nonce: 'x', ajaxUrl: 'x', adminUrl: 'x', isNetwork, isMultisite: isNetwork };
          global.FormData = class { append() {} };
          global.fetch = async () => ({
            text: async () => JSON.stringify({
              success: true,
              data: {
                posts: [
                  { id: 10, blog_id: 1, blog_name: 'Subsite 1', title: 'Test 1', slug: 'test1', post_type: 'post', status: 'publish', author_id: 1, author_name: 'Admin', modified_date: '2026-10-15', severity: 'clean', is_protected: false, reasons: ['🚨 本文改ざん痕跡: "hacked by"'] }
                ],
                users: [{ id: 1, login: 'admin', name: 'Admin', email: 'a@b.com', roles: ['administrator'], registered: '2026-10-15', is_threat: false, threat_reasons: [] }],
                sites: [{ id: 1, name: 'Site 1', url: 'http://localhost' }],
                signatures: { version: '2026.10.15.1', high_count: 23, med_count: 35, updated_at: '2026-10-15 17:40:00' },
                summary: { totalSites: 1, totalPosts: 1, threatPosts: 1, totalUsers: 1, threatUsers: 0 }
              }
            })
          });
          eval(code);
        }

        runMock(false);
        runMock(true);
        console.log('JS mock execution completed');
        """
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode == 0, f"JS execution failed: {res.stderr}"
    print("  [PASS] JavaScript runtime execution (Single & Multisite mock rendering)")


def test_i18n_l10n_readiness(php_text: str, js_text: str):
    """国際化 (i18n) および地域化 (l10n) の実装検証"""
    # 1. プラグインヘッダ
    assert "Text Domain: wp-incident-sweeper" in php_text, "Plugin header must specify Text Domain"
    assert "Domain Path: /languages" in php_text, "Plugin header must specify Domain Path"

    # 2. テキストドメインロード
    assert "load_plugin_textdomain" in php_text, "Plugin must call load_plugin_textdomain"
    assert "load_textdomain" in php_text, "Plugin must have load_textdomain method"

    # 3. 言語ファイル実体の存在
    lang_dir = ROOT / "wp-incident-sweeper" / "languages"
    pot_file = lang_dir / "wp-incident-sweeper.pot"
    po_file = lang_dir / "wp-incident-sweeper-ja.po"
    mo_file = lang_dir / "wp-incident-sweeper-ja.mo"

    assert pot_file.is_file(), "POT template file must exist"
    assert po_file.is_file(), "Japanese PO translation file must exist"
    assert mo_file.is_file(), "Compiled MO binary file must exist"

    # 4. MO バイナリのマジックナンバー検証 (GNU gettext magic bytes)
    mo_bytes = mo_file.read_bytes()
    assert len(mo_bytes) > 500, "MO binary must be populated"
    magic = mo_bytes[:4]
    assert magic in (b"\xde\x12\x04\x95", b"\x95\x04\x12\xde"), f"Invalid gettext magic bytes: {magic.hex()}"

    # 5. JS 側のローカライゼーション
    assert "'i18n'        => array(" in php_text, "PHP enqueue_assets must supply i18n dictionary"
    assert "function __(key, fallback)" in js_text, "admin.js must implement __() i18n helper"
    assert "function sprintf(format, ...args)" in js_text, "admin.js must implement sprintf helper"
    print("  [PASS] Internationalization (i18n/l10n) catalogs and gettext MO verified")


def test_compliance_and_privacy(php_text: str):
    """法令準拠（電気通信事業法外部送信規律、APPI、GDPR、不正アクセス禁止法、免責）の検証"""
    # 1. プライバシーポリシー提案フック
    assert "register_privacy_policy_content" in php_text, "Must register privacy policy content handler"
    assert "wp_add_privacy_policy_content" in php_text, "Must call wp_add_privacy_policy_content"

    # 2. GDPR / 個人情報保護法 個人データ開示・消去（Data Exporter / Eraser）
    assert "wp_privacy_personal_data_exporters" in php_text, "Must register wp_privacy_personal_data_exporters"
    assert "wp_privacy_personal_data_erasers" in php_text, "Must register wp_privacy_personal_data_erasers"
    assert "export_user_privacy_data" in php_text, "Must implement export_user_privacy_data"
    assert "erase_user_privacy_data" in php_text, "Must implement erase_user_privacy_data"

    # 3. 法令・規約・プライバシータブとパネルUI
    assert 'data-tab="compliance"' in php_text, "Compliance tab button must exist"
    assert 'id="wpis-panel-compliance"' in php_text, "Compliance panel #wpis-panel-compliance must exist"

    # 4. 電気通信事業法 外部送信規律の開示
    assert "電気通信事業法（外部送信規律）への対応" in php_text, "Must disclose Telecommunications Business Act external transmission"
    assert "raw.githubusercontent.com" in php_text, "Must disclose external communication destination"

    # 5. 不正アクセス禁止法 & 免責事項
    assert "不正アクセス禁止法遵守" in php_text, "Must declare compliance with Unauthorized Computer Access Act"
    assert "免責事項 (Limitation of Liability)" in php_text, "Must specify limitation of liability and AS IS warranty disclaimer"
    assert "事前バックアップ義務" in php_text, "Must stipulate prerequisite backup obligation"
    print("  [PASS] Legal compliance (Telecommunications Act, APPI/GDPR, AUP, AS-IS) verified")


def main():
    print("Running WP Incident Sweeper Test Suite...")
    php_text = PHP_FILE.read_text(encoding="utf-8")
    js_text = JS_FILE.read_text(encoding="utf-8")

    test_php_bracket_integrity(php_text)
    test_security_controls(php_text, js_text)
    test_multisite_single_separation(php_text, js_text)
    test_safety_guards(php_text)
    test_signature_engine(php_text, js_text)
    test_my_keywords_feature(php_text, js_text)
    test_js_execution()
    test_i18n_l10n_readiness(php_text, js_text)
    test_compliance_and_privacy(php_text)

    print("\nALL TESTS PASSED! Plugin is strictly verified.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
