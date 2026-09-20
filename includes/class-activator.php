<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {
    public static function activate() {
        self::create_tables();
        update_option( 'opentranslation_db_version', OPENTRANSLATION_DB_VERSION );
    }

    /**
     * 版本升级通道：已激活的站点在插件更新后补建新表。
     *
     * 激活钩子只在首次启用时触发，覆盖文件升级不会再跑。
     * dbDelta 幂等，重复调用安全。
     */
    public static function maybe_upgrade() {
        if ( get_option( 'opentranslation_db_version' ) === OPENTRANSLATION_DB_VERSION ) {
            return;
        }
        self::create_tables();
        self::drop_legacy_tables();
        update_option( 'opentranslation_db_version', OPENTRANSLATION_DB_VERSION );
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        foreach ( array( 'log_sql', 'usage_sql' ) as $method ) {
            dbDelta( self::$method( $prefix, $charset ) );
        }
    }

    private static function log_sql( $prefix, $charset ) {
        return "CREATE TABLE IF NOT EXISTS {$prefix}opentranslation_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key varchar(32) NOT NULL,
            action varchar(50) NOT NULL,
            message longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY cache_key (cache_key),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};";
    }

    /**
     * 用量按日 + 模型聚合（P2-3）。逐次记录一年会产生数十万行，
     * 日聚合后规模是模型数 × 天数。
     */
    private static function usage_sql( $prefix, $charset ) {
        return "CREATE TABLE IF NOT EXISTS {$prefix}opentranslation_usage (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            usage_date date NOT NULL,
            model_key varchar(32) NOT NULL,
            model_label varchar(191) NOT NULL,
            requests int(10) unsigned NOT NULL DEFAULT 0,
            prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            total_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY date_model (usage_date, model_key)
        ) {$charset};";
    }

    /**
     * 删除自建队列时代遗留的表。
     *
     * 译文已全部由 TranslatePress 写入 trp_dictionary_* 字典表，
     * 这两张表只是当时的缓存与限流副本，删除不丢翻译结果。
     */
    private static function drop_legacy_tables() {
        global $wpdb;
        foreach ( array( 'opentranslation_cache', 'opentranslation_rate_limit' ) as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB
        }
    }
}
