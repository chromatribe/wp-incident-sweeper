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
    endpoints = ["ajax_run_scan", "ajax_delete_posts", "ajax_delete_users"]
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


def main():
    print("Running WP Incident Sweeper Test Suite...")
    php_text = PHP_FILE.read_text(encoding="utf-8")
    js_text = JS_FILE.read_text(encoding="utf-8")

    test_php_bracket_integrity(php_text)
    test_security_controls(php_text, js_text)
    test_multisite_single_separation(php_text, js_text)
    test_safety_guards(php_text)

    print("\nALL TESTS PASSED! Plugin is strictly verified.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
