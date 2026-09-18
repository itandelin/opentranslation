<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 用量看板页：30 天明细、本月累计、单价配置与费用估算。
 */
class Admin_Usage {

    const DAYS = 30;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
    }

    public function register_menu() {
        add_submenu_page( 'opentranslation', __( 'Usage', 'opentranslation' ), __( 'Usage', 'opentranslation' ), 'manage_options', 'opentranslation-usage', array( $this, 'render_page' ) );
    }

    public function render_page() {
        $this->handle_pricing_save();
        settings_errors( 'opentranslation_usage' );

        $settings  = get_option( 'opentranslation_settings', array() );
        $pricing   = isset( $settings['model_pricing'] ) && is_array( $settings['model_pricing'] ) ? $settings['model_pricing'] : array();
        $by_model  = Usage::by_model( self::DAYS );
        $daily     = Usage::daily( self::DAYS );
        $month     = Usage::month_totals();
        $month_cost = $this->estimate_month_cost( $pricing );
        $days      = self::DAYS;

        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-usage.php';
    }

    /**
     * 按单价估算一行的费用；未配置单价返回 null。
     *
     * @return float|null
     */
    public static function estimate( array $row, array $pricing ) {
        $key = isset( $row['model_key'] ) ? $row['model_key'] : '';
        if ( ! isset( $pricing[ $key ] ) ) {
            return null;
        }
        $p = (float) ( $pricing[ $key ]['prompt_per_1k'] ?? 0 );
        $c = (float) ( $pricing[ $key ]['completion_per_1k'] ?? 0 );
        return ( (int) $row['prompt_tokens'] / 1000 ) * $p + ( (int) $row['completion_tokens'] / 1000 ) * $c;
    }

    /**
     * 本月费用：只对配置了单价的模型求和；无任何单价返回 null。
     */
    private function estimate_month_cost( array $pricing ) {
        if ( empty( $pricing ) ) {
            return null;
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT model_key, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens
             FROM {$wpdb->prefix}opentranslation_usage WHERE usage_date >= %s GROUP BY model_key",
            gmdate( 'Y-m-01' )
        ), ARRAY_A );

        $sum = 0.0;
        foreach ( (array) $rows as $row ) {
            $sum += (float) self::estimate( $row, $pricing );
        }
        return $sum;
    }

    private function handle_pricing_save() {
        if ( ! isset( $_POST['opentranslation_usage_nonce'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentranslation_usage_nonce'] ) ), 'opentranslation_pricing_save' ) ) {
            return;
        }

        $raw     = isset( $_POST['pricing'] ) && is_array( $_POST['pricing'] ) ? wp_unslash( $_POST['pricing'] ) : array();
        $pricing = self::sanitize_pricing( $raw );

        $settings                  = get_option( 'opentranslation_settings', array() );
        $settings['model_pricing'] = $pricing;
        update_option( 'opentranslation_settings', $settings );

        add_settings_error( 'opentranslation_usage', 'pricing_saved', __( '单价已保存。', 'opentranslation' ), 'success' );
    }

    /**
     * 单价规范化：key 为 32 位 hex；两项单价 ≥ 0；币种 3 位大写字母。
     * 两项单价都为 0 的行视为未配置，不保存。
     */
    public static function sanitize_pricing( array $raw ) {
        $out = array();
        foreach ( $raw as $key => $row ) {
            if ( ! is_array( $row ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', (string) $key ) ) {
                continue;
            }
            $p = max( 0.0, (float) ( $row['prompt_per_1k'] ?? 0 ) );
            $c = max( 0.0, (float) ( $row['completion_per_1k'] ?? 0 ) );
            if ( 0.0 === $p && 0.0 === $c ) {
                continue;
            }
            $cur = strtoupper( sanitize_text_field( $row['currency'] ?? 'USD' ) );
            $out[ $key ] = array(
                'prompt_per_1k'     => $p,
                'completion_per_1k' => $c,
                'currency'          => 1 === preg_match( '/^[A-Z]{3}$/', $cur ) ? $cur : 'USD',
            );
        }
        return $out;
    }
}
