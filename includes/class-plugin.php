<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

        // 直接调用而非挂钩子：本方法已在 plugins_loaded 回调内执行，
        // 再往同一钩子挂同优先级回调本轮不会被触发（PHP foreach 迭代数组副本），
        // 而 plugins_loaded 不会再触发第二次——这是语言包从不加载的原因。
        $this->load_textdomain();

        if ( is_admin() ) {
            new Admin();
            new Admin_Ajax();
            new Admin_Actions();
        }

        Activator::maybe_upgrade();
        new Scheduler();
    }

    public function load_textdomain() {
        $settings = get_option( 'opentranslation_settings', array() );
        $lang = isset( $settings['plugin_language'] ) ? $settings['plugin_language'] : 'zh_CN';

        unload_textdomain( 'opentranslation' );

        // en_US 即源码里 __() 的原文，不该加载任何 .mo，
        // 否则可能命中系统语言的语言包造成混排。
        if ( 'en_US' === $lang ) {
            return;
        }

        load_textdomain( 'opentranslation', OPENTRANSLATION_PLUGIN_DIR . 'languages/opentranslation-zh_CN.mo' );
    }

    public function add_cron_interval( $schedules ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $interval = isset( $settings['cron_interval'] ) ? absint( $settings['cron_interval'] ) : 5;
        $interval = max( 1, $interval );

        $schedules['opentranslation_interval'] = array(
            'interval' => $interval * MINUTE_IN_SECONDS,
            'display'  => sprintf( __( 'Every %d minutes', 'opentranslation' ), $interval ),
        );

        return $schedules;
    }
}
