<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 操作日志。
 *
 * 相对旧版的三个改动：
 * 1. 分级：scheduler_run 这类心跳仅在 WP_DEBUG 下入库。
 *    线上实测 9403 行日志里 4738 行是 scheduler_run，占 50% 全是噪音。
 * 2. 脱敏：上游错误响应常含请求头片段、账号 ID、key 前缀，入库前打码。
 * 3. 清理：按天节流删除过期记录，避免无限膨胀。
 */
class Log {

    const LEVEL_ERROR = 'error';
    const LEVEL_WARN  = 'warn';
    const LEVEL_INFO  = 'info';
    const LEVEL_DEBUG = 'debug';

    const CLEANUP_OPTION = 'opentranslation_log_cleanup_at';

    /**
     * 各 action 的级别。未列出的按 info 处理。
     */
    private static function action_levels() {
        return array(
            'failed'                => self::LEVEL_ERROR,
            'tp_engine_error'       => self::LEVEL_ERROR,
            'tp_bulk_update_failed' => self::LEVEL_ERROR,
            'cache_insert_failed'   => self::LEVEL_ERROR,
            'cache_update_failed'   => self::LEVEL_ERROR,
            'cache_set_failed'      => self::LEVEL_ERROR,
            'retry'                 => self::LEVEL_WARN,
            'model_fallback'        => self::LEVEL_WARN,
            'model_circuit_open'    => self::LEVEL_WARN,
            'scheduler_run'         => self::LEVEL_DEBUG,
        );
    }

    public static function level_for( $action ) {
        $levels = self::action_levels();
        return isset( $levels[ $action ] ) ? $levels[ $action ] : self::LEVEL_INFO;
    }

    /**
     * debug 级仅在 WP_DEBUG 开启时入库。
     */
    private static function should_persist( $level ) {
        if ( self::LEVEL_DEBUG !== $level ) {
            return true;
        }
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }

    /**
     * 移除消息中的凭据片段。
     *
     * @param string $message 原始消息
     * @return string
     */
    public static function redact( $message ) {
        $message = (string) $message;
        if ( '' === $message ) {
            return '';
        }

        $patterns = array(
            '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]{8,}/i' => '$1[redacted]',
            '/\bsk-[A-Za-z0-9\-_]{8,}/i'              => '[redacted]',
            '/("(?:x-api-key|api_key|apiKey|authorization)"\s*:\s*")[^"]{8,}(")/i' => '$1[redacted]$2',
        );

        foreach ( $patterns as $pattern => $replacement ) {
            $message = preg_replace( $pattern, $replacement, $message );
        }

        return $message;
    }

    public static function add( $cache_key, $action, $message = '' ) {
        if ( ! self::should_persist( self::level_for( $action ) ) ) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_log';

        return false !== $wpdb->insert(
            $table,
            array(
                'cache_key' => $cache_key,
                'action'    => $action,
                'message'   => self::redact( $message ),
            ),
            array( '%s', '%s', '%s' )
        );
    }

    /**
     * 分页 + 按 action 筛选。
     *
     * 用 id 而非 created_at 排序：created_at 无唯一性，
     * 同秒多条时分页会重复或漏行。
     *
     * @param int    $limit  每页条数
     * @param int    $offset 偏移
     * @param string $action 为空则不筛选
     * @return array
     */
    public static function get_recent( $limit = 50, $offset = 0, $action = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_log';

        if ( '' !== $action ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d OFFSET %d",
                    $action,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    public static function count_all( $action = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_log';

        if ( '' !== $action ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", $action )
            );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * 所有出现过的 action，供筛选下拉。
     */
    public static function get_actions() {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_log';
        return $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action ASC" );
    }

    /**
     * 动作名白名单化：不在已知动作列表内一律置空。
     *
     * get_recent() / count_all() 内部已走 $wpdb->prepare，
     * 这里在调用点显式净化，既避免无意义查询，
     * 也让静态审计能看出消费方只会收到白名单值。
     *
     * @param string $action 待净化的动作名
     * @return string 白名单内的动作名，否则空串
     */
    public static function sanitize_action( $action ) {
        $action = sanitize_key( (string) $action );
        if ( '' === $action ) {
            return '';
        }
        return in_array( $action, self::get_actions(), true ) ? $action : '';
    }

    /**
     * 删除 N 天前的日志。
     *
     * @param int $days 保留天数
     * @return int 删除行数
     */
    public static function cleanup( $days = 30 ) {
        $days = max( 1, (int) $days );

        global $wpdb;
        $table  = $wpdb->prefix . 'opentranslation_log';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        return (int) $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
        );
    }

    /**
     * 按天节流的清理入口，供 Scheduler 调用。
     */
    public static function maybe_cleanup() {
        $last = (int) get_option( self::CLEANUP_OPTION, 0 );
        if ( ( time() - $last ) < DAY_IN_SECONDS ) {
            return;
        }

        $days = (int) apply_filters( 'opentranslation_log_retention_days', 30 );
        self::cleanup( max( 1, min( 365, $days ) ) );

        update_option( self::CLEANUP_OPTION, time(), false );
    }
}
