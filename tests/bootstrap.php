<?php
/**
 * 让纯逻辑类脱离 WordPress 运行的最小 stub。
 * 只 stub 被测类实际用到的函数，不做完整模拟。
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

// i18n：直接返回原文
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        return $text;
    }
}

// 过滤器：测试中不挂钩子，直接返回默认值
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        if ( isset( $GLOBALS['ot_test_filters'][ $tag ] ) ) {
            return call_user_func( $GLOBALS['ot_test_filters'][ $tag ], $value );
        }
        return $value;
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, '/\\' ) . '/';
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0 ) {
        return json_encode( $data, $options );
    }
}

// WP_Error 最小实现
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data( $key = '' ) {
            if ( '' === $key ) {
                return $this->data;
            }
            return is_array( $this->data ) && isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

// 极简过滤器注册表：测试里可用 add_filter 覆盖默认值（如缩短重试延迟）
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback ) {
        $GLOBALS['ot_test_filters'][ $tag ] = $callback;
    }
    function remove_filter( $tag ) {
        unset( $GLOBALS['ot_test_filters'][ $tag ] );
    }
}
// 覆盖上方的直通版 apply_filters：有注册则调用，无则返回默认值
if ( ! isset( $GLOBALS['ot_test_filters'] ) ) {
    $GLOBALS['ot_test_filters'] = array();
}

// HTTP stub：wp_remote_post 按队列依次返回预设响应，并记录请求体
if ( ! function_exists( 'wp_remote_post' ) ) {
    $GLOBALS['ot_http_queue'] = array();
    $GLOBALS['ot_http_log']   = array();

    function ot_http_enqueue( $code, $body ) {
        $GLOBALS['ot_http_queue'][] = ( $code instanceof WP_Error )
            ? $code
            : array( 'response' => array( 'code' => $code, 'message' => 'stub' ), 'body' => $body );
    }
    function ot_http_reset() {
        $GLOBALS['ot_http_queue'] = array();
        $GLOBALS['ot_http_log']   = array();
    }
    function wp_remote_post( $url, $args = array() ) {
        $GLOBALS['ot_http_log'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
        if ( empty( $GLOBALS['ot_http_queue'] ) ) {
            return new WP_Error( 'stub_exhausted', 'no queued response' );
        }
        return array_shift( $GLOBALS['ot_http_queue'] );
    }
    function wp_remote_retrieve_body( $r ) {
        return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
    }
    function wp_remote_retrieve_response_code( $r ) {
        return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : '';
    }
    function wp_remote_retrieve_response_message( $r ) {
        return is_array( $r ) && isset( $r['response']['message'] ) ? $r['response']['message'] : '';
    }
}
