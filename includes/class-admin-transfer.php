<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 配置导出导入页。
 *
 * 导入分三步：上传 → 预览 → 确认。中间态（validate() 的结果）按用户存 transient，
 * 15 分钟过期。不把结果塞进 URL 或隐藏域：既超长，也会让用户可篡改已校验的数据。
 *
 * 导出与三步的分派都挂在 admin_init：导出要先发 header 再 exit，
 * 到 render_page() 时 admin-header.php 已经输出，header() 会失效。
 */
class Admin_Transfer {

    const PAGE         = 'opentranslation-transfer';
    const NONCE_ACTION = 'opentranslation_transfer_action';
    const NONCE_FIELD  = 'opentranslation_transfer_nonce';

    /**
     * transient 名前缀。带 user id：预览态属于操作者本人，
     * 两个管理员同时导入不会互相覆盖。Uninstaller 按此前缀清理。
     */
    const TRANSIENT_PREFIX = 'opentranslation_import_';

    const TRANSIENT_TTL = 900;

    /**
     * 上传体积上限 1 MB。配置文件即便含全部术语也远小于此，
     * 超限基本意味着传错了文件。
     */
    const MAX_UPLOAD_BYTES = 1048576;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'opentranslation',
            __( 'Import / Export', 'opentranslation' ),
            __( 'Import / Export', 'opentranslation' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render_page' )
        );
    }

    /**
     * 分派导出 / 上传 / 确认 / 取消。
     *
     * nonce 只防 CSRF，不防越权：能力检查必须独立存在。
     */
    public function handle_actions() {
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        if ( isset( $_POST['export_config'] ) ) {
            $this->send_export( ! empty( $_POST['include_api_keys'] ) );
        }
        if ( isset( $_POST['upload_config'] ) ) {
            $this->handle_upload();
        }
        if ( isset( $_POST['confirm_import'] ) ) {
            $this->handle_confirm();
        }
        if ( isset( $_POST['cancel_import'] ) ) {
            delete_transient( self::transient_key() );
            $this->redirect( array( 'message' => 'cancelled' ) );
        }
    }

    /**
     * 直接吐 JSON 附件。
     */
    private function send_export( $include_keys ) {
        $json = wp_json_encode(
            Config_Transfer::export( $include_keys ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="opentranslation-config-' . gmdate( 'Ymd-Hi' ) . '.json"' );
        header( 'Content-Length: ' . strlen( $json ) );

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON 附件，非 HTML
        exit;
    }

    /**
     * 上传：读文件 → 解码 → 校验 → 存 transient → 转到预览。
     *
     * 解码失败也存 transient（ok=false），让预览页统一展示拒绝原因，
     * 不必把原因塞进 URL。
     */
    private function handle_upload() {
        $raw = $this->read_upload();
        if ( is_wp_error( $raw ) ) {
            $this->redirect( array( 'message' => $raw->get_error_code() ) );
        }

        $data = self::decode_payload( $raw );
        if ( is_wp_error( $data ) ) {
            $this->stash( self::rejection( $data->get_error_message() ) );
            $this->redirect( array( 'step' => 'preview' ) );
        }

        $this->stash( Config_Transfer::validate(
            $data,
            self::existing_models(),
            TP_Storage_Adapter::get_target_languages()
        ) );
        $this->redirect( array( 'step' => 'preview' ) );
    }

    /**
     * 取上传文件内容。
     *
     * @return string|\WP_Error
     */
    private function read_upload() {
        if ( empty( $_FILES['config'] ) || ! is_array( $_FILES['config'] ) ) {
            return new \WP_Error( 'no_file', __( '未选择文件。', 'opentranslation' ) );
        }

        $file = $_FILES['config'];
        if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
            return new \WP_Error( 'upload_failed', __( '文件上传失败。', 'opentranslation' ) );
        }

        // 防止把任意本地路径当成上传文件读取
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new \WP_Error( 'upload_failed', __( '文件上传失败。', 'opentranslation' ) );
        }

        return (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /**
     * 确认导入：transient 过期则明确提示，不静默失败。
     */
    private function handle_confirm() {
        $result = get_transient( self::transient_key() );

        if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
            delete_transient( self::transient_key() );
            $this->redirect( array( 'message' => 'expired' ) );
        }

        Config_Transfer::apply( $result['normalized'] );
        delete_transient( self::transient_key() );
        $this->redirect( array( 'message' => 'imported' ) );
    }

    public function render_page() {
        $step    = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
        $message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
        $result  = 'preview' === $step ? get_transient( self::transient_key() ) : false;

        // 预览态已过期：退回首屏并说明，避免展示空白预览
        if ( 'preview' === $step && ! is_array( $result ) ) {
            $step    = '';
            $message = 'expired';
        }

        $notice = self::notice( $message );

        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-transfer.php';
    }

    /**
     * 解码上传内容。纯函数：不碰 $_FILES，可单测。
     *
     * @param string $raw 文件原始内容
     * @return array|\WP_Error 顶层数组
     */
    public static function decode_payload( $raw ) {
        $raw = (string) $raw;

        if ( strlen( $raw ) > self::MAX_UPLOAD_BYTES ) {
            return new \WP_Error( 'too_large', __( '文件超过 1 MB，不像是配置文件。', 'opentranslation' ) );
        }
        if ( '' === trim( $raw ) ) {
            return new \WP_Error( 'invalid_json', __( '文件内容为空。', 'opentranslation' ) );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return new \WP_Error( 'invalid_json', __( '文件不是合法的 JSON 对象。', 'opentranslation' ) );
        }

        return $data;
    }

    /**
     * 预览里某个模型的密钥来源。
     *
     * 来源由 Config_Transfer::validate() 在解析时如实记录（key_sources，与
     * normalized['models'] 同序），这里不再从 includes_api_keys 反推——
     * 反推会把「文件声明含密钥、但该条为空、实际沿用了本站密钥」误标成「文件提供」。
     *
     * @param array       $model    normalized 中的模型
     * @param string|null $recorded key_sources 中对应项；缺失时按 api_key 兜底
     * @return string missing|from_file|reused
     */
    public static function key_status( array $model, $recorded = null ) {
        if ( in_array( $recorded, array( 'from_file', 'reused', 'missing' ), true ) ) {
            return $recorded;
        }

        // 兜底：升级前存下的预览 transient 没有 key_sources
        return '' === ( isset( $model['api_key'] ) ? (string) $model['api_key'] : '' ) ? 'missing' : 'reused';
    }

    /**
     * 目标站现有模型（含明文 key），供 validate() 按 identity 沿用密钥。
     */
    private static function existing_models() {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        return is_array( $models ) ? $models : array();
    }

    private static function transient_key() {
        return self::TRANSIENT_PREFIX . get_current_user_id();
    }

    private function stash( array $result ) {
        set_transient( self::transient_key(), $result, self::TRANSIENT_TTL );
    }

    private static function rejection( $reason ) {
        return array(
            'ok'         => false,
            'reason'     => $reason,
            'normalized' => array(),
            'summary'    => array(),
            'skipped'    => array(),
        );
    }

    /**
     * 顶部提示。跨重定向传递用 message 码而非 settings_error：
     * add_settings_error 的内容不跨请求存活。
     *
     * @return array|null array( text, type )
     */
    private static function notice( $message ) {
        $map = array(
            'imported'      => array( __( '配置已导入。', 'opentranslation' ), 'success' ),
            'cancelled'     => array( __( '已取消导入，未做任何改动。', 'opentranslation' ), 'info' ),
            'expired'       => array( __( '预览已过期或不存在，请重新上传配置文件。', 'opentranslation' ), 'error' ),
            'no_file'       => array( __( '未选择文件。', 'opentranslation' ), 'error' ),
            'upload_failed' => array( __( '文件上传失败，请重试。', 'opentranslation' ), 'error' ),
        );

        if ( ! isset( $map[ $message ] ) ) {
            return null;
        }

        return array( 'text' => $map[ $message ][0], 'type' => $map[ $message ][1] );
    }

    private function redirect( array $args ) {
        $args['page'] = self::PAGE;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
