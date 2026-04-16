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
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        if ( is_admin() ) {
            new Admin();
        }

        new Scheduler();
    }

    public function load_textdomain() {
        $settings = get_option( 'opentranslation_settings', array() );
        $lang = isset( $settings['plugin_language'] ) ? $settings['plugin_language'] : 'zh_CN';

        if ( 'zh_CN' === $lang ) {
            unload_textdomain( 'opentranslation' );
            load_textdomain( 'opentranslation', OPENTRANSLATION_PLUGIN_DIR . 'languages/opentranslation-zh_CN.mo' );
        } else {
            load_plugin_textdomain( 'opentranslation', false, dirname( plugin_basename( OPENTRANSLATION_PLUGIN_DIR . 'opentranslation.php' ) ) . '/languages/' );
        }
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
