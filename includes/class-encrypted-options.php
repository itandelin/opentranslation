<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Encrypted_Options {
    private static function get_key() {
        if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
            return AUTH_KEY;
        }
        return 'opentranslation-fallback-key-' . DB_PASSWORD;
    }

    public static function get( $option, $default = false ) {
        $raw = get_option( $option, $default );
        if ( false === $raw || '' === $raw ) {
            return $default;
        }
        if ( ! is_string( $raw ) ) {
            return is_array( $raw ) ? $raw : $default;
        }
        $decrypted = self::decrypt( $raw );
        return false !== $decrypted ? $decrypted : $default;
    }

    public static function set( $option, $value ) {
        $encrypted = self::encrypt( $value );
        return update_option( $option, $encrypted );
    }

    public static function delete( $option ) {
        return delete_option( $option );
    }

    private static function encrypt( $value ) {
        $key = hash( 'sha256', self::get_key(), true );
        $iv  = openssl_random_pseudo_bytes( 16 );
        $encrypted = openssl_encrypt( wp_json_encode( $value ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $encrypted );
    }

    private static function decrypt( $value ) {
        $data = base64_decode( $value );
        if ( false === $data || strlen( $data ) < 17 ) {
            return false;
        }
        $iv  = substr( $data, 0, 16 );
        $encrypted = substr( $data, 16 );
        $key = hash( 'sha256', self::get_key(), true );
        $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return false !== $decrypted ? json_decode( $decrypted, true ) : false;
    }
}
