<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 后台 admin-post 动作处理（重置模型熔断）。
 *
 * 从 Admin 拆出以满足单文件 300 行上限。
 */
class Admin_Actions {

    public function __construct() {
        add_action( 'admin_post_opentranslation_reset_circuit', array( $this, 'handle_reset_circuit' ) );
    }

    /**
     * 重置单个或全部模型的熔断状态。
     *
     * GET key 为 32 位 hex（模型 key）或 all。来源页带 message=circuit_reset。
     */
    public function handle_reset_circuit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_reset_circuit' );

        $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

        if ( 'all' === $key ) {
            $models = Encrypted_Options::get( 'opentranslation_models', array() );
            foreach ( $models as $config ) {
                Model_Health::reset( Model_Identity::key( $config ) );
            }
        } elseif ( 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
            Model_Health::reset( $key );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-models&message=circuit_reset' ) );
        exit;
    }
}
