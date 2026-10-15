<?php
/**
 * Plugin Name: WP Incident Sweeper (インシデント一括巡回・駆除スイーパー)
 * Plugin URI: https://chromatri.be
 * Description: サイト内の全投稿・固定ページ・全カスタム投稿タイプ（公開・下書き・ゴミ箱・非公開含む）およびユーザーを瞬時に一括スキャン。単一サイトおよびマルチサイト（サイトネットワーク管理）の両方に完全対応し、コンテキストに応じてUIが自動最適化されます。
 * Version: 2.20261015.7
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
        add_action( 'wp_ajax_wpis_sync_signatures', array( $this, 'ajax_sync_signatures' ) );
        add_action( 'wp_ajax_wpis_save_my_keywords', array( $this, 'ajax_save_my_keywords' ) );
    }

    const REMOTE_SIGNATURES_URL = 'https://raw.githubusercontent.com/chromatribe/wp-incident-sweeper/main/signatures.json';

    public function get_signatures() {
        $cached = get_site_option( 'wpis_signatures', null );
        if ( is_array( $cached ) && ! empty( $cached['threat_keywords_high'] ) ) {
            return array(
                'threat_keywords_high'     => array_values( array_unique( array_merge( $this->threat_keywords_high, (array) $cached['threat_keywords_high'] ) ) ),
                'threat_keywords_medium'   => array_values( array_unique( array_merge( $this->threat_keywords_medium, (array) $cached['threat_keywords_medium'] ) ) ),
                'suspicious_email_domains' => array_values( array_unique( array_merge( $this->suspicious_email_domains, (array) $cached['suspicious_email_domains'] ) ) ),
                'version'                  => isset( $cached['version'] ) ? $cached['version'] : 'builtin',
                'updated_at'               => get_site_option( 'wpis_signatures_synced_at', '組み込み既定値' ),
            );
        }

        return array(
            'threat_keywords_high'     => $this->threat_keywords_high,
            'threat_keywords_medium'   => $this->threat_keywords_medium,
            'suspicious_email_domains' => $this->suspicious_email_domains,
            'version'                  => 'builtin',
            'updated_at'               => '組み込み既定値',
        );
    }

    public function get_threat_keywords_high() {
        $sig = $this->get_signatures();
        return $sig['threat_keywords_high'];
    }

    public function get_threat_keywords_medium() {
        $sig = $this->get_signatures();
        return $sig['threat_keywords_medium'];
    }

    public function get_suspicious_email_domains() {
        $sig = $this->get_signatures();
        return $sig['suspicious_email_domains'];
    }

    public function sync_remote_signatures( $force = false ) {
        if ( ! $force ) {
            $last_checked = (int) get_site_option( 'wpis_signatures_last_checked', 0 );
            if ( time() - $last_checked < 86400 ) {
                return true;
            }
        }

        update_site_option( 'wpis_signatures_last_checked', time() );

        $response = wp_remote_get( self::REMOTE_SIGNATURES_URL, array(
            'timeout'    => 10,
            'sslverify'  => true,
            'user-agent' => 'WP-Incident-Sweeper/' . '2.20261015.7',
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'http_error', 'GitHubからのシグネチャ取得に失敗しました (HTTP ' . $code . ')' );
        }

        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( ! is_array( $json ) || empty( $json['threat_keywords_high'] ) ) {
            return new WP_Error( 'invalid_json', '取得したシグネチャデータの形式が不正です。' );
        }

        $clean_data = array(
            'version'                  => sanitize_text_field( isset( $json['version'] ) ? $json['version'] : date( 'Ymd' ) ),
            'updated_at'               => current_time( 'Y-m-d H:i:s' ),
            'threat_keywords_high'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) $json['threat_keywords_high'] ) ) ),
            'threat_keywords_medium'   => array_values( array_filter( array_map( 'sanitize_text_field', (array) $json['threat_keywords_medium'] ) ) ),
            'suspicious_email_domains' => array_values( array_filter( array_map( 'sanitize_text_field', (array) $json['suspicious_email_domains'] ) ) ),
        );

        update_site_option( 'wpis_signatures', $clean_data );
        update_site_option( 'wpis_signatures_synced_at', current_time( 'Y-m-d H:i:s' ) );

        return $clean_data;
    }

    public function ajax_sync_signatures() {
        if ( ! check_ajax_referer( 'wpis_nonce_action', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークン(Nonce)の検証に失敗しました。' ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network' ) ) {
            wp_send_json_error( array( 'message' => '操作権限がありません。' ), 403 );
        }

        $res = $this->sync_remote_signatures( true );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        $signatures = $this->get_signatures();
        wp_send_json_success( array(
            'message'    => 'GitHubから最新検知キーワードを同期・更新しました！',
            'version'    => $signatures['version'],
            'updated_at' => $signatures['updated_at'],
            'counts'     => array(
                'high'    => count( $signatures['threat_keywords_high'] ),
                'medium'  => count( $signatures['threat_keywords_medium'] ),
                'domains' => count( $signatures['suspicious_email_domains'] ),
            ),
        ) );
    }

    public function get_my_keywords( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return array();
        }
        $raw = get_user_meta( $user_id, 'wpis_my_keywords', true );
        if ( is_string( $raw ) ) {
            $lines = preg_split( '/[\r\n,]+/', $raw );
            return array_values( array_unique( array_filter( array_map( function( $w ) {
                return mb_strtolower( trim( sanitize_text_field( $w ) ), 'UTF-8' );
            }, $lines ) ) ) );
        }
        return is_array( $raw ) ? array_values( array_unique( array_filter( $raw ) ) ) : array();
    }

    public function set_my_keywords( $keywords, $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return array();
        }
        if ( is_string( $keywords ) ) {
            $lines = preg_split( '/[\r\n,]+/', $keywords );
            $clean = array_values( array_unique( array_filter( array_map( function( $w ) {
                return mb_strtolower( trim( sanitize_text_field( $w ) ), 'UTF-8' );
            }, $lines ) ) ) );
        } elseif ( is_array( $keywords ) ) {
            $clean = array_values( array_unique( array_filter( array_map( function( $w ) {
                return mb_strtolower( trim( sanitize_text_field( $w ) ), 'UTF-8' );
            }, $keywords ) ) ) );
        } else {
            $clean = array();
        }
        update_user_meta( $user_id, 'wpis_my_keywords', $clean );
        return $clean;
    }

    public function ajax_save_my_keywords() {
        if ( ! check_ajax_referer( 'wpis_nonce_action', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークン(Nonce)の検証に失敗しました。' ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network' ) ) {
            wp_send_json_error( array( 'message' => '操作権限がありません。' ), 403 );
        }

        $raw_kw = isset( $_POST['keywords'] ) ? wp_unslash( $_POST['keywords'] ) : '';
        $clean = $this->set_my_keywords( $raw_kw );

        wp_send_json_success( array(
            'message'  => 'マイKW（独自検知キーワード）を正常に保存しました！',
            'keywords' => $clean,
            'count'    => count( $clean ),
        ) );
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
            '2.20261015.7'
        );

        wp_enqueue_script(
            'wpis-admin-js',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            '2.20261015.7',
            true
        );

        $is_network = is_multisite() && is_network_admin();

        wp_localize_script( 'wpis-admin-js', 'wpisConfig', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'adminUrl'    => admin_url(),
            'isMultisite' => is_multisite(),
            'isNetwork'   => $is_network,
            'nonce'       => wp_create_nonce( 'wpis_nonce_action' ),
            'myKeywords'  => $this->get_my_keywords(),
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
        if ( ! check_ajax_referer( 'wpis_nonce_action', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークン(Nonce)の検証に失敗しました。ページを再読み込みしてから再度お試しください。' ), 403 );
        }

        $is_network_req = is_multisite() && ( isset( $_POST['is_network'] ) && '1' === $_POST['is_network'] );

        if ( $is_network_req ) {
            if ( ! current_user_can( 'manage_network' ) ) {
                wp_send_json_error( array( 'message' => 'ネットワーク横断スキャンの操作権限がありません（特権管理者権限が必要です）。' ), 403 );
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
            $site_objs = get_sites( array(
                'number'   => 200,
                'deleted'  => 0,
                'archived' => 0,
                'spam'     => 0,
            ) );
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

        // 1. ユーザーのスキャン
        $users_data = array();
        $threat_user_ids = array();
        $all_users = get_users();

        $start_july = strtotime( '2026-07-17 00:00:00' );
        $end_july   = strtotime( '2026-07-25 23:59:59' );

        $my_keywords = $this->get_my_keywords();

        foreach ( $all_users as $u ) {
            $is_threat = false;
            $reasons = array();

            if ( strpos( $u->user_login, 'wp2_' ) === 0 ) {
                $is_threat = true;
                $reasons[] = 'ユーザー名が "wp2_*" で開始';
            }

            $u_email = strtolower( $u->user_email );
            foreach ( $this->get_suspicious_email_domains() as $bad_domain ) {
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

            if ( ! empty( $my_keywords ) ) {
                $u_text = mb_strtolower( (string) $u->user_login . ' ' . (string) $u->display_name . ' ' . (string) $u->user_email, 'UTF-8' );
                foreach ( $my_keywords as $mkw ) {
                    if ( mb_strpos( $u_text, $mkw ) !== false ) {
                        $is_threat = true;
                        $reasons[] = '🎯 マイKW検知: "' . esc_html( $mkw ) . '"';
                        break;
                    }
                }
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

        // 2. 投稿・固定ページ・全カスタム投稿タイプのスキャン（switch_to_blogで完全横断）
        $exclude_types = array( 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
        $in_clause = "'" . implode( "','", array_map( 'esc_sql', $exclude_types ) ) . "'";

        $posts_data = array();
        $threat_post_count = 0;

        $kw_high = $this->get_threat_keywords_high();
        $kw_med  = $this->get_threat_keywords_medium();

        foreach ( $sites_to_scan as $target_site ) {
            $blog_id   = $target_site['id'];
            $site_name = $target_site['name'];

            if ( is_multisite() ) {
                switch_to_blog( $blog_id );
            }

            $protected_post_ids = $this->get_protected_post_ids();

            // 1. 本文（post_content）の改ざんシグネチャをSQLで超高速抽出（メモリ消費ゼロ）
            $high_likes = array();
            foreach ( $kw_high as $kw ) {
                $high_likes[] = "p.post_content LIKE " . $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $kw ) . '%' );
            }
            $threat_high_ids = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type NOT IN ({$in_clause}) AND (" . implode( ' OR ', $high_likes ) . ")"
            ) );

            // ヒットした危険記事のみ本文を取得し、具体的なキーワードを特定（記事数0〜数件のため負荷ゼロ）
            $high_matched_words = array();
            if ( ! empty( $threat_high_ids ) ) {
                $ids_in = implode( ',', $threat_high_ids );
                $threat_rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$ids_in})" );
                foreach ( $threat_rows as $tr ) {
                    $c_lower = mb_strtolower( (string) $tr->post_content, 'UTF-8' );
                    $m_list = array();
                    foreach ( $kw_high as $kw ) {
                        if ( mb_strpos( $c_lower, $kw ) !== false ) {
                            $m_list[] = $kw;
                        }
                    }
                    $high_matched_words[ (int) $tr->ID ] = $m_list;
                }
            }

            // 2. 本文のスパム・不審コードをSQLで超高速抽出
            $med_likes = array();
            foreach ( $kw_med as $kw ) {
                $med_likes[] = "p.post_content LIKE " . $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $kw ) . '%' );
            }
            $threat_med_ids = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type NOT IN ({$in_clause}) AND (" . implode( ' OR ', $med_likes ) . ")"
            ) );

            $med_matched_words = array();
            if ( ! empty( $threat_med_ids ) ) {
                $ids_in = implode( ',', $threat_med_ids );
                $threat_rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$ids_in})" );
                foreach ( $threat_rows as $tr ) {
                    $c_lower = mb_strtolower( (string) $tr->post_content, 'UTF-8' );
                    $m_list = array();
                    foreach ( $kw_med as $kw ) {
                        if ( mb_strpos( $c_lower, $kw ) !== false ) {
                            $m_list[] = $kw;
                        }
                    }
                    $med_matched_words[ (int) $tr->ID ] = $m_list;
                }
            }

            // 3. 本文のマイKW（独自指定キーワード）をSQLで超高速抽出
            $my_matched_words = array();
            if ( ! empty( $my_keywords ) ) {
                $my_likes = array();
                foreach ( $my_keywords as $kw ) {
                    $my_likes[] = "p.post_content LIKE " . $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $kw ) . '%' );
                }
                $threat_my_ids = array_map( 'intval', (array) $wpdb->get_col(
                    "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type NOT IN ({$in_clause}) AND (" . implode( ' OR ', $my_likes ) . ")"
                ) );

                if ( ! empty( $threat_my_ids ) ) {
                    $ids_in = implode( ',', $threat_my_ids );
                    $threat_rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$ids_in})" );
                    foreach ( $threat_rows as $tr ) {
                        $c_lower = mb_strtolower( (string) $tr->post_content, 'UTF-8' );
                        $m_list = array();
                        foreach ( $my_keywords as $kw ) {
                            if ( mb_strpos( $c_lower, $kw ) !== false ) {
                                $m_list[] = $kw;
                            }
                        }
                        $my_matched_words[ (int) $tr->ID ] = $m_list;
                    }
                }
            }

            // 4. メインクエリ（巨大な post_content を除外してメモリ枯渇・500/504エラーを完全防止）
            $sql = "
                SELECT p.ID, p.post_title, p.post_name, p.post_type, p.post_status, p.post_author, p.post_modified, u.display_name AS author_name
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
                        $reasons[] = '🚨 不審ユーザー作成記事 (' . esc_html( $author_name ) . ')';
                    }

                    // 本文内の改ざんシグネチャ検知（具体的な単語を表示）
                    if ( isset( $high_matched_words[ $post_id ] ) && ! empty( $high_matched_words[ $post_id ] ) ) {
                        $severity = 'high';
                        $words_str = implode( '", "', array_slice( $high_matched_words[ $post_id ], 0, 3 ) );
                        $reasons[] = '🚨 本文改ざん痕跡: "' . esc_html( $words_str ) . '"';
                    }

                    // タイトル・スラッグ内の危険キーワード検知（具体的な単語を表示）
                    $title_slug_text = mb_strtolower( (string) $title . ' ' . (string) $slug, 'UTF-8' );
                    foreach ( $kw_high as $kw ) {
                        if ( mb_strpos( $title_slug_text, $kw ) !== false ) {
                            $severity = 'high';
                            $reasons[] = '🚨 タイトル改ざん: "' . esc_html( $kw ) . '"';
                            break;
                        }
                    }

                    // 中危険度（本文またはタイトル・スラッグの具体単語）
                    if ( $severity !== 'high' ) {
                        if ( isset( $med_matched_words[ $post_id ] ) && ! empty( $med_matched_words[ $post_id ] ) ) {
                            $severity = 'medium';
                            $words_str = implode( '", "', array_slice( $med_matched_words[ $post_id ], 0, 3 ) );
                            $reasons[] = '⚠️ 本文スパム/不審コード: "' . esc_html( $words_str ) . '"';
                        } else {
                            foreach ( $kw_med as $kw ) {
                                if ( mb_strpos( $title_slug_text, $kw ) !== false ) {
                                    $severity = 'medium';
                                    $reasons[] = '⚠️ タイトルスパム: "' . esc_html( $kw ) . '"';
                                    break;
                                }
                            }
                        }
                    }

                    // 🎯 マイKW検知（本文）
                    if ( isset( $my_matched_words[ $post_id ] ) && ! empty( $my_matched_words[ $post_id ] ) ) {
                        if ( 'clean' === $severity || 'low' === $severity ) {
                            $severity = 'high';
                        }
                        $words_str = implode( '", "', array_slice( $my_matched_words[ $post_id ], 0, 3 ) );
                        $reasons[] = '🎯 マイKW検知(本文): "' . esc_html( $words_str ) . '"';
                    }

                    // 🎯 マイKW検知（タイトル・スラッグ）
                    if ( ! empty( $my_keywords ) ) {
                        foreach ( $my_keywords as $mkw ) {
                            if ( mb_strpos( $title_slug_text, $mkw ) !== false ) {
                                if ( 'clean' === $severity || 'low' === $severity ) {
                                    $severity = 'high';
                                }
                                $reasons[] = '🎯 マイKW検知(タイトル): "' . esc_html( $mkw ) . '"';
                                break;
                            }
                        }
                    }

                    if ( $severity === 'clean' && in_array( $status, array( 'draft', 'trash', 'pending', 'private' ), true ) ) {
                        $severity = 'low';
                        $reasons[] = 'ℹ️ 非公開/下書き/ゴミ箱';
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

        $sig_info = $this->get_signatures();
        wp_send_json_success( array(
            'posts'             => $posts_data,
            'users'             => $users_data,
            'sites'             => $sites_to_scan,
            'my_keywords'       => $my_keywords,
            'my_keywords_count' => count( $my_keywords ),
            'signatures'        => array(
                'version'    => $sig_info['version'],
                'updated_at' => $sig_info['updated_at'],
                'high_count' => count( $sig_info['threat_keywords_high'] ),
                'med_count'  => count( $sig_info['threat_keywords_medium'] ),
            ),
            'summary'    => array(
                'totalSites'  => count( $sites_to_scan ),
                'totalPosts'  => count( $posts_data ),
                'threatPosts' => $threat_post_count,
                'totalUsers'  => count( $users_data ),
                'threatUsers' => count( $threat_user_ids ),
            ),
        ) );
    }

    /**
     * Ajax: 選択した投稿の一括削除/ゴミ箱移動（マルチサイト安全対応）
     */
    public function ajax_delete_posts() {
        if ( ! check_ajax_referer( 'wpis_nonce_action', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークン(Nonce)の検証に失敗しました。ページを再読み込みしてください。' ), 403 );
        }

        $raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
        $items = json_decode( $raw_items, true );
        $force_delete = ( isset( $_POST['force_delete'] ) && '1' === $_POST['force_delete'] );

        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( array( 'message' => '削除対象が選択されていません。' ) );
        }

        $deleted_count = 0;
        $skipped_count = 0;

        foreach ( $items as $item ) {
            $pid     = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
            $blog_id = isset( $item['blog_id'] ) ? absint( $item['blog_id'] ) : get_current_blog_id();

            if ( $pid <= 0 ) continue;

            // マルチサイトで他サイトの投稿を操作する場合、特権管理者権限を必須化
            if ( is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id() ) {
                if ( ! current_user_can( 'manage_network' ) ) {
                    $skipped_count++;
                    continue;
                }
            }

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
                $check_text = mb_strtolower( (string) $post->post_title . ' ' . (string) $post->post_name . ' ' . (string) $post->post_content, 'UTF-8' );
                $is_confirmed_threat = false;
                foreach ( $this->get_threat_keywords_high() as $kw ) {
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
        if ( ! check_ajax_referer( 'wpis_nonce_action', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークン(Nonce)の検証に失敗しました。ページを再読み込みしてください。' ), 403 );
        }

        if ( is_multisite() ) {
            if ( ! current_user_can( 'manage_network_users' ) ) {
                wp_send_json_error( array( 'message' => 'マルチサイト環境でのユーザー削除権限がありません（サイトネットワーク管理者・特権管理者権限が必要です）。' ), 403 );
            }
        } else {
            if ( ! current_user_can( 'delete_users' ) ) {
                wp_send_json_error( array( 'message' => 'ユーザーの削除権限がありません（delete_usersが必要です）。' ), 403 );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        if ( is_multisite() ) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }

        global $wpdb;

        $raw_ids = isset( $_POST['user_ids'] ) ? wp_unslash( $_POST['user_ids'] ) : '[]';
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
            $is_explicit_attacker = false;

            if ( strpos( $user_obj->user_login, 'wp2_' ) === 0 ) {
                $is_threat_user = true;
                $is_explicit_attacker = true;
            }
            $u_email = strtolower( $user_obj->user_email );
            foreach ( $this->get_suspicious_email_domains() as $bad_domain ) {
                if ( strpos( $u_email, $bad_domain ) !== false ) {
                    $is_threat_user = true;
                    $is_explicit_attacker = true;
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

            // 特権管理者（Super Admin）の巻き込み事故防止ガード:
            // 攻撃シグネチャ（wp2_ または 不審ドメイン）に明示一致しない限り、特権管理者は削除不可
            if ( is_multisite() && is_super_admin( $uid ) && ! $is_explicit_attacker ) {
                $skipped_count++;
                continue;
            }

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
     * 管理画面UIレンダリング（単サイト・マルチサイトを完全切り分け）
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
                        インシデント巡回スイーパー <?php echo $is_network ? '<span class="wpis-badge wpis-badge-danger" style="font-size:12px;margin-left:8px;">全傘下サイト横断モード</span>' : ''; ?>
                    </h1>
                    <p>
                        <?php if ( $is_network ) : ?>
                            ネットワーク傘下の<strong>すべての子サイト（サブサイト）</strong>を一括横断スキャンし、不審なアカウントや改ざん下書きを一網打尽に検知・駆除します。
                        <?php else : ?>
                            サイト内の全コンテンツ（公開・下書き・ゴミ箱）およびユーザーを一括クロールし、不正な痕跡を特定・安全に駆除します。
                        <?php endif; ?>
                    </p>
                    <?php 
                    $sig = $this->get_signatures(); 
                    $my_kw = $this->get_my_keywords();
                    ?>
                    <div style="margin-top:8px;font-size:12px;color:#64748b;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="background:#f1f5f9;border:1px solid #e2e8f0;padding:2px 8px;border-radius:4px;">
                            🛡️ 検知シグネチャ: <code id="wpis-sig-version" style="font-weight:bold;color:#0f172a;"><?php echo esc_html( $sig['version'] ); ?></code>
                            （危険: <strong id="wpis-sig-high" style="color:#dc2626;"><?php echo count( $sig['threat_keywords_high'] ); ?></strong>件 / 警告: <strong id="wpis-sig-med" style="color:#d97706;"><?php echo count( $sig['threat_keywords_medium'] ); ?></strong>件）
                        </span>
                        <span style="background:#faf5ff;border:1px solid #e9d5ff;padding:2px 8px;border-radius:4px;color:#6b21a8;">
                            🎯 マイKW: <strong id="wpis-badge-my-kw"><?php echo count( $my_kw ); ?></strong>件
                        </span>
                        <span style="font-size:11px;color:#94a3b8;">最終同期: <span id="wpis-sig-date"><?php echo esc_html( $sig['updated_at'] ); ?></span></span>
                    </div>

                    <?php if ( is_multisite() && ! $is_network ) : ?>
                    <div style="margin-top:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:8px 12px;font-size:12px;color:#0369a1;">
                        ℹ️ <strong>サブサイト個別モード:</strong> 現在のサイト（<?php bloginfo( 'name' ); ?>）のみを対象にスキャンしています。全傘下サイトを一括横断スキャンする場合は、<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=wp-incident-sweeper' ) ); ?>" style="color:#0284c7;font-weight:bold;text-decoration:underline;">サイトネットワーク管理のスイーパー</a> をご利用ください。
                    </div>
                    <?php endif; ?>
                </div>
                <div class="wpis-actions">
                    <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-my-kw" title="あなたが個別に検知したいキーワードを設定・管理します">
                        <span class="dashicons dashicons-admin-generic" style="font-size:16px;width:16px;height:16px;"></span>
                        マイKW設定 (<span id="wpis-my-kw-btn-count"><?php echo count( $my_kw ); ?></span>)
                    </button>
                    <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-sync-kw" title="GitHubから最新の検知キーワード定義を取得・更新します">
                        <span class="dashicons dashicons-cloud" style="font-size:16px;width:16px;height:16px;"></span>
                        最新KW更新 (GitHub)
                    </button>
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
                <?php if ( $is_network ) : ?>
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
                    <?php echo $is_network ? '巡回ガイド＆マルチサイト運用' : '巡回チェックガイド＆安全ガード仕様'; ?>
                </button>
            </div>

            <!-- Panel: Posts -->
            <div class="wpis-panel is-active" id="wpis-panel-posts">
                <div class="wpis-table-toolbar">
                    <div class="wpis-filter-group">
                        <?php if ( $is_network ) : ?>
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
                                <?php if ( $is_network ) : ?><th style="width:140px;">対象サイト</th><?php endif; ?>
                                <th>タイトル</th>
                                <th style="width:90px;">タイプ</th>
                                <th style="width:80px;">状態</th>
                                <th style="width:120px;">作成者</th>
                                <th style="width:140px;">最終更新日時</th>
                                <th>検知理由</th>
                            </tr>
                        </thead>
                        <tbody id="wpis-posts-tbody">
                            <tr><td colspan="<?php echo $is_network ? '9' : '8'; ?>" class="wpis-empty-state">スキャン準備中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel: Users -->
            <div class="wpis-panel" id="wpis-panel-users">
                <div class="wpis-table-toolbar">
                    <div class="wpis-filter-group">
                        <span style="font-size:13px;color:#64748b;">
                            <?php if ( is_multisite() && ! $is_network ) : ?>
                                ℹ️ <strong>ユーザー監査（閲覧のみ）:</strong> マルチサイト構成では、ユーザーの完全削除はサイトネットワーク管理者（特権管理者）画面（<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=wp-incident-sweeper' ) ); ?>" style="color:#0284c7;font-weight:bold;text-decoration:underline;">/wp-admin/network/</a>）からのみ実行可能です。
                            <?php elseif ( $is_network ) : ?>
                                🛡️ <strong>マルチサイト安全保護:</strong> ネットワーク管理画面から不審ユーザーを削除すると、全傘下サイトに散らばった不正下書きが一括完全削除され、公開中の正規記事は安全に保護されます。
                            <?php else : ?>
                                🛡️ <strong>安全保護機能:</strong> 不審ユーザー削除時、下書き等の不正記事のみ消去され、公開中の正規記事は現在の管理者に安全に引き継がれます。
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="wpis-bulk-actions">
                        <?php if ( ! ( is_multisite() && ! $is_network ) ) : ?>
                        <button type="button" class="wpis-btn wpis-btn-danger wpis-btn-sm" id="wpis-btn-delete-users" disabled>
                            選択した不審ユーザーを削除
                        </button>
                        <?php endif; ?>
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
                    <?php if ( $is_network ) : ?>
                        <h2 style="font-size:16px;margin-top:0;">🌐 マルチサイト（サイトネットワーク）での運用手順</h2>
                        <ol style="padding-left:20px;font-size:13px;color:#334155;">
                            <li><strong>全傘下サイトの一括横断スキャン:</strong> 「全サイト横断スキャン実行」を押すと、配下のすべての子サイトを全自動で巡回クロールします。</li>
                            <li><strong>サイト別フィルター:</strong> 投稿一覧上部の「全傘下サイト」から特定の子サイトに絞り込んで確認できます。</li>
                            <li><strong>一括駆除:</strong> 危険なアイテムをチェックして削除すると、該当する子サイトのDBを特定して安全に消去されます。</li>
                        </ol>
                    <?php else : ?>
                        <h2 style="font-size:16px;margin-top:0;">🛡️ 巡回時の確認ポイントと運用手順</h2>
                        <ol style="padding-left:20px;font-size:13px;color:#334155;">
                            <li><strong>自動スキャン:</strong> 画面を開くとサイト内の全コンテンツとユーザーが自動でクロールされます。</li>
                            <li><strong>危険項目を一括選択:</strong> 改ざん記事（<code>Hacked by...</code>）や不審ユーザー作成記事があれば、ボタン1つで一括チェックが入ります。</li>
                            <li><strong>一括完全削除:</strong> 「選択した項目を完全削除」を押せば、ゴミ箱を経由せず一発で消去されます（通常記事はゴミ箱へ退避可能）。</li>
                        </ol>
                    <?php endif; ?>

                    <h3 style="font-size:15px;margin-top:24px;">🛡️ 巻き込み事故を防止する「5つの多重安全ガード」</h3>
                    <ul style="padding-left:20px;font-size:13px;color:#334155;">
                        <li><strong>ガード1（システム重要ページの完全保護）：</strong> トップページ、ブログ一覧、プライバシーポリシーページは削除対象から強制除外。</li>
                        <li><strong>ガード2（許可された投稿タイプのみ対象）：</strong> 投稿、固定ページ、公開カスタム投稿タイプのみが対象。画像実体やメニューは保護。</li>
                        <li><strong>ガード3（公開中記事の誤完全削除ブロック）：</strong> 公開中の通常記事は完全削除を拒否し、ゴミ箱へ安全退避。</li>
                        <li><strong>ガード4（公開記事の所有権安全移譲）：</strong> 不審ユーザー削除時、下書き等の不正記事のみ消去され、公開中記事は現在管理者に引き継ぎ。</li>
                        <li><strong>ガード5（自身・初期管理者の保護）：</strong> ログイン中管理者およびID: 1 のアカウントは削除不可。</li>
                    </ul>
                </div>
            </div>

            <!-- My KW Modal Dialog -->
            <div id="wpis-my-kw-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:99999;align-items:center;justify-content:center;">
                <div style="background:#fff;border-radius:12px;padding:24px;width:90%;max-width:540px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);position:relative;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px;">
                        <h3 style="margin:0;font-size:17px;font-weight:700;display:flex;align-items:center;gap:8px;color:#0f172a;">
                            <span>🎯</span> マイKW設定（個別検知キーワード）
                        </h3>
                        <button type="button" id="wpis-btn-close-my-kw" style="background:none;border:none;font-size:24px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
                    </div>
                    <p style="font-size:13px;color:#475569;margin-bottom:12px;line-height:1.5;">
                        あなたが個別に監視・検知したいキーワードを登録できます。<br>
                        登録したキーワードは全記事のタイトル・本文・ユーザー名から自動検知され、一覧上に <code style="color:#6b21a8;background:#f3e8ff;padding:1px 5px;border-radius:3px;">🎯 マイKW検知</code> バッジで分かりやすく表示されます。
                    </p>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px;">
                            検知キーワード（1行に1つ、またはカンマ区切りで入力）:
                        </label>
                        <textarea id="wpis-my-kw-input" rows="6" style="width:100%;font-family:monospace;font-size:13px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;" placeholder="例:&#10;自社固有のNGワード&#10;不審な特定ドメイン&#10;情報漏洩の兆候文字列"><?php echo esc_textarea( implode( "\n", $my_kw ) ); ?></textarea>
                        <div style="font-size:11px;color:#94a3b8;margin-top:4px;">※ 大文字・小文字は区別されず自動的に小文字で照合されます。</div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-cancel-my-kw">キャンセル</button>
                        <button type="button" class="wpis-btn wpis-btn-primary" id="wpis-btn-save-my-kw">保存して再スキャン</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// 起動
add_action( 'plugins_loaded', array( 'WP_Incident_Sweeper', 'get_instance' ) );
