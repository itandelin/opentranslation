<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Storage_Adapter {
    const COUNT_CACHE_GROUP = 'opentranslation_counts';

    private static $trp_settings = null;
    private static $trp_query = null;

    public static function is_tp_active() {
        return class_exists( 'TRP_Translate_Press' );
    }

    public static function get_settings() {
        if ( null === self::$trp_settings ) {
            if ( function_exists( 'trp_get_settings_options' ) ) {
                self::$trp_settings = trp_get_settings_options();
            } elseif ( class_exists( 'TRP_Settings' ) ) {
                $trp = \TRP_Translate_Press::get_trp_instance();
                $settings = new \TRP_Settings();
                self::$trp_settings = $settings->get_settings();
            } else {
                self::$trp_settings = get_option( 'trp_settings', array() );
            }
        }
        return self::$trp_settings;
    }

    public static function get_target_languages() {
        $settings = self::get_settings();
        if ( empty( $settings['publish-languages'] ) ) {
            return array();
        }
        $default_lang = isset( $settings['default-language'] ) ? $settings['default-language'] : 'en_US';
        $languages = $settings['publish-languages'];
        return array_values( array_filter( $languages, function ( $lang ) use ( $default_lang ) {
            return $lang !== $default_lang;
        } ) );
    }

    /**
     * 语言码白名单化：只保留字母数字与下划线。
     *
     * 语言码来自 TP 设置，正常可信，但会直接拼进表名进入 SQL，
     * 必须防止 TP 设置被污染时形成注入落点。
     */
    private static function normalize_language_slug( $language ) {
        $slug = strtolower( str_replace( '-', '_', (string) $language ) );
        return preg_replace( '/[^a-z0-9_]/', '', $slug );
    }

    public static function get_dictionary_table( $language ) {
        global $wpdb;
        $settings     = self::get_settings();
        $default_lang = isset( $settings['default-language'] ) ? $settings['default-language'] : 'en_US';

        $default_slug = self::normalize_language_slug( $default_lang );
        $target_slug  = self::normalize_language_slug( $language );

        $candidates = array(
            $wpdb->prefix . 'trp_dictionary_' . $default_slug . '_' . $target_slug,
            $wpdb->prefix . 'trp_dictionary_' . $target_slug,
        );

        foreach ( $candidates as $candidate ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $candidate ) );
            if ( $exists ) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    public static function get_ready_untranslated( $language, $limit = 10, $offset = 0 ) {
        global $wpdb;
        $table      = self::get_dictionary_table( $language );
        $cache_table = $wpdb->prefix . 'opentranslation_cache';
        $now        = gmdate( 'Y-m-d H:i:s' );
        $scope      = Scope::sql_where( $language );
        $sql = $wpdb->prepare(
            "SELECT d.id, d.original, d.translated, d.status, c.translated_text AS cached_translation
            FROM `{$table}` d
            LEFT JOIN `{$cache_table}` c
                ON c.cache_key = MD5( CONCAT( d.original, '|', %s, '|' ) )
            WHERE ( d.translated = '' OR d.translated IS NULL )
                AND d.status != 2
                {$scope}
                AND (
                    c.id IS NULL
                    OR ( c.translated_text IS NOT NULL AND c.translated_text != '' )
                    OR ( c.status != 'failed' AND ( c.next_retry_at IS NULL OR c.next_retry_at <= %s ) )
                )
            ORDER BY d.id ASC
            LIMIT %d OFFSET %d",
            $language,
            $now,
            $limit,
            $offset
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function has_ready_untranslated( $language ) {
        return ! empty( self::get_ready_untranslated( $language, 1, 0 ) );
    }

    /**
     * 未译条目数（带短期缓存）。
     *
     * 字典表当前约 1.4 万行且 translated 列无索引，
     * 每次打开管理页都全表扫描代价过高。
     *
     * @param string $language  目标语言
     * @param bool   $use_cache 是否读缓存
     * @return int
     */
    public static function get_untranslated_count( $language, $use_cache = true ) {
        $cache_key = 'untranslated_' . $language . '_' . md5( wp_json_encode( Scope::get( $language ) ) );

        if ( $use_cache ) {
            $cached = wp_cache_get( $cache_key, self::COUNT_CACHE_GROUP );
            if ( false !== $cached ) {
                return (int) $cached;
            }
        }

        global $wpdb;
        $table = self::get_dictionary_table( $language );
        $scope = Scope::sql_where( $language );
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` d WHERE ( d.translated = '' OR d.translated IS NULL ) AND d.status != %d {$scope}",
                2
            )
        );

        $ttl = (int) apply_filters( 'opentranslation_count_cache_ttl', 5 * MINUTE_IN_SECONDS );
        wp_cache_set( $cache_key, $count, self::COUNT_CACHE_GROUP, max( 30, $ttl ) );

        return $count;
    }

    /**
     * 写回后使计数缓存失效（按语言删除当前范围 key）。
     */
    public static function flush_count_cache( $language ) {
        wp_cache_delete( 'untranslated_' . $language . '_' . md5( wp_json_encode( Scope::get( $language ) ) ), self::COUNT_CACHE_GROUP );
    }

    public static function update_translation( $language, $id, $translated_text ) {
        global $wpdb;
        $table = self::get_dictionary_table( $language );
        $result = $wpdb->update(
            $table,
            array( 'translated' => $translated_text, 'status' => 1 ),
            array( 'id' => $id ),
            array( '%s', '%d' ),
            array( '%d' )
        );
        return false !== $result;
    }

    public static function bulk_update_translations( $language, $translations ) {
        global $wpdb;
        if ( empty( $translations ) ) {
            return true;
        }
        $table = self::get_dictionary_table( $language );
        $ids = array();
        $cases = array();
        $values = array();
        foreach ( $translations as $item ) {
            $id = absint( $item['id'] );
            $ids[] = $id;
            $cases[] = "WHEN id = {$id} THEN %s";
            $values[] = $item['translated'];
        }
        $ids_sql = implode( ',', $ids );
        $sql = "UPDATE `{$table}` SET translated = CASE " . implode( " ", $cases ) . " ELSE translated END, status = 1 WHERE id IN ({$ids_sql})";
        $prepared = $wpdb->prepare( $sql, ...$values );
        $result = $wpdb->query( $prepared );
        if ( false === $result ) {
            Log::add( '', 'tp_bulk_update_failed', $wpdb->last_error );
        }
        self::flush_count_cache( $language );
        self::clear_tp_cache();
        return false !== $result;
    }

    /**
     * 清理 TranslatePress 缓存，使新译文即时在前台生效。
     *
     * 加节流：队列可能每分钟写回一次，
     * 频繁清缓存会抵消 TP 缓存本身的收益。
     */
    public static function clear_tp_cache() {
        $throttle = (int) apply_filters( 'opentranslation_tp_cache_clear_throttle', 5 * MINUTE_IN_SECONDS );
        $throttle = max( 0, $throttle );

        if ( $throttle > 0 ) {
            $last = (int) get_option( 'opentranslation_tp_cache_cleared_at', 0 );
            if ( ( time() - $last ) < $throttle ) {
                return;
            }
        }

        if ( class_exists( 'TRP_Translate_Press' ) ) {
            $trp = \TRP_Translate_Press::get_trp_instance();
            if ( $trp && method_exists( $trp, 'clear_cache' ) ) {
                $trp->clear_cache();
            }
        }

        if ( function_exists( 'trp_clear_cache' ) ) {
            trp_clear_cache();
        }

        update_option( 'opentranslation_tp_cache_cleared_at', time(), false );
    }
}
