<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 后台 admin-post 动作处理（触发队列、重试、暂停语言）。
 *
 * 从 Admin 拆出以满足单文件 300 行上限。
 */
class Admin_Actions {

    public function __construct() {
        add_action( 'admin_post_opentranslation_run_queue', array( $this, 'handle_run_queue' ) );
        add_action( 'admin_post_opentranslation_retry_failed', array( $this, 'handle_retry_failed' ) );
        add_action( 'admin_post_opentranslation_retry_item', array( $this, 'handle_retry_item' ) );
        add_action( 'admin_post_opentranslation_toggle_language', array( $this, 'handle_toggle_language' ) );
    }

    public function handle_run_queue() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_run_queue' );
        Scheduler::trigger_manual();
        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-queue&message=queued' ) );
        exit;
    }

    public function handle_retry_failed() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_retry_failed' );
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $wpdb->query( "UPDATE {$table} SET status = 'pending', retry_count = 0, next_retry_at = NULL WHERE status = 'failed'" );
        Scheduler::trigger_manual();
        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-queue&message=retried' ) );
        exit;
    }

    public function handle_retry_item() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_retry_item' );

        $cache_key = isset( $_GET['cache_key'] ) ? sanitize_text_field( wp_unslash( $_GET['cache_key'] ) ) : '';

        // cache_key 是 md5，严格校验格式后才进 SQL
        $message = ( 1 === preg_match( '/^[a-f0-9]{32}$/', $cache_key ) && Cache::reset_item( $cache_key ) )
            ? 'retried'
            : 'invalid';

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'opentranslation-failures', 'message' => $message ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function handle_toggle_language() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_toggle_language' );
        $lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
        $settings = get_option( 'opentranslation_settings', array() );
        $disabled = isset( $settings['disabled_languages'] ) ? $settings['disabled_languages'] : array();
        if ( in_array( $lang, $disabled, true ) ) {
            $disabled = array_diff( $disabled, array( $lang ) );
        } else {
            $disabled[] = $lang;
        }
        $settings['disabled_languages'] = array_values( $disabled );
        update_option( 'opentranslation_settings', $settings );
        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-queue&message=toggled' ) );
        exit;
    }
}
