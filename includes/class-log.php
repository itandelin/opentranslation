<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Log {
    public static function add( $cache_key, $action, $message = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_log';
        return false !== $wpdb->insert( $table, array(
            'cache_key' => $cache_key,
            'action'    => $action,
            'message'   => $message,
        ), array( '%s', '%s', '%s' ) );
    }

    public static function get_recent( $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_log';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ), ARRAY_A );
    }
}
