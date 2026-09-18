<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 术语表管理页。
 *
 * 模式与 Admin 的模型页一致：nonce + manage_options 能力检查 +
 * add / update / delete 三分派 + settings_errors。
 */
class Admin_Glossary {

    const PAGE         = 'opentranslation-glossary';
    const NONCE_ACTION = 'opentranslation_glossary_action';
    const NONCE_FIELD  = 'opentranslation_glossary_nonce';
    const ERROR_GROUP  = 'opentranslation_glossary';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'opentranslation',
            __( 'Glossary', 'opentranslation' ),
            __( 'Glossary', 'opentranslation' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        $this->handle_actions();
        settings_errors( self::ERROR_GROUP );

        $terms       = Glossary::get_all();
        $languages   = TP_Storage_Adapter::get_target_languages();
        $filter_lang = $this->current_filter_language( $languages );

        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-glossary.php';
    }

    /**
     * 分派 add / update / delete。
     *
     * nonce 只防 CSRF，不防越权：能力检查必须独立存在。
     */
    private function handle_actions() {
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        $terms = Glossary::get_all();
        $index = isset( $_POST['term_index'] ) ? absint( $_POST['term_index'] ) : -1;

        if ( isset( $_POST['delete_term'] ) && isset( $terms[ $index ] ) ) {
            array_splice( $terms, $index, 1 );
            Glossary::save( $terms );
            $this->notice( 'term_deleted', __( '术语已删除。', 'opentranslation' ) );
            return;
        }

        $term = $this->read_term_from_post();
        if ( null === $term ) {
            return;
        }

        if ( isset( $_POST['update_term'] ) && isset( $terms[ $index ] ) ) {
            $terms[ $index ] = $term;
            Glossary::save( $terms );
            $this->notice( 'term_updated', __( '术语已更新。', 'opentranslation' ) );
            return;
        }

        if ( isset( $_POST['add_term'] ) ) {
            $terms[] = $term;
            Glossary::save( $terms );
            $this->notice( 'term_added', __( '术语已添加。', 'opentranslation' ) );
        }
    }

    /**
     * 从表单读取术语字段。
     *
     * 校验失败时写入 settings_error 并返回 null。
     *
     * @return array|null
     */
    private function read_term_from_post() {
        $language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

        if ( ! in_array( $language, self::language_whitelist(), true ) ) {
            $this->error( 'invalid_language', __( '语言不在可翻译语言列表中。', 'opentranslation' ) );
            return null;
        }

        $term = Glossary::sanitize_term( array(
            'source'         => isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '',
            'target'         => isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : '',
            'language'       => $language,
            'case_sensitive' => ! empty( $_POST['case_sensitive'] ),
            'whole_word'     => ! empty( $_POST['whole_word'] ),
            'note'           => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
        ) );

        if ( is_wp_error( $term ) ) {
            $this->error( $term->get_error_code(), $term->get_error_message() );
            return null;
        }

        return $term;
    }

    /**
     * 语言白名单：''（所有语言）+ TP 目标语言。
     *
     * @return array
     */
    private static function language_whitelist() {
        return array_merge( array( '' ), TP_Storage_Adapter::get_target_languages() );
    }

    /**
     * 当前语言筛选，非法值回落 ''。
     *
     * @param array $languages TP 目标语言列表
     * @return string
     */
    private function current_filter_language( array $languages ) {
        $lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
        return in_array( $lang, $languages, true ) ? $lang : '';
    }

    private function notice( $code, $message ) {
        add_settings_error( self::ERROR_GROUP, $code, $message, 'success' );
    }

    private function error( $code, $message ) {
        add_settings_error( self::ERROR_GROUP, $code, $message, 'error' );
    }
}