<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Storage_Adapter {
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

    public static function get_dictionary_table( $language ) {
        global $wpdb;
        $settings     = self::get_settings();
        $default_lang = isset( $settings['default-language'] ) ? $settings['default-language'] : 'en_US';

        $candidates = array(
            $wpdb->prefix . 'trp_dictionary_' . strtolower( str_replace( '-', '_', $default_lang ) ) . '_' . strtolower( str_replace( '-', '_', $language ) ),
            $wpdb->prefix . 'trp_dictionary_' . strtolower( str_replace( '-', '_', $language ) ),
        );

        foreach ( $candidates as $candidate ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $candidate ) );
            if ( $exists ) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    public static function get_untranslated( $language, $limit = 10, $offset = 0 ) {
        global $wpdb;
        $table = self::get_dictionary_table( $language );
        $sql = $wpdb->prepare(
            "SELECT id, original, translated, status FROM `{$table}` WHERE ( translated = '' OR translated IS NULL ) AND status != 2 ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function get_ready_untranslated( $language, $limit = 10, $offset = 0 ) {
        global $wpdb;
        $table = self::get_dictionary_table( $language );
        $cache_table = $wpdb->prefix . 'opentranslation_cache';
        $now = gmdate( 'Y-m-d H:i:s' );
        $sql = $wpdb->prepare(
            "SELECT d.id, d.original, d.translated, d.status, c.translated_text AS cached_translation
            FROM `{$table}` d
            LEFT JOIN `{$cache_table}` c
                ON c.cache_key = MD5( CONCAT( d.original, '|', %s, '|' ) )
            WHERE ( d.translated = '' OR d.translated IS NULL )
                AND d.status != 2
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

    public static function get_untranslated_count( $language ) {
        global $wpdb;
        $table = self::get_dictionary_table( $language );
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE ( translated = '' OR translated IS NULL ) AND status != 2";
        return (int) $wpdb->get_var( $sql );
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
        return false !== $result;
    }

    public static function clear_tp_cache() {
        if ( class_exists( 'TRP_Translate_Press' ) ) {
            $trp = \TRP_Translate_Press::get_trp_instance();
            if ( $trp && method_exists( $trp, 'clear_cache' ) ) {
                $trp->clear_cache();
            }
        }
        if ( function_exists( 'trp_clear_cache' ) ) {
            trp_clear_cache();
        }
    }
}
