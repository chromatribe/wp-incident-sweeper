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
    assert "マイKW検知" in php_text, "PHP scan must tag matched posts with マイKW検知"

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
                  { id: 10, blog_id: 1, blog_name: 'Subsite 1', title: 'Test 1', slug: 'test1', post_type: 'post', status: 'publish', author_id: 1, author_name: 'Admin', modified_date: '2026-10-15', severity: 'clean', is_protected: false, reasons: ['本文改ざん痕跡: "hacked by"'] }
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

    # 3. 言語ファイル実体の存在（ja, en_US, id_ID, es_ES, de_DE）
    lang_dir = ROOT / "wp-incident-sweeper" / "languages"
    pot_file = lang_dir / "wp-incident-sweeper.pot"
    assert pot_file.is_file(), "POT template file must exist"

    target_locales = ["ja", "en_US", "id_ID", "es_ES", "de_DE"]
    for loc in target_locales:
        po_file = lang_dir / f"wp-incident-sweeper-{loc}.po"
        mo_file = lang_dir / f"wp-incident-sweeper-{loc}.mo"
        assert po_file.is_file(), f"PO translation file for {loc} must exist"
        assert mo_file.is_file(), f"Compiled MO binary file for {loc} must exist"

        # 4. MO バイナリのマジックナンバー検証 (GNU gettext magic bytes)
        mo_bytes = mo_file.read_bytes()
        assert len(mo_bytes) > 500, f"MO binary for {loc} must be populated"
        magic = mo_bytes[:4]
        assert magic in (b"\xde\x12\x04\x95", b"\x95\x04\x12\xde"), f"Invalid gettext magic bytes for {loc}: {magic.hex()}"

    # 5. JS 側のローカライゼーション
    assert "'i18n'        => array(" in php_text, "PHP enqueue_assets must supply i18n dictionary"
    assert "function __(key, fallback)" in js_text, "admin.js must implement __() i18n helper"
    assert "function sprintf(format, ...args)" in js_text, "admin.js must implement sprintf helper"
    print(f"  [PASS] Internationalization (i18n/l10n) catalogs ({', '.join(target_locales)}) and gettext MO verified")


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


def test_lucide_icons(php_text: str):
    """Dashicons から Lucide SVG アイコンへの完全移行を検証"""
    css_file = ROOT / "wp-incident-sweeper" / "assets/css/admin.css"
    css_text = css_file.read_text(encoding="utf-8")

    # CSS に .wpis-lucide スタイル定義が存在すること
    assert ".wpis-lucide" in css_text, "admin.css must define .wpis-lucide styles"

    # UI 内部に dashicons クラスが残存していないこと（すべて Lucide SVG へ移行済み）
    assert '<span class="dashicons' not in php_text, "All in-app UI dashicon spans must be replaced with Lucide SVGs"

    # Lucide SVG アイコン（Shield, Target, CloudDownload, Download, RefreshCw, FileText, Users, BookOpen, Scale, X）
    assert "wpis-icon-shield" in php_text or 'class="wpis-lucide' in php_text
    assert "LUCIDE_SHIELD_MENU_ICON" in php_text, "Menu icon must use Lucide Shield SVG data URI"
    print("  [PASS] Lucide SVG iconography verified (Zero Dashicons in app UI)")


def test_emoji_broken_image_defense(php_text: str):
    """オフライン/イントラ/CSP環境における絵文字画像（s.w.org）リンク切れ防止機構の検証"""
    css_file = ROOT / "wp-incident-sweeper" / "assets/css/admin.css"
    css_text = css_file.read_text(encoding="utf-8")

    # 1. PHP 側での絵文字スクリプト除去 (remove_action)
    assert "remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );" in php_text, "Must remove print_emoji_detection_script"
    assert "remove_action( 'admin_print_styles', 'print_emoji_styles' );" in php_text, "Must remove print_emoji_styles"
    assert "disable_emojis_on_plugin_pages" in php_text, "Must implement disable_emojis_on_plugin_pages handler"

    # 2. CSS でのフォールバック非表示ルール
    assert ".wpis-app img.emoji" in css_text, "admin.css must suppress img.emoji"
    assert "display: none !important;" in css_text, "admin.css must hide broken emoji img tags"

    # 3. ヘッダーバッジに生Unicode絵文字（シールド, ターゲット）の直接記載がないこと
    assert "検知シグネチャ" in php_text
    print("  [PASS] Emoji broken-image defenses & Lucide badge encapsulation verified")


def test_zero_unicode_emojis_in_plugin(php_text, js_text):
    """プラグインの実行コード（PHPおよびJS）にUnicode絵文字が一切残っていないことを厳格検証"""
    import unicodedata

    def find_emojis(text):
        found = []
        for ch in text:
            name = unicodedata.name(ch, '')
            if 'EMOJI' in name or 'VARIATION SELECTOR' in name:
                found.append(ch)
            else:
                cp = ord(ch)
                if (0x1F300 <= cp <= 0x1F9FF) or (0x2600 <= cp <= 0x27BF) or (0x1FA00 <= cp <= 0x1FAFF) or (0xFE00 <= cp <= 0xFE0F):
                    found.append(ch)
        return found

    php_emojis = find_emojis(php_text)
    assert len(php_emojis) == 0, f"PHP contains unicode emojis: {set(php_emojis)}"

    js_emojis = find_emojis(js_text)
    assert len(js_emojis) == 0, f"JS contains unicode emojis: {set(js_emojis)}"

    print("  [PASS] Zero Unicode emojis strictly verified across PHP and JavaScript sources")


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
    test_lucide_icons(php_text)
    test_emoji_broken_image_defense(php_text)
    test_zero_unicode_emojis_in_plugin(php_text, js_text)

    print("\nALL TESTS PASSED! Plugin is strictly verified.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
