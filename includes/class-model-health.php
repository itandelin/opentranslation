<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 模型健康状态机（熔断器）。
 *
 * 连续 FAILURE_THRESHOLD 次批级失败后熔断 open_seconds() 秒；
 * 到期后放行一次半开探测：成功清零，失败退避加倍。
 *
 * 所有判定方法接受 $now（默认 time()），单元测试不依赖 sleep。
 * 结果先累加在内存，由 flush() 在批末写一次 option。
 */
class Model_Health {

    const OPTION            = 'opentranslation_model_health';
    const FAILURE_THRESHOLD = 3;
    const BASE_OPEN_SECONDS = 300;
    const MAX_OPEN_SECONDS  = 3600;
    const MAX_ERROR_LENGTH  = 200;

    private $states = array();
    private $dirty  = false;

    public function __construct() {
        $stored       = get_option( self::OPTION );
        $this->states = is_array( $stored ) ? $stored : array();
    }

    /**
     * 取某模型的健康状态，缺省字段补零。
     *
     * @param string $key Model_Identity::key()
     * @return array
     */
    public function state( $key ) {
        $defaults = array(
            'consecutive_failures' => 0,
            'total_success'        => 0,
            'total_failure'        => 0,
            'last_error'           => '',
            'last_error_at'        => 0,
            'open_until'           => 0,
            'half_open'            => false,
        );

        $state = ( isset( $this->states[ $key ] ) && is_array( $this->states[ $key ] ) )
            ? $this->states[ $key ]
            : array();

        return array_merge( $defaults, $state );
    }

    /**
     * 是否可请求。熔断到期时放行一次并置半开。
     *
     * @param string   $key
     * @param int|null $now
     * @return bool
     */
    public function is_available( $key, $now = null ) {
        $now   = null === $now ? time() : (int) $now;
        $state = $this->state( $key );

        if ( (int) $state['open_until'] <= 0 ) {
            return true;
        }

        if ( $now < (int) $state['open_until'] ) {
            return false;
        }

        // 到期：放行一次半开探测。批量多 chunk 同时探测可接受，
        // 队列锁保证同一时刻只有一个执行者。
        if ( empty( $state['half_open'] ) ) {
            $this->states[ $key ] = array_merge( $state, array( 'half_open' => true ) );
            $this->dirty          = true;
        }

        return true;
    }

    /**
     * 记一次成功：清零失败计数并关闭熔断，累计成功率照记。
     */
    public function record_success( $key, $now = null ) {
        $state = $this->state( $key );

        $state['total_success']        = (int) $state['total_success'] + 1;
        $state['consecutive_failures'] = 0;
        $state['open_until']           = 0;
        $state['half_open']            = false;

        $this->states[ $key ] = $state;
        $this->dirty          = true;
    }

    /**
     * 记一次失败，达阈值则熔断、退避按连续次数加倍。
     *
     * @param string   $key
     * @param string   $message 上游错误消息（入库前脱敏并截断）
     * @param int|null $now
     */
    public function record_failure( $key, $message, $now = null ) {
        $now   = null === $now ? time() : (int) $now;
        $state = $this->state( $key );

        $state['consecutive_failures'] = (int) $state['consecutive_failures'] + 1;
        $state['total_failure']        = (int) $state['total_failure'] + 1;
        $state['last_error']           = self::sanitize_error( $message );
        $state['last_error_at']        = $now;

        if ( $state['consecutive_failures'] >= self::FAILURE_THRESHOLD ) {
            $state['open_until'] = $now + self::open_seconds( $state['consecutive_failures'] );
            $state['half_open']  = false;
        }

        $this->states[ $key ] = $state;
        $this->dirty          = true;
    }

    /**
     * 过滤掉熔断中的模型，保持原有优先级顺序。
     *
     * @param array    $models 模型配置列表
     * @param int|null $now
     * @return array
     */
    public function filter_available( array $models, $now = null ) {
        $available = array();
        foreach ( $models as $config ) {
            if ( $this->is_available( Model_Identity::key( $config ), $now ) ) {
                $available[] = $config;
            }
        }
        return $available;
    }

    /**
     * 是否所有已配置模型都不可用。未配置模型时返回 false（不阻塞调度）。
     *
     * @param array    $models
     * @param int|null $now
     * @return bool
     */
    public function all_open( array $models, $now = null ) {
        if ( empty( $models ) ) {
            return false;
        }

        foreach ( $models as $config ) {
            if ( $this->is_available( Model_Identity::key( $config ), $now ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 有待写状态时落盘一次。
     *
     * @return bool 是否写入
     */
    public function flush() {
        if ( ! $this->dirty ) {
            return false;
        }

        update_option( self::OPTION, $this->states, false );
        $this->dirty = false;

        return true;
    }

    /**
     * 熔断时长：300 * 2^(n-3)，上限 3600 秒。
     *
     * @param int $failures 连续失败次数
     * @return int
     */
    public static function open_seconds( $failures ) {
        $failures = (int) $failures;
        if ( $failures < self::FAILURE_THRESHOLD ) {
            return 0;
        }

        $seconds = self::BASE_OPEN_SECONDS * pow( 2, $failures - self::FAILURE_THRESHOLD );

        return (int) min( $seconds, self::MAX_OPEN_SECONDS );
    }

    /**
     * 手动重置某模型的熔断状态。
     *
     * @param string $key
     * @return bool
     */
    public static function reset( $key ) {
        $stored = get_option( self::OPTION );
        $states = is_array( $stored ) ? $stored : array();

        if ( isset( $states[ $key ] ) && is_array( $states[ $key ] ) ) {
            $states[ $key ]['consecutive_failures'] = 0;
            $states[ $key ]['open_until']           = 0;
            $states[ $key ]['half_open']            = false;
        }

        update_option( self::OPTION, $states, false );

        return true;
    }

    /**
     * 剩余熔断秒数，供后台展示。
     *
     * @param string   $key
     * @param int|null $now
     * @return int
     */
    public static function open_remaining( $key, $now = null ) {
        $now    = null === $now ? time() : (int) $now;
        $stored = get_option( self::OPTION );
        $states = is_array( $stored ) ? $stored : array();

        $open_until = isset( $states[ $key ]['open_until'] ) ? (int) $states[ $key ]['open_until'] : 0;

        return $open_until > $now ? $open_until - $now : 0;
    }

    /**
     * 错误消息脱敏并截断，避免 option 膨胀与凭据泄漏。
     *
     * @param string $message
     * @return string
     */
    private static function sanitize_error( $message ) {
        $message = Log::redact( (string) $message );

        if ( mb_strlen( $message ) > self::MAX_ERROR_LENGTH ) {
            $message = mb_substr( $message, 0, self::MAX_ERROR_LENGTH );
        }

        return $message;
    }
}