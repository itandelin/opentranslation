<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 卸载清理。
 *
 * 相对旧版补齐四项遗漏：
 * 1. queue_lock_until / queue_cursor / decrypt_failed / log_cleanup_at 等 option
 * 2. Action Scheduler 排程（旧版只删表和 option，AS 任务会成为孤儿）
 * 3. WP-Cron 事件
 * 4. 多站点逐站清理
 *
 * option 清理用「字面清单 + 前缀扫描」两道：
 * 清单显式可审计，前缀扫描兜住后续新增而忘记登记的项。
 */
class Uninstaller {

    const OPTION_PREFIX = 'opentranslation_';

    /**
     * 已知 option 清单。
     *
     * 用字面量而非引用 Encrypted_Options::DECRYPT_FAILED_FLAG 等常量：
     * 卸载时这些类可能尚未加载。
     */
    private static function options() {
        return array(
            'opentranslation_db_version',
            'opentranslation_settings',
            'opentranslation_models',
            'opentranslation_queue_lock_until',
            'opentranslation_queue_cursor',
            'opentranslation_decrypt_failed',
            'opentranslation_log_cleanup_at',
            'opentranslation_last_run_stats',
            'opentranslation_tp_cache_cleared_at',
        );
    }

    public static function uninstall() {
        if ( is_multisite() ) {
            $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                self::cleanup_site();
                restore_current_blog();
            }
            return;
        }

        self::cleanup_site();
    }

    private static function cleanup_site() {
        self::unschedule();
        self::drop_tables();
        self::delete_options();
    }

    private static function unschedule() {
        if ( class_exists( '\OpenTranslation\Scheduler' ) ) {
            Scheduler::unschedule_all();
            return;
        }

        // Scheduler 未加载时的等效清理
        $timestamp = wp_next_scheduled( 'opentranslation_process_queue' );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'opentranslation_process_queue' );
            $timestamp = wp_next_scheduled( 'opentranslation_process_queue' );
        }

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'opentranslation_as_process' );
            as_unschedule_all_actions( 'opentranslation_as_continue' );
        }
    }

    private static function drop_tables() {
        global $wpdb;

        foreach ( array( 'cache', 'log', 'rate_limit' ) as $suffix ) {
            $table = $wpdb->prefix . 'opentranslation_' . $suffix;
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }
    }

    private static function delete_options() {
        global $wpdb;

        foreach ( self::options() as $option ) {
            delete_option( $option );
        }

        // 前缀扫描兜底：清单漏登记的 option 也一并清除
        $leftovers = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%'
            )
        );

        foreach ( (array) $leftovers as $option_name ) {
            delete_option( $option_name );
        }
    }
}
