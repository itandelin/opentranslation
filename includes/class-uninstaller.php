<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Uninstaller {
    public static function uninstall() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $wpdb->query( "DROP TABLE IF EXISTS {$prefix}opentranslation_cache" );
        $wpdb->query( "DROP TABLE IF EXISTS {$prefix}opentranslation_log" );
        $wpdb->query( "DROP TABLE IF EXISTS {$prefix}opentranslation_rate_limit" );
        delete_option( 'opentranslation_db_version' );
        delete_option( 'opentranslation_settings' );
        delete_option( 'opentranslation_models' );
    }
}
