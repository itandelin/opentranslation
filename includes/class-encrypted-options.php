<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 模型配置（含 API Key）的加密存储。
 *
 * 相对旧版的三个改动：
 * 1. 改用 AES-256-GCM。CBC 无认证标签，密文被篡改时解密仍可能"成功"并返回垃圾数据
 * 2. 解密失败与「无数据」区分开：前者通常是 AUTH_KEY 变更，后台需要明确告警，
 *    而不是让用户看到「No models configured」却无从排查
 * 3. 检查 openssl 可用性与随机源强度，避免写入损坏数据
 *
 * 旧 CBC 数据仍可读取（密钥派生方式相同），下次 set() 自动升级为 GCM。
 */
class Encrypted_Options {

    const CIPHER_GCM = 'aes-256-gcm';
    const CIPHER_CBC = 'AES-256-CBC';
    const PREFIX_GCM = 'otg1:';
    const TAG_LENGTH = 16;
    const DECRYPT_FAILED_FLAG = 'opentranslation_decrypt_failed';

    private static function get_key() {
        if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
            return AUTH_KEY;
        }
        // SECURE_AUTH_KEY 优先于 DB_PASSWORD：后者强度不足且更易被其他组件读取
        if ( defined( 'SECURE_AUTH_KEY' ) && '' !== SECURE_AUTH_KEY ) {
            return SECURE_AUTH_KEY;
        }
        return 'opentranslation-fallback-key-' . ( defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '' );
    }

    /**
     * 派生方式必须与旧版保持一致，否则现有 CBC 数据无法读取。
     */
    private static function derive_key() {
        return hash( 'sha256', self::get_key(), true );
    }

    public static function is_available() {
        return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
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

        if ( false === $decrypted ) {
            // 与「无数据」区分：这是解密失败，通常意味着 AUTH_KEY 变更
            update_option( self::DECRYPT_FAILED_FLAG, $option, false );
            return $default;
        }

        if ( '' !== (string) get_option( self::DECRYPT_FAILED_FLAG, '' ) ) {
            delete_option( self::DECRYPT_FAILED_FLAG );
        }

        return $decrypted;
    }

    public static function set( $option, $value ) {
        $encrypted = self::encrypt( $value );
        if ( false === $encrypted ) {
            return false;
        }
        delete_option( self::DECRYPT_FAILED_FLAG );
        return update_option( $option, $encrypted );
    }

    public static function delete( $option ) {
        return delete_option( $option );
    }

    /**
     * 是否存在解密失败记录，供后台提示。
     *
     * @return string 失败的 option 名；无失败时为空串
     */
    public static function get_decrypt_failure() {
        return (string) get_option( self::DECRYPT_FAILED_FLAG, '' );
    }

    private static function encrypt( $value ) {
        if ( ! self::is_available() ) {
            return false;
        }

        $plaintext = wp_json_encode( $value );
        if ( false === $plaintext ) {
            return false;
        }

        $iv_length = openssl_cipher_iv_length( self::CIPHER_GCM );
        $strong    = false;
        $iv        = openssl_random_pseudo_bytes( $iv_length, $strong );

        if ( false === $iv || ! $strong ) {
            return false;
        }

        $tag        = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_GCM,
            self::derive_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ( false === $ciphertext ) {
            return false;
        }

        // GCM 自带认证标签，无需额外 HMAC
        return self::PREFIX_GCM . base64_encode( $iv . $tag . $ciphertext );
    }

    private static function decrypt( $value ) {
        if ( ! self::is_available() ) {
            return false;
        }

        if ( 0 === strpos( $value, self::PREFIX_GCM ) ) {
            return self::decrypt_gcm( substr( $value, strlen( self::PREFIX_GCM ) ) );
        }

        // 兼容旧 CBC 数据：读取成功后由下次 set() 自动升级为 GCM
        return self::decrypt_cbc( $value );
    }

    private static function decrypt_gcm( $encoded ) {
        $data = base64_decode( $encoded, true );
        if ( false === $data ) {
            return false;
        }

        $iv_length = openssl_cipher_iv_length( self::CIPHER_GCM );
        if ( strlen( $data ) <= $iv_length + self::TAG_LENGTH ) {
            return false;
        }

        $iv         = substr( $data, 0, $iv_length );
        $tag        = substr( $data, $iv_length, self::TAG_LENGTH );
        $ciphertext = substr( $data, $iv_length + self::TAG_LENGTH );

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_GCM,
            self::derive_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return self::decode_json( $plaintext );
    }

    private static function decrypt_cbc( $encoded ) {
        $data = base64_decode( $encoded, true );
        if ( false === $data || strlen( $data ) < 17 ) {
            return false;
        }

        $iv         = substr( $data, 0, 16 );
        $ciphertext = substr( $data, 16 );

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_CBC,
            self::derive_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        return self::decode_json( $plaintext );
    }

    private static function decode_json( $plaintext ) {
        if ( false === $plaintext ) {
            return false;
        }

        $decoded = json_decode( $plaintext, true );
        return JSON_ERROR_NONE === json_last_error() ? $decoded : false;
    }
}
