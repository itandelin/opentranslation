<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base URL 安全校验。
 *
 * 模型请求会自动携带 Authorization / x-api-key，
 * 一旦指向内网地址即同时造成内网探测与凭据外泄，
 * 因此保存与请求两个环节都要校验。
 */
class URL_Guard {

    private static function allowed_schemes() {
        return (array) apply_filters( 'opentranslation_allowed_url_schemes', array( 'https' ) );
    }

    /**
     * @param string $url 待校验 URL
     * @return bool
     */
    public static function is_allowed( $url ) {
        return '' === self::get_rejection_reason( $url );
    }

    /**
     * 返回拒绝原因；通过校验时返回空串。
     *
     * @param string $url 待校验 URL
     * @return string
     */
    public static function get_rejection_reason( $url ) {
        $url = trim( (string) $url );

        if ( '' === $url ) {
            return __( 'Base URL is empty.', 'opentranslation' );
        }

        $parts = wp_parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return __( 'Base URL must be an absolute URL with scheme and host.', 'opentranslation' );
        }

        $scheme = strtolower( $parts['scheme'] );
        if ( ! in_array( $scheme, self::allowed_schemes(), true ) ) {
            return sprintf(
                /* translators: %s is the rejected URL scheme. */
                __( 'Scheme "%s" is not allowed. Use https.', 'opentranslation' ),
                $scheme
            );
        }

        $host = strtolower( trim( $parts['host'], '[]' ) );

        if ( self::is_allowlisted_host( $host ) ) {
            return '';
        }

        if ( self::is_blocked_hostname( $host ) ) {
            return sprintf(
                /* translators: %s is the rejected host name. */
                __( 'Host "%s" points to a local or reserved address.', 'opentranslation' ),
                $host
            );
        }

        if ( self::is_blocked_ip( $host ) ) {
            return sprintf(
                /* translators: %s is the rejected host name. */
                __( 'Host "%s" is a private or reserved IP address.', 'opentranslation' ),
                $host
            );
        }

        return '';
    }

    /**
     * 显式放行名单，供特殊网络环境下的自建网关豁免。
     */
    private static function is_allowlisted_host( $host ) {
        $allowed = (array) apply_filters( 'opentranslation_allowed_base_url_hosts', array() );
        foreach ( $allowed as $candidate ) {
            if ( strtolower( trim( (string) $candidate ) ) === $host ) {
                return true;
            }
        }
        return false;
    }

    private static function is_blocked_hostname( $host ) {
        $blocked = array( 'localhost', 'localhost.localdomain', 'metadata', 'metadata.google.internal' );
        if ( in_array( $host, $blocked, true ) ) {
            return true;
        }
        return (bool) preg_match( '/\.(local|internal|localhost|home\.arpa)$/', $host );
    }

    /**
     * IP 字面量校验。非 IP 的域名不在此拦截——
     * DNS 可能指向内网，由 harden_request_args() 的禁重定向 +
     * reject_unsafe_urls 在传输层兜底。
     */
    private static function is_blocked_ip( $host ) {
        if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $public_only = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return false === $public_only;
    }

    /**
     * 模型请求统一的 wp_remote_* 参数加固。
     *
     * 即使 host 通过校验，一个 302 到 169.254.169.254 也能绕过，
     * 因此传输层必须关闭重定向。
     *
     * @param array $args 原始参数
     * @return array
     */
    public static function harden_request_args( $args ) {
        $args['redirection']        = 0;
        $args['reject_unsafe_urls'] = true;
        return $args;
    }
}
