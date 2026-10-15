<?php
/**
 * Plugin Name: WP Incident Sweeper (インシデント一括巡回・駆除スイーパー)
 * Plugin URI: https://chromatri.be
 * Description: サイト内の全投稿・固定ページ・全カスタム投稿タイプ（公開・下書き・ゴミ箱・非公開含む）およびユーザーを瞬時に一括スキャン。改ざん痕跡（Hacked by...、wp2_*、不審な登録日のアカウント等）を自動検出し、選択したアイテムを一括完全削除できる緊急セキュリティ・巡回ツール。
 * Version: 1.2.0
 * Author: CHROMATRIBE
 * Author URI: https://chromatri.be
 * License: GPL-2.0+
 * Text Domain: wp-incident-sweeper
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Incident_Sweeper {

    private static $instance = null;

    // 🚨 危険度：高（改ざん・ハッカー署名・Webシェル痕跡）
    private $threat_keywords_high = array(
        'hacked by',
        'trenggalek',
        'trenggalek6etar',
        'wp2shell',
        'defaced by',
        'defaced',
        'indoxploit',
        'indonesian hacker',
        'anonsec',
        'algerian hacker',
        'pakistan cyber',
        'gaza hacker',
        'turkcell',
        'priv8',
        'xleet',
        'b374k',
        'wso shell',
        'alfa team',
        'c99shell',
        'r57shell',
        'weevely',
        'web shell',
        'webshell',
    );

    // ⚠️ 危険度：中（SEOスパム・カジノ・不正コード・隠しリンク）
    private $threat_keywords_medium = array(
        // オンラインカジノ・ギャンブル系スパム
        'casino',
        'slot online',
        'slot gacor',
        'judi online',
        'poker online',
        'togel online',
        'sbobet',
        'maxwin',
        'zeus slot',
        'pragmatic play',
        'カジノ',
        'オンラインカジノ',
        'バカラ',
        'パチンコ',
        // 医薬品・違法スパム
        'viagra',
        'cialis',
        'levitra',
        'バイアグラ',
        'シアリス',
        'スーパーコピー',
        '偽物 ブランド',
        '激安 ブランド',
        '無修正',
        // 不正コード・難読化・スクリプト
        'eval(base64_decode',
        'base64_decode(',
        'gzinflate(base64_decode',
        'str_rot13(',
        '<script>eval',
        'document.write(unescape',
        'window.location.replace',
        'fromCharCode(',
        // SEO隠しテキスト（不自然なスタイル）
        'position:absolute;left:-9999px',
        'position: absolute; left: -9999px',
        'display:none !important',
        'visibility:hidden !important',
    );

    // 不審ドメインパターン（使い捨てメアド・攻撃ドメイン）
    private $suspicious_email_domains = array(
        '@wp2shell.invalid',
        '.invalid',
        '@tempmail.',
        '@temp-mail.',
        '@guerrillamail.',
        '@10minutemail.',
        '@mailinator.',
        '@throwawaymail.',
        '@sharklasers.',
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Ajax Handlers (要管理者権限・Nonce検証)
        add_action( 'wp_ajax_wpis_run_scan', array( $this, 'ajax_run_scan' ) );
        add_action( 'wp_ajax_wpis_delete_posts', array( $this, 'ajax_delete_posts' ) );
        add_action( 'wp_ajax_wpis_delete_users', array( $this, 'ajax_delete_users' ) );
    }

    public function register_admin_menu() {
        add_menu_page(
            'インシデント巡回スイーパー',
            '巡回スイーパー',
            'manage_options',
            'wp-incident-sweeper',
            array( $this, 'render_admin_page' ),
            'dashicons-shield',
            3
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_wp-incident-sweeper' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wpis-admin-css',
            plugins_url( 'assets/css/admin.css', __FILE__ ),
            array(),
            '1.2.0'
        );

        wp_enqueue_script(
            'wpis-admin-js',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            '1.2.0',
            true
        );

        wp_localize_script( 'wpis-admin-js', 'wpisConfig', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'adminUrl' => admin_url(),
            'nonce'    => wp_create_nonce( 'wpis_nonce_action' ),
        ) );
    }

    /**
     * システム保護対象ページのIDリストを取得（誤削除絶対防止）
     */
    private function get_protected_post_ids() {
        $protected = array();
        $front_id   = (int) get_option( 'page_on_front' );
        $posts_id   = (int) get_option( 'page_for_posts' );
        $privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );

        if ( $front_id > 0 )   $protected[] = $front_id;
        if ( $posts_id > 0 )   $protected[] = $posts_id;
        if ( $privacy_id > 0 ) $protected[] = $privacy_id;

        return array_unique( $protected );
    }

    /**
     * Ajax: サイト全体のスキャン実行（高効率・省メモリクエリ）
     */
    public function ajax_run_scan() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '操作権限がありません（manage_optionsが必要です）。' ), 403 );
        }

        @ini_set( 'memory_limit', '512M' );
        @set_time_limit( 120 );

        global $wpdb;

        $protected_post_ids = $this->get_protected_post_ids();

        // 1. ユーザーのスキャン
        $users_data = array();
        $threat_user_ids = array();
        $all_users = get_users();

        $start_july = strtotime( '2026-07-17 00:00:00' );
        $end_july   = strtotime( '2026-07-25 23:59:59' );

        foreach ( $all_users as $u ) {
            $is_threat = false;
            $reasons = array();

            // wp2_* プレフィックス
            if ( strpos( $u->user_login, 'wp2_' ) === 0 ) {
                $is_threat = true;
                $reasons[] = 'ユーザー名が "wp2_*" で開始';
            }

            // 不審なメールアドレス・ドメイン
            $u_email = strtolower( $u->user_email );
            foreach ( $this->suspicious_email_domains as $bad_domain ) {
                if ( strpos( $u_email, $bad_domain ) !== false ) {
                    $is_threat = true;
                    $reasons[] = '不審なメールアドレスドメイン ("' . esc_html( $bad_domain ) . '")';
                    break;
                }
            }

            // 7月インシデント期間（7/17〜7/25）登録
            $reg_time = strtotime( $u->user_registered );
            if ( $reg_time >= $start_july && $reg_time <= $end_july ) {
                $is_threat = true;
                $reasons[] = '7月脆弱性インシデント期間（7/17〜7/25）の登録ユーザー';
            }

            if ( $is_threat ) {
                $threat_user_ids[] = (int) $u->ID;
            }

            // 該当ユーザーが作成した公開記事数と下書き数を事前集計（安全性確認）
            $published_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_author = %d AND post_status = 'publish'",
                $u->ID
            ) );
            $draft_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_author = %d AND post_status IN ('draft', 'pending', 'trash')",
                $u->ID
            ) );

            $users_data[] = array(
                'id'              => (int) $u->ID,
                'login'           => sanitize_text_field( $u->user_login ),
                'name'            => sanitize_text_field( $u->display_name ),
                'email'           => sanitize_email( $u->user_email ),
                'roles'           => array_map( 'sanitize_key', (array) $u->roles ),
                'registered'      => get_date_from_gmt( $u->user_registered, 'Y-m-d H:i:s' ),
                'is_threat'       => $is_threat,
                'threat_reasons'  => $reasons,
                'published_posts' => $published_count,
                'draft_posts'     => $draft_count,
            );
        }

        // 2. 投稿・固定ページ・全カスタム投稿タイプのスキャン
        $exclude_types = array( 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
        $in_clause = "'" . implode( "','", array_map( 'esc_sql', $exclude_types ) ) . "'";

        $sql = "
            SELECT p.ID, p.post_title, p.post_name, p.post_type, p.post_status, p.post_author, p.post_modified, p.post_content, u.display_name AS author_name
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID
            WHERE p.post_type NOT IN ({$in_clause})
            AND p.post_status IN ('publish', 'draft', 'pending', 'future', 'private', 'trash')
            ORDER BY p.post_modified DESC
        ";

        $rows = $wpdb->get_results( $sql );
        $posts_data = array();
        $threat_post_count = 0;

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $post_id     = (int) $row->ID;
                $title       = $row->post_title;
                $slug        = $row->post_name;
                $ptype       = $row->post_type;
                $status      = $row->post_status;
                $author_id   = (int) $row->post_author;
                $author_name = $row->author_name ? $row->author_name : 'ID:' . $author_id;
                $mod_date    = $row->post_modified;

                $is_protected = in_array( $post_id, $protected_post_ids, true );

                $severity = 'clean';
                $reasons  = array();

                if ( $is_protected ) {
                    $reasons[] = '🔒 システム重要ページ（トップ/ブログ/プライバシー）';
                }

                // 不審ユーザーが作成した記事
                if ( in_array( $author_id, $threat_user_ids, true ) ) {
                    $severity = 'high';
                    $reasons[] = '不審なユーザー（wp2_* 等）が作成した記事';
                }

                // タイトル・スラッグ・本文のKW検査
                $check_text = mb_strtolower( $title . ' ' . $slug . ' ' . $row->post_content, 'UTF-8' );

                foreach ( $this->threat_keywords_high as $kw ) {
                    if ( mb_strpos( $check_text, $kw ) !== false ) {
                        $severity = 'high';
                        $reasons[] = '🚨 改ざん・Webシェル痕跡検知: "' . esc_html( $kw ) . '"';
                        break;
                    }
                }

                if ( $severity !== 'high' ) {
                    foreach ( $this->threat_keywords_medium as $kw ) {
                        if ( mb_strpos( $check_text, $kw ) !== false ) {
                            $severity = 'medium';
                            $reasons[] = '⚠️ スパム/不審コード検知: "' . esc_html( $kw ) . '"';
                            break;
                        }
                    }
                }

                // 下書き・ゴミ箱ステータス
                if ( $severity === 'clean' && in_array( $status, array( 'draft', 'trash', 'pending', 'private' ), true ) ) {
                    $severity = 'low';
                    $reasons[] = '非公開/下書き/ゴミ箱ステータス';
                }

                if ( $severity === 'high' || $severity === 'medium' ) {
                    $threat_post_count++;
                }

                $posts_data[] = array(
                    'id'               => $post_id,
                    'title'            => sanitize_text_field( $title ),
                    'slug'             => sanitize_text_field( $slug ),
                    'post_type'        => sanitize_key( $ptype ),
                    'status'           => sanitize_key( $status ),
                    'author_id'        => $author_id,
                    'author_name'      => sanitize_text_field( $author_name ),
                    'author_is_threat' => in_array( $author_id, $threat_user_ids, true ),
                    'modified_date'    => $mod_date,
                    'severity'         => $severity,
                    'is_protected'     => $is_protected,
                    'reasons'          => $reasons,
                );
            }
        }

        wp_send_json_success( array(
            'posts'   => $posts_data,
            'users'   => $users_data,
            'summary' => array(
                'totalPosts'  => count( $posts_data ),
                'threatPosts' => $threat_post_count,
                'totalUsers'  => count( $users_data ),
                'threatUsers' => count( $threat_user_ids ),
            ),
        ) );
    }

    /**
     * Ajax: 選択した投稿の一括削除/ゴミ箱移動（安全ガード・巻き込み防止）
     */
    public function ajax_delete_posts() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_others_posts' ) ) {
            wp_send_json_error( array( 'message' => '記事の削除権限がありません。' ), 403 );
        }

        $raw_ids = isset( $_POST['post_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['post_ids'] ) ) : '[]';
        $post_ids = json_decode( $raw_ids, true );
        $force_delete = ( isset( $_POST['force_delete'] ) && '1' === $_POST['force_delete'] );

        if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
            wp_send_json_error( array( 'message' => '削除対象が選択されていません。' ) );
        }

        $protected_post_ids = $this->get_protected_post_ids();
        $allowed_post_types = get_post_types( array( 'public' => true ), 'names' );
        if ( ! in_array( 'post', $allowed_post_types, true ) ) $allowed_post_types[] = 'post';
        if ( ! in_array( 'page', $allowed_post_types, true ) ) $allowed_post_types[] = 'page';

        $deleted_count = 0;
        $skipped_count = 0;

        foreach ( $post_ids as $pid ) {
            $pid = absint( $pid );
            if ( $pid <= 0 ) continue;

            // ガード1: システム保護ページ（フロントページ等）の強制スキップ
            if ( in_array( $pid, $protected_post_ids, true ) ) {
                $skipped_count++;
                continue;
            }

            // ガード2: 投稿タイプの厳格検証（添付ファイルや内部データ等の巻き込み防止）
            $post = get_post( $pid );
            if ( ! $post || ! in_array( $post->post_type, $allowed_post_types, true ) ) {
                $skipped_count++;
                continue;
            }

            // ガード3: 公開中（publish）記事の誤完全削除ガード
            // 公開中かつ改ざんキーワードが含まれていない記事は、完全削除を拒否しゴミ箱移動にフォールバック
            if ( $force_delete && 'publish' === $post->post_status ) {
                $check_text = mb_strtolower( $post->post_title . ' ' . $post->post_name . ' ' . $post->post_content, 'UTF-8' );
                $is_confirmed_threat = false;
                foreach ( $this->threat_keywords_high as $kw ) {
                    if ( mb_strpos( $check_text, $kw ) !== false ) {
                        $is_confirmed_threat = true;
                        break;
                    }
                }
                // 明確な改ざんシグネチャがない公開記事は完全削除させない（ゴミ箱へ退避）
                if ( ! $is_confirmed_threat ) {
                    $res = wp_trash_post( $pid );
                    if ( $res ) $deleted_count++;
                    continue;
                }
            }

            // 実行
            if ( $force_delete ) {
                $res = wp_delete_post( $pid, true ); // 完全削除
            } else {
                $res = wp_trash_post( $pid ); // ゴミ箱へ移動
            }

            if ( $res ) {
                $deleted_count++;
            }
        }

        wp_send_json_success( array(
            'deleted_count' => $deleted_count,
            'skipped_count' => $skipped_count,
        ) );
    }

    /**
     * Ajax: 選択した不審ユーザーの一括削除（公開記事巻き込み防止・所有権安全移譲）
     */
    public function ajax_delete_users() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_users' ) ) {
            wp_send_json_error( array( 'message' => 'ユーザーの削除権限がありません。' ), 403 );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        global $wpdb;

        $raw_ids = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '[]';
        $user_ids = json_decode( $raw_ids, true );

        if ( ! is_array( $user_ids ) || empty( $user_ids ) ) {
            wp_send_json_error( array( 'message' => '削除対象ユーザーが選択されていません。' ) );
        }

        $current_user_id = get_current_user_id();
        $deleted_count = 0;
        $skipped_count = 0;

        $start_july = strtotime( '2026-07-17 00:00:00' );
        $end_july   = strtotime( '2026-07-25 23:59:59' );

        foreach ( $user_ids as $uid ) {
            $uid = absint( $uid );

            // ガード1: 自身（ログイン中）およびID: 1 の削除は物理的・論理的に絶対阻止
            if ( $uid <= 0 || $uid === $current_user_id || $uid === 1 ) {
                $skipped_count++;
                continue;
            }

            $user_obj = get_userdata( $uid );
            if ( ! $user_obj ) {
                continue;
            }

            // ガード2: 不審フラグを持たない正規ユーザーの誤爆削除を防止
            $is_threat_user = false;
            if ( strpos( $user_obj->user_login, 'wp2_' ) === 0 ) {
                $is_threat_user = true;
            }
            $u_email = strtolower( $user_obj->user_email );
            foreach ( $this->suspicious_email_domains as $bad_domain ) {
                if ( strpos( $u_email, $bad_domain ) !== false ) {
                    $is_threat_user = true;
                    break;
                }
            }
            $reg_time = strtotime( $user_obj->user_registered );
            if ( $reg_time >= $start_july && $reg_time <= $end_july ) {
                $is_threat_user = true;
            }

            // 不審判定されていない通常ユーザーが選択されていた場合は安全のためスキップ
            if ( ! $is_threat_user ) {
                $skipped_count++;
                continue;
            }

            // ガード3: 公開中記事の巻き込み事故防止（超重要！）
            // 該当ユーザーが作成した「下書き・ゴミ箱・保留（不正記事）」のみを物理削除し、
            // 万が一「公開中（publish）の正規記事」が存在した場合は現在ログイン中の安全な管理者に所有権を引き継ぐ！
            
            // 3-a: 不正な下書き・ゴミ箱記事を個別削除
            $draft_posts = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_status IN ('draft', 'pending', 'trash')",
                $uid
            ) );
            foreach ( $draft_posts as $d_id ) {
                wp_delete_post( (int) $d_id, true );
            }

            // 3-b: 公開中記事があれば現在管理者に所有権を移譲した上でユーザーを削除
            // wp_delete_user の第2引数に $current_user_id を指定することで、残った公開記事は安全に保護される
            if ( wp_delete_user( $uid, $current_user_id ) ) {
                $deleted_count++;
            }
        }

        wp_send_json_success( array(
            'deleted_count' => $deleted_count,
            'skipped_count' => $skipped_count,
        ) );
    }

    /**
     * 管理画面のUIレンダリング（XSS防止エスケープ徹底）
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'このページにアクセスする権限がありません。' ) );
        }
        ?>
        <div class="wrap wpis-app">
            <!-- Header -->
            <div class="wpis-header">
                <div class="wpis-title-area">
                    <h1>
                        <span class="dashicons dashicons-shield" style="font-size:24px;width:24px;height:24px;color:#0284c7;"></span>
                        インシデント巡回スイーパー (WP Incident Sweeper)
                    </h1>
                    <p>サイト内の全コンテンツ（公開・下書き・ゴミ箱）およびユーザーを一括クロールし、不正な痕跡を特定・安全に駆除します。</p>
                </div>
                <div class="wpis-actions">
                    <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-csv">
                        <span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;"></span>
                        結果をCSV出力
                    </button>
                    <button type="button" class="wpis-btn wpis-btn-primary" id="wpis-btn-scan">
                        <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;"></span>
                        再スキャン実行
                    </button>
                </div>
            </div>

            <!-- Metrics Summary Cards -->
            <div class="wpis-metrics">
                <div class="wpis-metric-card is-primary">
                    <div class="wpis-metric-label">総コンテンツ数（全タイプ）</div>
                    <div class="wpis-metric-val" id="wpis-metric-total-posts">-</div>
                </div>
                <div class="wpis-metric-card is-danger">
                    <div class="wpis-metric-label">🚨 危険・警告コンテンツ</div>
                    <div class="wpis-metric-val" id="wpis-metric-threat-posts">-</div>
                </div>
                <div class="wpis-metric-card is-primary">
                    <div class="wpis-metric-label">総登録ユーザー数</div>
                    <div class="wpis-metric-val" id="wpis-metric-total-users">-</div>
                </div>
                <div class="wpis-metric-card is-danger">
                    <div class="wpis-metric-label">🚨 不審なユーザー（wp2_* 等）</div>
                    <div class="wpis-metric-val" id="wpis-metric-threat-users">-</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="wpis-tabs">
                <button type="button" class="wpis-tab-btn is-active" data-tab="posts">
                    <span class="dashicons dashicons-admin-post" style="font-size:18px;width:18px;height:18px;"></span>
                    投稿・固定ページ監査
                    <span class="wpis-tab-count" id="wpis-badge-posts-threat">0</span>
                </button>
                <button type="button" class="wpis-tab-btn" data-tab="users">
                    <span class="dashicons dashicons-admin-users" style="font-size:18px;width:18px;height:18px;"></span>
                    ユーザー監査
                    <span class="wpis-tab-count" id="wpis-badge-users-threat">0</span>
                </button>
                <button type="button" class="wpis-tab-btn" data-tab="guide">
                    <span class="dashicons dashicons-info" style="font-size:18px;width:18px;height:18px;"></span>
                    巡回チェックガイド＆安全ガード仕様
                </button>
            </div>

            <!-- Panel: Posts -->
            <div class="wpis-panel is-active" id="wpis-panel-posts">
                <div class="wpis-table-toolbar">
                    <div class="wpis-filter-group">
                        <select class="wpis-filter-select" id="wpis-posts-filter">
                            <option value="all">すべて表示</option>
                            <option value="threat_high">🚨 危険のみ（Hacked by / 不審者作成）</option>
                            <option value="threat_all">⚠️ 危険＋警告（全検知項目）</option>
                            <option value="draft_trash">下書き・ゴミ箱・非公開のみ</option>
                        </select>
                        <input type="text" class="wpis-search-input" id="wpis-posts-search" placeholder="タイトルや作成者で絞り込み...">
                        <button type="button" class="wpis-btn wpis-btn-secondary wpis-btn-sm" id="wpis-btn-select-threats">
                            🚨 危険項目を一括選択
                        </button>
                    </div>
                    <div class="wpis-bulk-actions">
                        <button type="button" class="wpis-btn wpis-btn-secondary wpis-btn-sm" id="wpis-btn-trash-selected" disabled>
                            選択した項目をゴミ箱へ（安全）
                        </button>
                        <button type="button" class="wpis-btn wpis-btn-danger wpis-btn-sm" id="wpis-btn-delete-selected" disabled>
                            選択した項目を完全削除
                        </button>
                    </div>
                </div>

                <div class="wpis-table-wrap">
                    <table class="wpis-table">
                        <thead>
                            <tr>
                                <th style="width:36px;text-align:center;"><input type="checkbox" id="wpis-posts-check-all"></th>
                                <th style="width:130px;">判定</th>
                                <th>タイトル</th>
                                <th style="width:100px;">タイプ</th>
                                <th style="width:90px;">状態</th>
                                <th style="width:130px;">作成者</th>
                                <th style="width:150px;">最終更新日時</th>
                                <th>検知理由</th>
                            </tr>
                        </thead>
                        <tbody id="wpis-posts-tbody">
                            <tr><td colspan="8" class="wpis-empty-state">スキャン準備中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel: Users -->
            <div class="wpis-panel" id="wpis-panel-users">
                <div class="wpis-table-toolbar">
                    <div class="wpis-filter-group">
                        <span style="font-size:13px;color:#64748b;">
                            🛡️ <strong>安全保護機能:</strong> 不審ユーザー削除時、下書き等の不正記事のみ消去され、公開中の正規記事は現在の管理者に安全に引き継がれます。
                        </span>
                    </div>
                    <div class="wpis-bulk-actions">
                        <button type="button" class="wpis-btn wpis-btn-danger wpis-btn-sm" id="wpis-btn-delete-users" disabled>
                            選択した不審ユーザーを削除
                        </button>
                    </div>
                </div>

                <div class="wpis-table-wrap">
                    <table class="wpis-table">
                        <thead>
                            <tr>
                                <th style="width:36px;text-align:center;"><input type="checkbox" id="wpis-users-check-all"></th>
                                <th style="width:130px;">判定</th>
                                <th>ユーザー名</th>
                                <th>メールアドレス</th>
                                <th style="width:110px;">権限グループ</th>
                                <th style="width:130px;">公開 / 下書き数</th>
                                <th style="width:160px;">登録日時 (JST)</th>
                                <th>検知理由</th>
                            </tr>
                        </thead>
                        <tbody id="wpis-users-tbody">
                            <tr><td colspan="8" class="wpis-empty-state">スキャン準備中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel: Guide -->
            <div class="wpis-panel" id="wpis-panel-guide">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;line-height:1.7;">
                    <h2 style="font-size:16px;margin-top:0;">🛡️ 巻き込み事故を防止する「多重安全ガード仕様」</h2>
                    <p style="font-size:13px;color:#475569;">本ツールは本番環境での安全な運用のため、他記事や正規ユーザー、DBテーブルへの巻き込み事故を論理的・物理的に防止する4重のガード機構を標準装備しています。</p>
                    
                    <ul style="padding-left:20px;font-size:13px;color:#334155;">
                        <li><strong>ガード1（システム重要ページの完全保護）：</strong><br>
                            トップページ（<code>page_on_front</code>）、ブログ一覧ページ（<code>page_for_posts</code>）、プライバシーポリシーページ等のシステム設定ページは「🔒 システム保護」として判定され、削除処理から強制的に除外（スキップ）されます。</li>
                        <li><strong>ガード2（許可された投稿タイプのみ対象）：</strong><br>
                            投稿（post）、固定ページ（page）、公開カスタム投稿タイプのみを対象とし、添付ファイル（画像実体）、ナビゲーションメニュー、システム内部データ（ブロック定義等）への連鎖削除・巻き込みは一切発生しません。</li>
                        <li><strong>ガード3（公開中記事の誤完全削除ブロック）：</strong><br>
                            ステータスが「公開中（publish）」で、かつ明確な改ざんシグネチャがない記事は、万が一「完全削除」を実行しても物理削除を拒否し、「ゴミ箱への移動（いつでも復元可能）」に安全にフォールバックします。</li>
                        <li><strong>ガード4（ユーザー削除時の公開記事所有権移譲）：</strong><br>
                            不審ユーザーを削除する際、下書き（draft）やゴミ箱内の不正記事のみが完全消去され、万が一公開中（publish）の正規記事が存在した場合は、削除を実行している管理者に所有権が自動で引き継がれ、記事が消滅することはありません。</li>
                        <li><strong>ガード5（自身・初期管理者の保護）：</strong><br>
                            ログイン中の管理者自身、およびID: 1 のマスター管理者アカウントはチェック・削除できないよう物理的に保護されています。</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}

// 起動
add_action( 'plugins_loaded', array( 'WP_Incident_Sweeper', 'get_instance' ) );
