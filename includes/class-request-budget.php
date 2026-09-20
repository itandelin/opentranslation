<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 单次页面渲染的翻译预算。
 *
 * TranslatePress 把页面上全部新字符串一次性交给引擎，没有任何数量上限
 * （class-translation-render.php:1774-1806 的收集循环无 array_slice）。
 * Google 引擎无所谓（128 条一次请求、百毫秒级），但 AI 模型是 10 条一次、
 * 秒级响应，内容多的页面首屏会被阻塞到分钟级。
 *
 * 预算机制依赖 TP 的一个既有行为：引擎少返回 key 是被容忍的
 * （class-machine-translator.php:420-434 只遍历实际返回的 key），
 * 未翻译的字符串下次渲染会被重新提交。因此「本轮只翻一部分」是安全的，
 * 多次访问自然收敛，不需要任何自建队列。
 */
class Request_Budget {

    /** @var int 本轮还可提交给模型的条数 */
    private $strings_left;

    /** @var float 本轮剩余墙钟预算（秒） */
    private $seconds_left;

    /** @var bool 是否受墙钟约束（cron / CLI 不受） */
    private $time_limited;

    /** @var int 单次模型请求的条数 */
    private $chunk_size;

    private function __construct( $max_strings, $max_seconds, $chunk_size ) {
        $this->strings_left = $max_strings;
        $this->seconds_left = (float) $max_seconds;
        $this->time_limited = $max_seconds > 0;
        $this->chunk_size   = $chunk_size;
    }

    /**
     * 按当前请求的身份给出预算。
     *
     * 管理员预算放宽：手动浏览一遍站点即可快速预热，
     * 这比自建 cron 队列直观，也不占用匿名访客的首屏时间。
     */
    public static function for_current_request() {
        $privileged = self::is_privileged();

        $max_strings = $privileged ? 200 : 30;
        $max_seconds = $privileged ? 60 : 6;

        // cron 与 WP-CLI 没有首屏可言，不设墙钟上限
        if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
            $max_strings = 1000;
            $max_seconds = 0;
        }

        $max_strings = (int) apply_filters( 'opentranslation_request_max_strings', $max_strings, $privileged );
        $max_seconds = (float) apply_filters( 'opentranslation_request_max_seconds', $max_seconds, $privileged );
        $chunk_size  = (int) apply_filters( 'opentranslation_request_chunk_size', 10 );

        return new self(
            max( 1, $max_strings ),
            max( 0.0, $max_seconds ),
            max( 1, min( 20, $chunk_size ) )
        );
    }

    /**
     * 后台、AJAX、REST、cron、CLI 以及登录管理员都算「特权请求」。
     */
    private static function is_privileged() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return true;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }

        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    public function chunk_size() {
        return $this->chunk_size;
    }

    /**
     * 预算是否已用尽。
     */
    public function exhausted() {
        if ( $this->strings_left <= 0 ) {
            return true;
        }

        return $this->time_limited && $this->seconds_left <= 0.0;
    }

    /**
     * 扣减一次 chunk 的消耗。
     *
     * @param int   $strings 本次提交的条数
     * @param float $elapsed 本次耗时（秒）
     */
    public function consume( $strings, $elapsed ) {
        $this->strings_left -= (int) $strings;

        if ( $this->time_limited ) {
            $this->seconds_left -= (float) $elapsed;
        }
    }
}
