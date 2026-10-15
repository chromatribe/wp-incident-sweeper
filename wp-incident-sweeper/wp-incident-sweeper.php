<?php
/**
 * Plugin Name: WP Incident Sweeper (インシデント一括巡回・駆除スイーパー)
 * Plugin URI: https://chromatri.be
 * Description: サイト内の全投稿・固定ページ・カスタム投稿タイプ（下書き・ゴミ箱・非公開含む）およびユーザーを瞬時に一括スキャン。改ざん痕跡（Hacked by...、wp2_*、不審な登録日のアカウント等）を自動検出し、選択したアイテムを一括完全削除できる緊急セキュリティ・巡回ツール。
 * Version: 1.0.0
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

    // 検知キーワードリスト
    private $threat_keywords_high = array(
        'hacked by',
        'trenggalek',
        'trenggalek6etar',
        'wp2shell',
        'defaced by',
        'indoxploit',
        'indonesian hacker',
        'c99shell',
        'r57shell',
        'b374k',
    );

    private $threat_keywords_medium = array(
        'casino online',
        'slot gacor',
        'slot online',
        'judi online',
        'poker online',
        'viagra online',
        'cialis online',
        'eval(base64_decode',
        '<script>eval',
        'document.write(unescape',
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

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_wp-incident-sweeper' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wpis-admin-css',
            plugins_url( 'assets/css/admin.css', __FILE__ ),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'wpis-admin-js',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            '1.0.0',
            true
        );

        wp_localize_script( 'wpis-admin-js', 'wpisConfig', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'adminUrl' => admin_url(),
            'nonce'    => wp_create_nonce( 'wpis_nonce_action' ),
        ) );
    }

    /**
     * Ajax: サイト全体のスキャン実行
     */
    public function ajax_run_scan() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        // 1. ユーザーのスキャン
        $users_data = array();
        $threat_user_ids = array();
        $all_users = get_users();

        foreach ( $all_users as $u ) {
            $is_threat = false;
            $reasons = array();

            // wp2_* プレフィックス
            if ( strpos( $u->user_login, 'wp2_' ) === 0 ) {
                $is_threat = true;
                $reasons[] = 'ユーザー名が wp2_* で始まっています';
            }

            // @wp2shell.invalid または .invalid ドメイン
            if ( stripos( $u->user_email, '@wp2shell.invalid' ) !== false ) {
                $is_threat = true;
                $reasons[] = 'メールアドレスが @wp2shell.invalid です';
            } elseif ( stripos( $u->user_email, '.invalid' ) !== false ) {
                $is_threat = true;
                $reasons[] = '無効ドメイン（.invalid）のアドレスです';
            }

            // 7月17日〜7月25日の登録
            $reg_time = strtotime( $u->user_registered );
            $start_july = strtotime( '2026-07-17 00:00:00' );
            $end_july   = strtotime( '2026-07-25 23:59:59' );

            if ( $reg_time >= $start_july && $reg_time <= $end_july ) {
                $is_threat = true;
                $reasons[] = '7月インシデント期間（7/17〜7/25）の登録ユーザー';
            }

            if ( $is_threat ) {
                $threat_user_ids[] = $u->ID;
            }

            $users_data[] = array(
                'id'             => $u->ID,
                'login'          => $u->user_login,
                'name'           => $u->display_name,
                'email'          => $u->user_email,
                'roles'          => $u->roles,
                'registered'     => get_date_from_gmt( $u->user_registered, 'Y-m-d H:i:s' ),
                'is_threat'      => $is_threat,
                'threat_reasons' => $reasons,
            );
        }

        // 2. 投稿・固定ページ・全カスタム投稿タイプのスキャン
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        // 一般的な内部・管理用タイプも必要に応じて追加
        if ( ! in_array( 'post', $post_types, true ) ) $post_types[] = 'post';
        if ( ! in_array( 'page', $post_types, true ) ) $post_types[] = 'page';

        $args = array(
            'post_type'      => array_values( $post_types ),
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );

        $query = new WP_Query( $args );
        $posts_data = array();
        $threat_post_count = 0;

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $title   = get_the_title();
                $content = get_the_content();
                $slug    = get_post_field( 'post_name', $post_id );
                $author_id = get_the_author_meta( 'ID' );
                $author_name = get_the_author_meta( 'display_name' );
                $status  = get_post_status( $post_id );
                $ptype   = get_post_type();
                $mod_date = get_the_modified_date( 'Y-m-d H:i:s' );

                $severity = 'clean';
                $reasons  = array();

                // 不審ユーザーが作成した記事か？
                if ( in_array( $author_id, $threat_user_ids, true ) ) {
                    $severity = 'high';
                    $reasons[] = '不審なユーザーによって作成された記事';
                }

                // タイトル・スラッグ・本文のキーワード検査
                $check_text = mb_strtolower( $title . ' ' . $slug . ' ' . $content, 'UTF-8' );

                foreach ( $this->threat_keywords_high as $kw ) {
                    if ( mb_strpos( $check_text, $kw ) !== false ) {
                        $severity = 'high';
                        $reasons[] = '高危険度キーワード検知: "' . $kw . '"';
                    }
                }

                if ( $severity !== 'high' ) {
                    foreach ( $this->threat_keywords_medium as $kw ) {
                        if ( mb_strpos( $check_text, $kw ) !== false ) {
                            $severity = 'medium';
                            $reasons[] = 'スパム/不審コード検知: "' . $kw . '"';
                        }
                    }
                }

                // 下書き・ゴミ箱のフラグ
                if ( $severity === 'clean' && in_array( $status, array( 'draft', 'trash', 'pending', 'private' ), true ) ) {
                    $severity = 'low';
                    $reasons[] = '非公開/下書き/ゴミ箱ステータス';
                }

                if ( $severity === 'high' || $severity === 'medium' ) {
                    $threat_post_count++;
                }

                $posts_data[] = array(
                    'id'               => $post_id,
                    'title'            => $title,
                    'slug'             => $slug,
                    'post_type'        => $ptype,
                    'status'           => $status,
                    'author_id'        => $author_id,
                    'author_name'      => $author_name,
                    'author_is_threat' => in_array( $author_id, $threat_user_ids, true ),
                    'modified_date'    => $mod_date,
                    'severity'         => $severity,
                    'reasons'          => $reasons,
                );
            }
            wp_reset_postdata();
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
     * Ajax: 選択した投稿の一括削除/ゴミ箱移動
     */
    public function ajax_delete_posts() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $raw_ids = isset( $_POST['post_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['post_ids'] ) ) : '[]';
        $post_ids = json_decode( $raw_ids, true );
        $force_delete = ( isset( $_POST['force_delete'] ) && '1' === $_POST['force_delete'] );

        if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
            wp_send_json_error( array( 'message' => '削除対象が選択されていません。' ) );
        }

        $deleted_count = 0;
        foreach ( $post_ids as $pid ) {
            $pid = intval( $pid );
            if ( $pid <= 0 ) continue;

            if ( $force_delete ) {
                $res = wp_delete_post( $pid, true ); // 完全削除
            } else {
                $res = wp_trash_post( $pid ); // ゴミ箱へ
            }

            if ( $res ) {
                $deleted_count++;
            }
        }

        wp_send_json_success( array( 'deleted_count' => $deleted_count ) );
    }

    /**
     * Ajax: 選択したユーザーの一括削除（関連コンテンツも同時削除）
     */
    public function ajax_delete_users() {
        check_ajax_referer( 'wpis_nonce_action', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $raw_ids = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '[]';
        $user_ids = json_decode( $raw_ids, true );

        if ( ! is_array( $user_ids ) || empty( $user_ids ) ) {
            wp_send_json_error( array( 'message' => '削除対象ユーザーが選択されていません。' ) );
        }

        $current_user_id = get_current_user_id();
        $deleted_count = 0;

        foreach ( $user_ids as $uid ) {
            $uid = intval( $uid );
            if ( $uid <= 0 || $uid === $current_user_id ) {
                continue; // 自身のアカウントは絶対に削除しない安全ガード
            }

            // 第2引数を null にすることで、そのユーザーが作成した全コンテンツを完全削除！
            if ( wp_delete_user( $uid, null ) ) {
                $deleted_count++;
            }
        }

        wp_send_json_success( array( 'deleted_count' => $deleted_count ) );
    }

    /**
     * 管理画面のUIレンダリング
     */
    public function render_admin_page() {
        ?>
        <div class="wrap wpis-app">
            <!-- Header -->
            <div class="wpis-header">
                <div class="wpis-title-area">
                    <h1>
                        <span class="dashicons dashicons-shield" style="font-size:24px;width:24px;height:24px;color:#0284c7;"></span>
                        インシデント巡回スイーパー (WP Incident Sweeper)
                    </h1>
                    <p>サイト内の全コンテンツ（公開・下書き・ゴミ箱）およびユーザーを一括クロールし、不正な痕跡を瞬時に特定・駆除します。</p>
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
                    巡回チェックガイド
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
                            選択した項目をゴミ箱へ
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
                        <span style="font-size:13px;color:#64748b;">※不審ユーザーを削除すると、そのユーザーが作成した下書き・投稿等のコンテンツも自動で一括削除されます。</span>
                    </div>
                    <div class="wpis-bulk-actions">
                        <button type="button" class="wpis-btn wpis-btn-danger wpis-btn-sm" id="wpis-btn-delete-users" disabled>
                            選択したユーザーを完全削除
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
                    <h2 style="font-size:16px;margin-top:0;">🛡️ 巡回時の確認ポイントと運用手順</h2>
                    <ol style="padding-left:20px;font-size:14px;color:#334155;">
                        <li><strong>プラグインの有効化:</strong> 管理画面の「プラグイン > 新規追加 > プラグインのアップロード」から <code>wp-incident-sweeper.zip</code> をアップロードして有効化します。</li>
                        <li><strong>画面を開くと自動スキャン:</strong> 管理画面左メニューの「巡回スイーパー」を開くと、サイト内の全コンテンツとユーザーが自動でクロールされます。</li>
                        <li><strong>「🚨 危険項目を一括選択」を活用:</strong> 改ざん記事（<code>Hacked by...</code>）や不審ユーザー作成記事があれば、ボタン1つで一括チェックが入ります。</li>
                        <li><strong>一括完全削除:</strong> 「選択した項目を完全削除」を押せば、ゴミ箱を経由せず一発で消去されます。</li>
                        <li><strong>ユーザーの確認・削除:</strong> 「ユーザー監査」タブにて、<code>wp2_*</code> や 7月インシデント期間登録のユーザーが残っていないか確認し、必要に応じて削除します。</li>
                        <li><strong>CSV出力（エビデンス保全）:</strong> 右上の「結果をCSV出力」をクリックすると、巡回結果のレポートがダウンロードできます。インフラチームや上長への報告用証拠として保管できます。</li>
                        <li><strong>完了後のプラグイン削除:</strong> 巡回と駆除が完了したら、管理画面の「プラグイン一覧」から停止・削除して問題ありません（DBに余計なデータを一切残さない安全設計です）。</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
}

// 起動
add_action( 'plugins_loaded', array( 'WP_Incident_Sweeper', 'get_instance' ) );
