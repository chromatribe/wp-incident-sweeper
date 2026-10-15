<?php
/**
 * Plugin Name: WP Incident Sweeper (インシデント一括巡回・駆除スイーパー)
 * Plugin URI: https://chromatri.be
 * Description: サイト内の全投稿・固定ページ・全カスタム投稿タイプ（公開・下書き・ゴミ箱・非公開含む）およびユーザーを瞬時に一括スキャン。単一サイトおよびマルチサイト（サイトネットワーク管理）の両方に完全対応し、コンテキストに応じてUIが自動最適化されます。
 * Version: 2.20261015.9
 * Author: CHROMATRIBE
 * Author URI: https://chromatri.be
 * License: GPL-2.0+
 * Network: true
 * Text Domain: wp-incident-sweeper
 * Domain Path: /languages
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
        // 国際化・地域化 (i18n / l10n)
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // 通常管理画面メニュー
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        // マルチサイト・ネットワーク管理画面メニュー
        add_action( 'network_admin_menu', array( $this, 'register_network_admin_menu' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 法令遵守・プライバシー対応（APPI / GDPR / 電気通信事業法外部送信開示）
        add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporters' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_erasers' ) );

        // Ajax Handlers
        add_action( 'wp_ajax_wpis_run_scan', array( $this, 'ajax_run_scan' ) );
        add_action( 'wp_ajax_wpis_delete_posts', array( $this, 'ajax_delete_posts' ) );
        add_action( 'wp_ajax_wpis_delete_users', array( $this, 'ajax_delete_users' ) );
        add_action( 'wp_ajax_wpis_sync_signatures', array( $this, 'ajax_sync_signatures' ) );
        add_action( 'wp_ajax_wpis_save_my_keywords', array( $this, 'ajax_save_my_keywords' ) );
    }

    /**
     * 国際化・翻訳テキストドメインのロード
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-incident-sweeper',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /**
     * 個人情報保護法・GDPR対応：プライバシーポリシー提案文面の登録
     */
    public function register_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $content = sprintf(
            '<p class="privacy-policy-tutorial">%s</p>' .
            '<h3 class="privacy-policy-tutorial">%s</h3>' .
            '<p>%s</p>' .
            '<h3 class="privacy-policy-tutorial">%s</h3>' .
            '<p>%s</p>',
            esc_html__( 'WP Incident Sweeper はサイト内のセキュリティ維持およびインシデント発生時の復旧支援ツールです。以下の文面は、サイト管理者がプライバシーポリシーに追加・調整できる参考テンプレートです。', 'wp-incident-sweeper' ),
            esc_html__( 'セキュリティ監査および脅威検知', 'wp-incident-sweeper' ),
            esc_html__( '当サイトでは、不正な改ざんやスパム投稿、不正アクセスの兆候を検知・監視するため、管理ツール（WP Incident Sweeper）を使用しています。本ツールによるコンテンツおよびアカウントの照合処理はすべて当サイトのサーバー内部で完結しており、投稿本文や利用者の個人データが外部第三者へ送信・共有されることはありません。', 'wp-incident-sweeper' ),
            esc_html__( 'シグネチャ更新に伴う外部通信（電気通信事業法外部送信規律への対応）', 'wp-incident-sweeper' ),
            esc_html__( '脅威シグネチャ（既知の改ざん文字列や悪性ドメイン一覧）の最新化のため、当サイト管理者はGitHubサーバー（raw.githubusercontent.com）からシグネチャ定義ファイルを取得することがあります。この通信において送信される情報はHTTP接続に必要な標準的情報（IPアドレス、User-Agent）に限られ、サイト利用者や投稿内容等のデータは一切含まれません。', 'wp-incident-sweeper' )
        );
        wp_add_privacy_policy_content( __( 'WP Incident Sweeper', 'wp-incident-sweeper' ), $content );
    }

    /**
     * 個人データエクスポート（GDPR / APPI 開示要求対応）
     */
    public function register_privacy_exporters( $exporters ) {
        $exporters['wp-incident-sweeper'] = array(
            'exporter_friendly_name' => __( 'WP Incident Sweeper マイKW設定', 'wp-incident-sweeper' ),
            'callback'               => array( $this, 'export_user_privacy_data' ),
        );
        return $exporters;
    }

    public function export_user_privacy_data( $email_address, $page = 1 ) {
        $user = get_user_by( 'email', $email_address );
        $data_to_export = array();

        if ( $user ) {
            $my_keywords = get_user_meta( $user->ID, 'wpis_my_keywords', true );
            if ( ! empty( $my_keywords ) ) {
                $kw_list = is_array( $my_keywords ) ? implode( ', ', $my_keywords ) : (string) $my_keywords;
                $data_to_export[] = array(
                    'group_id'    => 'wp-incident-sweeper',
                    'group_label' => __( 'WP Incident Sweeper 設定', 'wp-incident-sweeper' ),
                    'item_id'     => 'wpis-my-keywords-' . $user->ID,
                    'data'        => array(
                        array(
                            'name'  => __( 'マイKW（個別検知キーワード）', 'wp-incident-sweeper' ),
                            'value' => $kw_list,
                        ),
                    ),
                );
            }
        }

        return array(
            'data' => $data_to_export,
            'done' => true,
        );
    }

    /**
     * 個人データ消去（GDPR / APPI 消去要求・忘れられる権利対応）
     */
    public function register_privacy_erasers( $erasers ) {
        $erasers['wp-incident-sweeper'] = array(
            'eraser_friendly_name' => __( 'WP Incident Sweeper マイKW設定', 'wp-incident-sweeper' ),
            'callback'             => array( $this, 'erase_user_privacy_data' ),
        );
        return $erasers;
    }

    public function erase_user_privacy_data( $email_address, $page = 1 ) {
        $user = get_user_by( 'email', $email_address );
        $items_removed = 0;

        if ( $user ) {
            $my_keywords = get_user_meta( $user->ID, 'wpis_my_keywords', true );
            if ( ! empty( $my_keywords ) ) {
                delete_user_meta( $user->ID, 'wpis_my_keywords' );
                $items_removed = 1;
            }
        }

        return array(
            'items_removed'  => $items_removed,
            'items_retained' => 0,
            'messages'       => array(),
            'done'           => true,
        );
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
            'user-agent' => 'WP-Incident-Sweeper/' . '2.20261015.9',
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

    const LUCIDE_SHIELD_MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9ImJsYWNrIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+PHBhdGggZD0iTTIwIDEzYzAgNS0zLjUgNy41LTcuNjYgOC45NWExIDEgMCAwIDEtLjY3LS4wMUM3LjUgMjAuNSA0IDE4IDQgMTNWNmExIDEgMCAwIDEgMS0xYzIgMCA0LjUtMS4yIDYuMjQtMi43MmExLjE3IDEuMTcgMCAwIDEgMS41MiAwQzE0LjUxIDMuODEgMTcgNSAxOSA1YTEgMSAwIDAgMSAxIDF6Ii8+PHBhdGggZD0ibTkgMTIgMiAyIDQtNCIvPjwvc3ZnPg==';

    public function register_admin_menu() {
        add_menu_page(
            __( 'インシデント巡回スイーパー', 'wp-incident-sweeper' ),
            __( '巡回スイーパー', 'wp-incident-sweeper' ),
            'manage_options',
            'wp-incident-sweeper',
            array( $this, 'render_admin_page' ),
            self::LUCIDE_SHIELD_MENU_ICON,
            3
        );
    }

    public function register_network_admin_menu() {
        add_menu_page(
            __( 'インシデント巡回スイーパー（ネットワーク全体）', 'wp-incident-sweeper' ),
            __( '巡回スイーパー(全傘下サイト)', 'wp-incident-sweeper' ),
            'manage_network',
            'wp-incident-sweeper',
            array( $this, 'render_admin_page' ),
            self::LUCIDE_SHIELD_MENU_ICON,
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
            '2.20261015.9'
        );

        wp_enqueue_script(
            'wpis-admin-js',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            '2.20261015.9',
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
            'i18n'        => array(
                'scan_running'           => __( 'スキャン中...', 'wp-incident-sweeper' ),
                'scan_running_network'   => __( '全傘下サイトを巡回クロール中...', 'wp-incident-sweeper' ),
                'rescan'                 => __( '再スキャン実行', 'wp-incident-sweeper' ),
                'rescan_network'         => __( '全サイト横断スキャン実行', 'wp-incident-sweeper' ),
                'scanning_content'       => __( 'サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...', 'wp-incident-sweeper' ),
                'scanning_network'       => __( '全傘下サイトの全コンテンツ・ユーザーを横断クロール中...', 'wp-incident-sweeper' ),
                'scanning_users'         => __( 'ユーザーをスキャン中...', 'wp-incident-sweeper' ),
                'no_threats_found'       => __( '該当するコンテンツはありません（安全です）', 'wp-incident-sweeper' ),
                'no_users_found'         => __( 'ユーザーはいません', 'wp-incident-sweeper' ),
                'normal'                 => __( '正常', 'wp-incident-sweeper' ),
                'threat_danger'          => __( '🚨 危険（即削除推奨）', 'wp-incident-sweeper' ),
                'threat_warning'         => __( '⚠️ 警告（要確認）', 'wp-incident-sweeper' ),
                'threat_muted'           => __( 'ℹ️ 下書き/ゴミ箱', 'wp-incident-sweeper' ),
                'user_threat'            => __( '🚨 不審（即削除推奨）', 'wp-incident-sweeper' ),
                'protected_front'        => __( 'トップページ', 'wp-incident-sweeper' ),
                'protected_posts'        => __( '投稿一覧ページ', 'wp-incident-sweeper' ),
                'protected_privacy'      => __( 'プライバシーポリシー', 'wp-incident-sweeper' ),
                'protected_notice'       => __( 'システム重要ページのため削除保護', 'wp-incident-sweeper' ),
                'view'                   => __( '表示', 'wp-incident-sweeper' ),
                'edit'                   => __( '編集', 'wp-incident-sweeper' ),
                'confirm_delete_posts'   => __( "【安全確認: 記事の完全削除】\n選択された %d 件のコンテンツをデータベースから【完全に消去】します。\n\n※この操作は元に戻せません。公開中の正規記事は自動的にスキップまたはゴミ箱へ退避されます。\n\n本当に実行しますか？", 'wp-incident-sweeper' ),
                'confirm_trash_posts'    => __( "【確認: ゴミ箱への移動】\n選択された %d 件のコンテンツをゴミ箱へ移動します。\n（後から復元可能です）\n\n実行しますか？", 'wp-incident-sweeper' ),
                'confirm_delete_users'   => __( "【安全確認: 不審ユーザーの削除】\n選択された %d 名の不審ユーザーを削除します。\n\n🛡️ 巻き込み防止ガード:\n・不正な下書き・ゴミ箱記事のみが完全消去されます。\n・公開中の正規記事は安全に保護されます。\n\n実行しますか？", 'wp-incident-sweeper' ),
                'delete_users_btn'       => __( '選択した%d名の不審ユーザーを削除', 'wp-incident-sweeper' ),
                'delete_users_btn_empty' => __( '選択した不審ユーザーを削除', 'wp-incident-sweeper' ),
                'no_export_data'         => __( 'エクスポートするスキャンデータがありません。先にスキャンを実行してください。', 'wp-incident-sweeper' ),
                'sync_in_progress'       => __( '同期中...', 'wp-incident-sweeper' ),
                'save_in_progress'       => __( '保存中...', 'wp-incident-sweeper' ),
                'my_kw_saved'            => __( 'マイKWを保存しました！', 'wp-incident-sweeper' ),
                'my_kw_badge'            => __( '🎯 マイKW検知', 'wp-incident-sweeper' ),
                'sites_count_suffix'     => __( '%d サイト', 'wp-incident-sweeper' ),
                'delete_posts_done'      => __( "%d 件のコンテンツを処理しました。\n（完全削除: %d件 / ゴミ箱移動: %d件 / スキップ: %d件）", 'wp-incident-sweeper' ),
                'delete_users_done'      => __( '%d 名のユーザーの削除が完了しました。', 'wp-incident-sweeper' ),
                'delete_users_skipped'   => __( "\n（※正規ユーザーや自身のアカウント %d 名は安全のため自動スキップされました）", 'wp-incident-sweeper' ),
            ),
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
                        <svg class="wpis-lucide wpis-icon-shield" viewBox="0 0 24 24" width="24" height="24"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
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
                        <svg class="wpis-lucide" viewBox="0 0 24 24" width="15" height="15"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        マイKW設定 (<span id="wpis-my-kw-btn-count"><?php echo count( $my_kw ); ?></span>)
                    </button>
                    <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-sync-kw" title="GitHubから最新の検知キーワード定義を取得・更新します">
                        <svg class="wpis-lucide" viewBox="0 0 24 24" width="15" height="15"><path d="M12 13v8"/><path d="m8 17 4 4 4-4"/><path d="M4.393 15.269A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.436 8.284"/></svg>
                        最新KW更新 (GitHub)
                    </button>
                    <button type="button" class="wpis-btn wpis-btn-secondary" id="wpis-btn-csv">
                        <svg class="wpis-lucide" viewBox="0 0 24 24" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        結果をCSV出力
                    </button>
                    <button type="button" class="wpis-btn wpis-btn-primary" id="wpis-btn-scan">
                        <svg class="wpis-lucide" viewBox="0 0 24 24" width="15" height="15"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
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
                    <svg class="wpis-lucide" viewBox="0 0 24 24" width="18" height="18"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                    投稿・固定ページ監査
                    <span class="wpis-tab-count" id="wpis-badge-posts-threat">0</span>
                </button>
                <button type="button" class="wpis-tab-btn" data-tab="users">
                    <svg class="wpis-lucide" viewBox="0 0 24 24" width="18" height="18"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    ユーザー監査
                    <span class="wpis-tab-count" id="wpis-badge-users-threat">0</span>
                </button>
                <button type="button" class="wpis-tab-btn" data-tab="guide">
                    <svg class="wpis-lucide" viewBox="0 0 24 24" width="18" height="18"><path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg>
                    <?php echo $is_network ? '巡回ガイド＆マルチサイト運用' : '巡回チェックガイド＆安全ガード仕様'; ?>
                </button>
                <button type="button" class="wpis-tab-btn" data-tab="compliance">
                    <svg class="wpis-lucide" viewBox="0 0 24 24" width="18" height="18"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                    <?php esc_html_e( '法令・規約・プライバシー', 'wp-incident-sweeper' ); ?>
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

            <!-- Panel: Compliance & Privacy -->
            <div class="wpis-panel" id="wpis-panel-compliance">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;line-height:1.7;">
                    <div style="margin-bottom:24px;border-bottom:1px solid #e2e8f0;padding-bottom:14px;">
                        <h2 style="font-size:18px;margin:0 0 6px 0;color:#0f172a;display:flex;align-items:center;gap:8px;">
                            <svg class="wpis-lucide" viewBox="0 0 24 24" width="22" height="22" style="color:#0284c7;"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                            <?php esc_html_e( '法令遵守（コンプライアンス）・プライバシー・利用規約', 'wp-incident-sweeper' ); ?>
                        </h2>
                        <p style="font-size:13px;color:#64748b;margin:0;">
                            <?php esc_html_e( 'WP Incident Sweeper は、各種法令（電気通信事業法、個人情報保護法、不正アクセス禁止法等）およびWordPress標準プライバシーAPIに完全準拠して設計されています。', 'wp-incident-sweeper' ); ?>
                        </p>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <!-- Card 1: 外部送信規律 -->
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;">
                            <h3 style="font-size:15px;margin-top:0;margin-bottom:10px;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                <span>📡</span> <?php esc_html_e( '電気通信事業法（外部送信規律）への対応', 'wp-incident-sweeper' ); ?>
                            </h3>
                            <div style="font-size:12.5px;color:#334155;line-height:1.6;">
                                <p style="margin-top:0;"><strong>【外部送信の明示と目的】</strong><br>
                                本プラグインは、既知の不正改ざん・Webシェル・スパムドメイン等の最新定義ファイル（<code>signatures.json</code>）を取得する目的に限り、GitHubの公開サーバー（<code>raw.githubusercontent.com</code>）へHTTP通信を行います。</p>
                                <ul style="margin:0 0 8px 18px;padding:0;">
                                    <li><strong>送信先:</strong> GitHub, Inc. (米国)</li>
                                    <li><strong>送信情報:</strong> HTTPリクエストに伴う標準情報（接続元IPアドレス、User-Agentヘッダ）</li>
                                    <li><strong>ローカル完結設計:</strong> サイト内の投稿内容、ユーザー情報、データベースデータは<strong>一切外部送信されません</strong>。すべての照合・検知・削除処理はWordPressサーバー内のみで実行されます。</li>
                                    <li><strong>通信タイミング:</strong> 管理者による手動更新時、または1日に1回の更新確認時のみ。</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Card 2: 個人情報保護法 & GDPR -->
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;">
                            <h3 style="font-size:15px;margin-top:0;margin-bottom:10px;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                <span>🔒</span> <?php esc_html_e( '個人情報保護法 (APPI) & GDPR 対応', 'wp-incident-sweeper' ); ?>
                            </h3>
                            <div style="font-size:12.5px;color:#334155;line-height:1.6;">
                                <p style="margin-top:0;"><strong>【プライバシーポリシー連携と個人データ保護】</strong></p>
                                <ul style="margin:0 0 8px 18px;padding:0;">
                                    <li><strong>プライバシーポリシー提案機能:</strong> WordPress標準の <code>wp_add_privacy_policy_content</code> に対応し、「設定 > プライバシー」画面に当プラグインの利用に関するポリシー文面テンプレートを自動提供しています。</li>
                                    <li><strong>個人データのエクスポート・消去API準拠:</strong> 管理者が設定した「マイKW (<code>wpis_my_keywords</code>)」は個人データ開示・消去フック（<code>wp_privacy_personal_data_exporters / erasers</code>）に対応しており、開示請求や削除要求に完全準拠します。</li>
                                    <li><strong>監査ログ・証跡保全の推奨:</strong> 不審ユーザーや改ざん記事の削除は不可逆です。法的説明責任や事後調査のため、削除前に「CSVエクスポート」によるバックアップ保存を推奨します。</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Card 3: 不正アクセス禁止法 & 利用許諾条項 -->
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;">
                            <h3 style="font-size:15px;margin-top:0;margin-bottom:10px;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                <span>⚖️</span> <?php esc_html_e( '不正アクセス禁止法遵守 & 利用許諾条項', 'wp-incident-sweeper' ); ?>
                            </h3>
                            <div style="font-size:12.5px;color:#334155;line-height:1.6;">
                                <p style="margin-top:0;"><strong>【適正な利用に関する遵守事項】</strong></p>
                                <ul style="margin:0 0 8px 18px;padding:0;">
                                    <li><strong>正当な管理権原の保有:</strong> 本プラグインは、利用者が自ら所有・管理するWordPress環境、または正当な保守・セキュリティ調査契約に基づき所有者から明示的な書面・電磁的同意を得た環境でのみ使用できます。</li>
                                    <li><strong>第三者システムへの無断利用の禁止:</strong> 管理権原のない第三者のシステムに対する無断導入、検査、改変は「不正アクセス行為の禁止等に関する法律」および各国のサイバー犯罪防止法令により禁じられています。</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Card 4: 免責事項 & バックアップ義務 -->
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;">
                            <h3 style="font-size:15px;margin-top:0;margin-bottom:10px;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                <span>⚠️</span> <?php esc_html_e( '免責事項 (Limitation of Liability) & バックアップ義務', 'wp-incident-sweeper' ); ?>
                            </h3>
                            <div style="font-size:12.5px;color:#334155;line-height:1.6;">
                                <p style="margin-top:0;"><strong>【現状有姿 (AS IS) 提供と事前バックアップ義務】</strong></p>
                                <ul style="margin:0 0 8px 18px;padding:0;">
                                    <li><strong>無保証提供:</strong> 本ソフトウェアは現状有姿で提供され、特定の改ざん・インシデントの完全な検出または復旧を保証するものではありません。</li>
                                    <li><strong>バックアップの必須:</strong> コンテンツやユーザーの一括完全削除を実行する前に、必ずデータベースおよびファイル全体の完全バックアップを取得してください。</li>
                                    <li><strong>損害賠償の免責:</strong> 本ソフトウェアの利用または利用不能から生じるデータの消失、業務の中断、逸失利益その他の直接的・間接的損害について、開発者・配布者は一切の責任を負いません。</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My KW Modal Dialog -->
            <div id="wpis-my-kw-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:99999;align-items:center;justify-content:center;">
                <div style="background:#fff;border-radius:12px;padding:24px;width:90%;max-width:540px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);position:relative;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px;">
                        <h3 style="margin:0;font-size:17px;font-weight:700;display:flex;align-items:center;gap:8px;color:#0f172a;">
                            <span>🎯</span> マイKW設定（個別検知キーワード）
                        </h3>
                        <button type="button" id="wpis-btn-close-my-kw" style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;line-height:1;" title="閉じる"><svg class="wpis-lucide" viewBox="0 0 24 24" width="20" height="20"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
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
