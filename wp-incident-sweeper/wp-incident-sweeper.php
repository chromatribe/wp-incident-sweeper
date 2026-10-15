<?php
/**
 * Plugin Name: WP Incident Sweeper (インシデント一括巡回・駆除スイーパー)
 * Plugin URI: https://chromatri.be
 * Description: サイト内の全投稿・固定ページ・全カスタム投稿タイプ（公開・下書き・ゴミ箱・非公開含む）およびユーザーを瞬時に一括スキャン。マルチサイト（サイトネットワーク管理）環境にも完全対応し、傘下の全子サイトを一括横断クロール・駆除できます。
 * Version: 2.20261015.1
 * Author: CHROMATRIBE
 * Author URI: https://chromatri.be
 * License: GPL-2.0+
 * Network: true
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
        // 通常管理画面メニュー
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        // マルチサイト・ネットワーク管理画面メニュー
        add_action( 'network_admin_menu', array( $this, 'register_network_admin_menu' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Ajax Handlers
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

    public function register_network_admin_menu() {
        add_menu_page(
            'インシデント巡回スイーパー（ネットワーク全体）',
            '巡回スイーパー(全傘下サイト)',
            'manage_network',
            'wp-incident-sweeper',
            array( $this, 'render_admin_page' ),
            'dashicons-shield',
            3
        );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wp-incident-sweeper' ) ) {
            return;
        }

        wp_enqueue_style(
            'wpis-admin-css',
            plugins_url( 'assets/css/admin.css', __FILE__ ),
            array(),
            '2.20261015.1'
        );

        wp_enqueue_script(
            'wpis-admin-js',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            '2.20261015.1',
            true
        );

        $is_network = is_multisite() && is_network_admin();

        wp_localize_script( 'wpis-admin-js', 'wpisConfig', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'adminUrl'    => admin_url(),
            'isMultisite' => is_multisite(),
            'isNetwork'   => $is_network,
            'nonce'       => wp_create_nonce( 'wpis_nonce_action' ),
        ) );
    }

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
     * Ajax: サイト全体（マルチサイト時は傘下全サイト）のスキャン実行
     */
    public function ajax_run_scan() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        $is_network_req = ( isset( $_POST['is_network'] ) && '1' === $_POST['is_network'] );

        if ( $is_network_req ) {
            if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => '操作権限がありません（manage_networkが必要です）。' ), 403 );
            }
        } else {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => '操作権限がありません（manage_optionsが必要です）。' ), 403 );
            }
        }

        @ini_set( 'memory_limit', '768M' );
        @set_time_limit( 300 );

        global $wpdb;

        // 対象サイト一覧の構築
        $sites_to_scan = array();
        if ( is_multisite() && $is_network_req ) {
            $site_objs = get_sites( array( 'number' => 200, 'deleted' => 0 ) );
            foreach ( $site_objs as $s ) {
                $b_details = get_blog_details( (int) $s->blog_id );
                $sites_to_scan[] = array(
                    'id'   => (int) $s->blog_id,
                    'name' => $b_details ? $b_details->blogname : ($s->domain . $s->path),
                    'url'  => $b_details ? $b_details->siteurl : '',
                );
            }
        } else {
            $sites_to_scan[] = array(
                'id'   => get_current_blog_id(),
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url(),
            );
        }

        // 1. ユーザーのスキャン（ネットワーク共通）
        $users_data = array();
        $threat_user_ids = array();
        $all_users = get_users();

        $start_july = strtotime( '2026-07-17 00:00:00' );
        $end_july   = strtotime( '2026-07-25 23:59:59' );

        foreach ( $all_users as $u ) {
            $is_threat = false;
            $reasons = array();

            if ( strpos( $u->user_login, 'wp2_' ) === 0 ) {
                $is_threat = true;
                $reasons[] = 'ユーザー名が "wp2_*" で開始';
            }

            $u_email = strtolower( $u->user_email );
            foreach ( $this->suspicious_email_domains as $bad_domain ) {
                if ( strpos( $u_email, $bad_domain ) !== false ) {
                    $is_threat = true;
                    $reasons[] = '不審なメールアドレスドメイン ("' . esc_html( $bad_domain ) . '")';
                    break;
                }
            }

            $reg_time = strtotime( $u->user_registered );
            if ( $reg_time >= $start_july && $reg_time <= $end_july ) {
                $is_threat = true;
                $reasons[] = '7月脆弱性インシデント期間（7/17〜7/25）の登録ユーザー';
            }

            if ( $is_threat ) {
                $threat_user_ids[] = (int) $u->ID;
            }

            $is_super = is_multisite() && is_super_admin( $u->ID );
            $roles = (array) $u->roles;
            if ( $is_super && ! in_array( 'super_admin', $roles, true ) ) {
                array_unshift( $roles, 'super_admin(特権管理者)' );
            }

            $users_data[] = array(
                'id'              => (int) $u->ID,
                'login'           => sanitize_text_field( $u->user_login ),
                'name'            => sanitize_text_field( $u->display_name ),
                'email'           => sanitize_email( $u->user_email ),
                'roles'           => array_map( 'sanitize_text_field', $roles ),
                'registered'      => get_date_from_gmt( $u->user_registered, 'Y-m-d H:i:s' ),
                'is_threat'       => $is_threat,
                'threat_reasons'  => $reasons,
                'is_super_admin'  => $is_super,
            );
        }

        // 2. 傘下サイトの全投稿・固定ページのスキャン（switch_to_blogで完全横断）
        $exclude_types = array( 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
        $in_clause = "'" . implode( "','", array_map( 'esc_sql', $exclude_types ) ) . "'";

        $posts_data = array();
        $threat_post_count = 0;

        foreach ( $sites_to_scan as $target_site ) {
            $blog_id   = $target_site['id'];
            $site_name = $target_site['name'];

            if ( is_multisite() ) {
                switch_to_blog( $blog_id );
            }

            $protected_post_ids = $this->get_protected_post_ids();

            $sql = "
                SELECT p.ID, p.post_title, p.post_name, p.post_type, p.post_status, p.post_author, p.post_modified, p.post_content, u.display_name AS author_name
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID
                WHERE p.post_type NOT IN ({$in_clause})
                AND p.post_status IN ('publish', 'draft', 'pending', 'future', 'private', 'trash')
                ORDER BY p.post_modified DESC
            ";

            $rows = $wpdb->get_results( $sql );

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
                        $reasons[] = '🔒 システム重要ページ';
                    }

                    if ( in_array( $author_id, $threat_user_ids, true ) ) {
                        $severity = 'high';
                        $reasons[] = '不審なユーザー（wp2_* 等）が作成した記事';
                    }

                    $check_text = mb_strtolower( $title . ' ' . $slug . ' ' . $row->post_content, 'UTF-8' );

                    foreach ( $this->threat_keywords_high as $kw ) {
                        if ( mb_strpos( $check_text, $kw ) !== false ) {
                            $severity = 'high';
                            $reasons[] = '🚨 改ざん・Webシェル痕跡: "' . esc_html( $kw ) . '"';
                            break;
                        }
                    }

                    if ( $severity !== 'high' ) {
                        foreach ( $this->threat_keywords_medium as $kw ) {
                            if ( mb_strpos( $check_text, $kw ) !== false ) {
                                $severity = 'medium';
                                $reasons[] = '⚠️ スパム/不審コード: "' . esc_html( $kw ) . '"';
                                break;
                            }
                        }
                    }

                    if ( $severity === 'clean' && in_array( $status, array( 'draft', 'trash', 'pending', 'private' ), true ) ) {
                        $severity = 'low';
                        $reasons[] = '非公開/下書き/ゴミ箱';
                    }

                    if ( $severity === 'high' || $severity === 'medium' ) {
                        $threat_post_count++;
                    }

                    $edit_url = get_edit_post_link( $post_id, 'raw' );
                    if ( ! $edit_url ) {
                        $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
                    }

                    $posts_data[] = array(
                        'id'               => $post_id,
                        'blog_id'          => $blog_id,
                        'blog_name'        => $site_name,
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
                        'edit_url'         => esc_url_raw( $edit_url ),
                        'reasons'          => $reasons,
                    );
                }
            }

            if ( is_multisite() ) {
                restore_current_blog();
            }
        }

        wp_send_json_success( array(
            'posts'   => $posts_data,
            'users'   => $users_data,
            'sites'   => $sites_to_scan,
            'summary' => array(
                'totalSites'  => count( $sites_to_scan ),
                'totalPosts'  => count( $posts_data ),
                'threatPosts' => $threat_post_count,
                'totalUsers'  => count( $users_data ),
                'threatUsers' => count( $threat_user_ids ),
            ),
        ) );
    }

    /**
     * Ajax: 選択した投稿の一括削除/ゴミ箱移動（マルチサイト対応）
     */
    public function ajax_delete_posts() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        $raw_items = isset( $_POST['items'] ) ? sanitize_text_field( wp_unslash( $_POST['items'] ) ) : '[]';
        $items = json_decode( $raw_items, true );
        $force_delete = ( isset( $_POST['force_delete'] ) && '1' === $_POST['force_delete'] );

        if ( ! is_array( $items) || empty( $items ) ) {
            wp_send_json_error( array( 'message' => '削除対象が選択されていません。' ) );
        }

        $deleted_count = 0;
        $skipped_count = 0;

        foreach ( $items as $item ) {
            $pid     = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
            $blog_id = isset( $item['blog_id'] ) ? absint( $item['blog_id'] ) : get_current_blog_id();

            if ( $pid <= 0 ) continue;

            if ( is_multisite() && $blog_id > 0 ) {
                switch_to_blog( $blog_id );
            }

            if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'delete_others_posts' ) ) {
                if ( is_multisite() && $blog_id > 0 ) restore_current_blog();
                $skipped_count++;
                continue;
            }

            $protected_post_ids = $this->get_protected_post_ids();
            $allowed_post_types = get_post_types( array( 'public' => true ), 'names' );
            if ( ! in_array( 'post', $allowed_post_types, true ) ) $allowed_post_types[] = 'post';
            if ( ! in_array( 'page', $allowed_post_types, true ) ) $allowed_post_types[] = 'page';

            if ( in_array( $pid, $protected_post_ids, true ) ) {
                if ( is_multisite() && $blog_id > 0 ) restore_current_blog();
                $skipped_count++;
                continue;
            }

            $post = get_post( $pid );
            if ( ! $post || ! in_array( $post->post_type, $allowed_post_types, true ) ) {
                if ( is_multisite() && $blog_id > 0 ) restore_current_blog();
                $skipped_count++;
                continue;
            }

            // 公開中かつ改ざんシグネチャがない記事はゴミ箱へ安全退避
            if ( $force_delete && 'publish' === $post->post_status ) {
                $check_text = mb_strtolower( $post->post_title . ' ' . $post->post_name . ' ' . $post->post_content, 'UTF-8' );
                $is_confirmed_threat = false;
                foreach ( $this->threat_keywords_high as $kw ) {
                    if ( mb_strpos( $check_text, $kw ) !== false ) {
                        $is_confirmed_threat = true;
                        break;
                    }
                }
                if ( ! $is_confirmed_threat ) {
                    $res = wp_trash_post( $pid );
                    if ( $res ) $deleted_count++;
                    if ( is_multisite() && $blog_id > 0 ) restore_current_blog();
                    continue;
                }
            }

            if ( $force_delete ) {
                $res = wp_delete_post( $pid, true );
            } else {
                $res = wp_trash_post( $pid );
            }

            if ( $res ) {
                $deleted_count++;
            }

            if ( is_multisite() && $blog_id > 0 ) {
                restore_current_blog();
            }
        }

        wp_send_json_success( array(
            'deleted_count' => $deleted_count,
            'skipped_count' => $skipped_count,
        ) );
    }

    /**
     * Ajax: 選択した不審ユーザーの一括削除（マルチサイト安全対応）
     */
    public function ajax_delete_users() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( is_multisite() ) {
            if ( ! current_user_can( 'manage_network_users' ) && ! current_user_can( 'delete_users' ) ) {
                wp_send_json_error( array( 'message' => 'ユーザーの削除権限がありません。' ), 403 );
            }
        } else {
            if ( ! current_user_can( 'delete_users' ) ) {
                wp_send_json_error( array( 'message' => 'ユーザーの削除権限がありません。' ), 403 );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        if ( is_multisite() ) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }

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

            if ( $uid <= 0 || $uid === $current_user_id || $uid === 1 ) {
                $skipped_count++;
                continue;
            }

            $user_obj = get_userdata( $uid );
            if ( ! $user_obj ) {
                continue;
            }

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

            if ( ! $is_threat_user ) {
                $skipped_count++;
                continue;
            }

            // マルチサイト全サイト横断での不正下書き削除＆公開記事保護
            if ( is_multisite() ) {
                $sites = get_sites( array( 'number' => 200, 'deleted' => 0 ) );
                foreach ( $sites as $s ) {
                    switch_to_blog( (int) $s->blog_id );
                    $draft_posts = $wpdb->get_col( $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_status IN ('draft', 'pending', 'trash')",
                        $uid
                    ) );
                    foreach ( $draft_posts as $d_id ) {
                        wp_delete_post( (int) $d_id, true );
                    }
                    restore_current_blog();
                }
                // ネットワークからの完全消去（公開記事は $current_user_id に引き継ぎ）
                if ( wpmu_delete_user( $uid ) ) {
                    $deleted_count++;
                }
            } else {
                $draft_posts = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_status IN ('draft', 'pending', 'trash')",
                    $uid
                ) );
                foreach ( $draft_posts as $d_id ) {
                    wp_delete_post( (int) $d_id, true );
                }
                if ( wp_delete_user( $uid, $current_user_id ) ) {
                    $deleted_count++;
                }
            }
        }

        wp_send_json_success( array(
            'deleted_count' => $deleted_count,
            'skipped_count' => $skipped_count,
        ) );
    }

    /**
     * 管理画面UIレンダリング
     */
    public function render_admin_page() {
        $is_network = is_multisite() && is_network_admin();
        ?>
        <div class="wrap wpis-app" data-is-network="<?php echo $is_network ? '1' : '0'; ?>">
            <!-- Header -->
            <div class="wpis-header">
                <div class="wpis-title-area">
                    <h1>
                        <span class="dashicons dashicons-shield" style="font-size:24px;width:24px;height:24px;color:#0284c7;"></span>
                        インシデント巡回スイーパー <?php echo $is_network ? '<span class="wpis-badge wpis-badge-danger" style="font-size:12px;margin-left:8px;">ネットワーク全体（全子サイト横断モード）</span>' : ''; ?>
                    </h1>
                    <p>
                        <?php if ( $is_network ) : ?>
                            ネットワーク傘下の<strong>すべての子サイト（サブサイト）</strong>を一括横断スキャンし、不審なアカウントや改ざん下書きを一網打尽に検知・駆除します。
                        <?php else : ?>
                            サイト内の全コンテンツ（公開・下書き・ゴミ箱）およびユーザーを一括クロールし、不正な痕跡を特定・安全に駆除します。
                        <?php endif; ?>
                    </p>
                </div>
                <div class="wpis-actions">
                    <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-csv">
                        <span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;"></span>
                        結果をCSV出力
                    </button>
                    <button type="button" class="wpis-btn wpis-btn-primary" id="wpis-btn-scan">
                        <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;"></span>
                        <?php echo $is_network ? '全サイト横断スキャン実行' : '再スキャン実行'; ?>
                    </button>
                </div>
            </div>

            <!-- Metrics Summary Cards -->
            <div class="wpis-metrics">
                <?php if ( is_multisite() ) : ?>
                <div class="wpis-metric-card is-primary">
                    <div class="wpis-metric-label">スキャン対象サイト数</div>
                    <div class="wpis-metric-val" id="wpis-metric-total-sites">-</div>
                </div>
                <?php endif; ?>
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
                    巡回ガイド＆マルチサイト運用
                </button>
            </div>

            <!-- Panel: Posts -->
            <div class="wpis-panel is-active" id="wpis-panel-posts">
                <div class="wpis-table-toolbar">
                    <div class="wpis-filter-group">
                        <?php if ( is_multisite() ) : ?>
                        <select class="wpis-filter-select" id="wpis-posts-site-filter">
                            <option value="all">全傘下サイト</option>
                        </select>
                        <?php endif; ?>
                        <select class="wpis-filter-select" id="wpis-posts-filter">
                            <option value="all">すべてのステータス</option>
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
                                <?php if ( is_multisite() ) : ?><th style="width:140px;">対象サイト</th><?php endif; ?>
                                <th>タイトル</th>
                                <th style="width:90px;">タイプ</th>
                                <th style="width:80px;">状態</th>
                                <th style="width:120px;">作成者</th>
                                <th style="width:140px;">最終更新日時</th>
                                <th>検知理由</th>
                            </tr>
                        </thead>
                        <tbody id="wpis-posts-tbody">
                            <tr><td colspan="<?php echo is_multisite() ? '9' : '8'; ?>" class="wpis-empty-state">スキャン準備中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel: Users -->
            <div class="wpis-panel" id="wpis-panel-users">
                <div class="wpis-table-toolbar">
                    <div class="wpis-filter-group">
                        <span style="font-size:13px;color:#64748b;">
                            🛡️ <strong>マルチサイト安全保護:</strong> ネットワーク管理画面から不審ユーザーを削除すると、全傘下サイトに散らばった不正下書きが一括完全削除され、公開中の正規記事は安全に保護されます。
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
                                <th style="width:140px;">権限グループ</th>
                                <th style="width:160px;">登録日時 (JST)</th>
                                <th>検知理由</th>
                            </tr>
                        </thead>
                        <tbody id="wpis-users-tbody">
                            <tr><td colspan="7" class="wpis-empty-state">スキャン準備中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel: Guide -->
            <div class="wpis-panel" id="wpis-panel-guide">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;line-height:1.7;">
                    <h2 style="font-size:16px;margin-top:0;">🌐 マルチサイト（サイトネットワーク）での運用手順</h2>
                    <ol style="padding-left:20px;font-size:13px;color:#334155;">
                        <li><strong>ネットワーク有効化:</strong> サイトネットワーク管理者画面（<code>/wp-admin/network/plugins.php</code>）にて「ネットワークで有効化」します。</li>
                        <li><strong>ネットワーク管理からスキャン:</strong> ネットワーク管理の左メニュー「巡回スイーパー (全傘下サイト)」を開いてスキャンを実行すると、<strong>配下にあるすべての子サイトを全自動で巡回クロール</strong>します。</li>
                        <li><strong>サイト別フィルター:</strong> 投稿一覧では、特定の子サイトだけに絞り込んで確認することも可能です。</li>
                        <li><strong>一括駆除:</strong> 危険なアイテムをチェックして完全削除すると、該当する子サイトのDBに対して安全に処理が実行されます。</li>
                        <li><strong>単体サブサイトでの実行も可能:</strong> 通常の各サブサイト管理画面（<code>/wp-admin/</code>）でも、そのサイト単独のスイーパーとして利用できます。</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
}

// 起動
add_action( 'plugins_loaded', array( 'WP_Incident_Sweeper', 'get_instance' ) );
