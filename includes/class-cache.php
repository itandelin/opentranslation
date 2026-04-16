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
        $existing = self::get( $cache_key );
        if ( $existing ) {
            $result = $wpdb->update( $table, $data, array( 'id' => $existing['id'] ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
            if ( false === $result ) {
                Log::add( $cache_key, 'cache_update_failed', $wpdb->last_error );
            }
        } else {
            $result = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
            if ( false === $result ) {
                Log::add( $cache_key, 'cache_insert_failed', $wpdb->last_error );
            } else {
                $data['id'] = $wpdb->insert_id;
            }
        }
        if ( false !== $result ) {
            wp_cache_set( $cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS );
        }
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
}
