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

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( (string) $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return trim( strip_tags( (string) $str ) );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}

// 内存版 option：测试里直接读写 $GLOBALS['ot_test_options']
if ( ! function_exists( 'get_option' ) ) {
    $GLOBALS['ot_test_options'] = array();

    function get_option( $name, $default = false ) {
        return array_key_exists( $name, $GLOBALS['ot_test_options'] )
            ? $GLOBALS['ot_test_options'][ $name ]
            : $default;
    }
    function update_option( $name, $value, $autoload = null ) {
        $GLOBALS['ot_test_options'][ $name ] = $value;
        return true;
    }
    function delete_option( $name ) {
        unset( $GLOBALS['ot_test_options'][ $name ] );
        return true;
    }
}

// 极简 $wpdb stub。
//
// 写方法一律无操作并返回成功：Log::add() 与 Usage::record() 属于旁路记录，
// 不应影响被测的翻译逻辑。$GLOBALS['ot_test_queries'] 留给需要断言写入的用例。
if ( ! class_exists( 'OT_Test_WPDB' ) ) {
    class OT_Test_WPDB {
        public $prefix = 'wp_';
        public $last_error = '';

        public function insert( $table, $data, $format = null ) {
            $GLOBALS['ot_test_queries'][] = array( 'insert', $table, $data );
            return 1;
        }

        public function query( $sql ) {
            $GLOBALS['ot_test_queries'][] = array( 'query', $sql );
            return 1;
        }

        public function prepare( $sql, ...$args ) {
            return $sql;
        }

        public function get_var( $sql ) {
            return null;
        }

        public function get_results( $sql, $output = null ) {
            return array();
        }
    }
}
if ( ! isset( $GLOBALS['ot_test_queries'] ) ) {
    $GLOBALS['ot_test_queries'] = array();
}
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new OT_Test_WPDB();
}
if ( ! function_exists( 'esc_sql' ) ) {
    function esc_sql( $value ) {
        return addslashes( (string) $value );
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
    function wp_remote_retrieve_header( $r, $header ) {
        if ( ! is_array( $r ) || ! isset( $r['headers'] ) || ! is_array( $r['headers'] ) ) {
            return '';
        }
        $header = strtolower( $header );
        foreach ( $r['headers'] as $name => $value ) {
            if ( strtolower( $name ) === $header ) {
                return $value;
            }
        }
        return '';
    }
}

// WP 条件标签 stub：用 ot_wp_page_set() 在用例间切换「当前渲染的页面」
if ( ! function_exists( 'is_singular' ) ) {
    $GLOBALS['ot_wp_page'] = array(
        'wp_done'     => 1,         // did_action( 'wp' ) 的返回值，0 表示主查询未就绪
        'singular'    => false,
        'post_type'   => '',
        'post_status' => 'publish',
    );

    function ot_wp_page_set( array $state = array() ) {
        $GLOBALS['ot_wp_page'] = array_merge(
            array( 'wp_done' => 1, 'singular' => false, 'post_type' => '', 'post_status' => 'publish' ),
            $state
        );
    }
    function did_action( $hook ) {
        return ( 'wp' === $hook ) ? (int) $GLOBALS['ot_wp_page']['wp_done'] : 1;
    }
    function is_singular( $post_types = '' ) {
        return (bool) $GLOBALS['ot_wp_page']['singular'];
    }
    function get_post_type( $post = null ) {
        return $GLOBALS['ot_wp_page']['post_type'];
    }
    function get_post_status( $post = null ) {
        return $GLOBALS['ot_wp_page']['post_status'];
    }
}
// 请求上下文 stub：用 ot_wp_context_set() 切换「当前请求的身份」
if ( ! function_exists( 'is_admin' ) ) {
    $GLOBALS['ot_wp_context'] = array(
        'admin'      => false,
        'ajax'       => false,
        'cron'       => false,
        'logged_in'  => false,
        'can_manage' => false,
    );

    function ot_wp_context_set( array $state = array() ) {
        $GLOBALS['ot_wp_context'] = array_merge(
            array( 'admin' => false, 'ajax' => false, 'cron' => false, 'logged_in' => false, 'can_manage' => false ),
            $state
        );
    }
    function is_admin() {
        return (bool) $GLOBALS['ot_wp_context']['admin'];
    }
    function wp_doing_ajax() {
        return (bool) $GLOBALS['ot_wp_context']['ajax'];
    }
    function wp_doing_cron() {
        return (bool) $GLOBALS['ot_wp_context']['cron'];
    }
    function is_user_logged_in() {
        return (bool) $GLOBALS['ot_wp_context']['logged_in'];
    }
    function current_user_can( $cap ) {
        return (bool) $GLOBALS['ot_wp_context']['can_manage'];
    }
}


// 被测类里需要在 stub 之后加载的：Request_Budget 读 option / 用 $wpdb
require_once __DIR__ . '/../includes/class-request-budget.php';
