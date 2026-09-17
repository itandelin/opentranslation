<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cache {
    const CACHE_GROUP = 'opentranslation';

    public static function generate_key( $source_text, $target_lang, $context = '' ) {
        return md5( $source_text . '|' . $target_lang . '|' . $context );
    }

    public static function get( $cache_key ) {
        $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return $cached;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cache_key = %s LIMIT 1", $cache_key ), ARRAY_A );
        if ( $result ) {
            wp_cache_set( $cache_key, $result, self::CACHE_GROUP, HOUR_IN_SECONDS );
        }
        return $result ? $result : null;
    }

    public static function set( $cache_key, $source_text, $target_lang, $translated_text, $context = '', $model = '', $status = 'translated' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $data = array(
            'cache_key'       => $cache_key,
            'source_text'     => $source_text,
            'target_lang'     => $target_lang,
            'context'         => $context,
            'translated_text' => $translated_text,
            'model'           => $model,
            'status'          => $status,
        );
        // 用 cache_key（唯一索引）定位，而非依赖缓存里是否带 id。
        // 旧写法从 self::get() 取 id，但 update 分支写回缓存的 $data 不含 id，
        // 在持久化对象缓存下会导致下次 update 走 WHERE id IS NULL：
        // 匹配 0 行返回 0，却被 false !== $result 判成成功——译文没入库却报成功。
        $existing_id = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE cache_key = %s LIMIT 1", $cache_key )
        );

        if ( $existing_id ) {
            $result = $wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
            if ( false === $result ) {
                Log::add( $cache_key, 'cache_update_failed', $wpdb->last_error );
            }
        } else {
            $result = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
            if ( false === $result ) {
                Log::add( $cache_key, 'cache_insert_failed', $wpdb->last_error );
            }
        }

        // 写后失效而非写后缓存：$data 缺 id / retry_count / next_retry_at，
        // 缓存不完整会让重试计数永远读到 0（永不标 failed）、指数退避完全失效。
        wp_cache_delete( $cache_key, self::CACHE_GROUP );

        return false !== $result;
    }

    public static function update_status( $cache_key, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $result = $wpdb->update( $table, array( 'status' => $status ), array( 'cache_key' => $cache_key ), array( '%s' ), array( '%s' ) );
        if ( false !== $result ) {
            wp_cache_delete( $cache_key, self::CACHE_GROUP );
        }
        return false !== $result;
    }

    public static function mark_retry( $cache_key, $retry_count ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $delays = array( 5, 15, 60 );
        $delay = isset( $delays[ $retry_count ] ) ? $delays[ $retry_count ] : 60;
        $next_retry = gmdate( 'Y-m-d H:i:s', time() + ( $delay * MINUTE_IN_SECONDS ) );
        $result = $wpdb->update( $table, array(
            'retry_count'   => $retry_count + 1,
            'next_retry_at' => $next_retry,
            'status'        => 'pending',
        ), array( 'cache_key' => $cache_key ), array( '%d', '%s', '%s' ), array( '%s' ) );
        wp_cache_delete( $cache_key, self::CACHE_GROUP );
        return false !== $result;
    }

    public static function get_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $results = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", ARRAY_A );
        $counts = array( 'pending' => 0, 'translated' => 0, 'failed' => 0 );
        foreach ( $results as $row ) {
            $counts[ $row['status'] ] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * 按语言 + 状态分组的计数。
     *
     * @return array<string,array<string,int>> [lang => ['pending'=>n,'translated'=>n,'failed'=>n]]
     */
    public static function get_counts_by_language() {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';

        $rows = $wpdb->get_results(
            "SELECT target_lang, status, COUNT(*) AS total FROM {$table} GROUP BY target_lang, status",
            ARRAY_A
        );

        $counts = array();
        foreach ( (array) $rows as $row ) {
            $lang = (string) $row['target_lang'];

            if ( ! isset( $counts[ $lang ] ) ) {
                $counts[ $lang ] = array( 'pending' => 0, 'translated' => 0, 'failed' => 0 );
            }

            $counts[ $lang ][ (string) $row['status'] ] = (int) $row['total'];
        }

        return $counts;
    }
}
