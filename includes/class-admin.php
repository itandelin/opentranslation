<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_opentranslation_run_queue', array( $this, 'handle_run_queue' ) );
        add_action( 'admin_post_opentranslation_retry_failed', array( $this, 'handle_retry_failed' ) );
        add_action( 'admin_post_opentranslation_toggle_language', array( $this, 'handle_toggle_language' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_decrypt_warning' ) );
    }

    /**
     * 解密失败时明确告警。
     *
     * 否则用户只会看到「No models configured」，
     * 完全无法判断是没配过还是 AUTH_KEY 变更导致读不出来。
     */
    public function maybe_show_decrypt_warning() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( '' === Encrypted_Options::get_decrypt_failure() ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__( 'OpenTranslation:', 'opentranslation' ),
            esc_html__( 'Saved model configuration could not be decrypted. This usually means AUTH_KEY in wp-config.php has changed. Please re-enter the model configuration, or restore the original AUTH_KEY.', 'opentranslation' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'opentranslation' ) === false ) {
            return;
        }
        wp_enqueue_script( 'opentranslation-admin', OPENTRANSLATION_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), OPENTRANSLATION_VERSION, true );
        wp_localize_script( 'opentranslation-admin', 'opentranslation_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'opentranslation_fetch_models_nonce' ),
            'strings'  => array(
                'fetching'     => __( '正在获取模型列表...', 'opentranslation' ),
                'fetch_models' => __( '获取模型列表', 'opentranslation' ),
                'no_models'    => __( '未获取到模型列表，请手动输入模型名称。', 'opentranslation' ),
                'error'        => __( '获取失败，请检查 API Key 和 Base URL 是否正确。', 'opentranslation' ),
                'testing'      => __( '正在测试...', 'opentranslation' ),
                'test_model'   => __( '测试', 'opentranslation' ),
                'test_success' => __( '连接成功', 'opentranslation' ),
                'test_failed'  => __( '连接失败', 'opentranslation' ),
            ),
            'test_nonce' => wp_create_nonce( 'opentranslation_test_model_nonce' ),
        ) );
    }

    public function register_menus() {
        add_menu_page( __( 'OpenTranslation', 'opentranslation' ), __( 'OpenTranslation', 'opentranslation' ), 'manage_options', 'opentranslation', array( $this, 'render_settings_page' ), 'dashicons-translation', 80 );
        add_submenu_page( 'opentranslation', __( 'Settings', 'opentranslation' ), __( 'Settings', 'opentranslation' ), 'manage_options', 'opentranslation', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'opentranslation', __( 'Models', 'opentranslation' ), __( 'Models', 'opentranslation' ), 'manage_options', 'opentranslation-models', array( $this, 'render_models_page' ) );
        add_submenu_page( 'opentranslation', __( 'Queue & Logs', 'opentranslation' ), __( 'Queue & Logs', 'opentranslation' ), 'manage_options', 'opentranslation-queue', array( $this, 'render_queue_page' ) );
    }

    public function register_settings() {
        register_setting( 'opentranslation_settings', 'opentranslation_settings', array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $existing = get_option( 'opentranslation_settings', array() );

        $output = array();
        $output['batch_size'] = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : 10;
        $output['cron_interval'] = isset( $input['cron_interval'] ) ? absint( $input['cron_interval'] ) : 5;
        $output['rate_limit_per_minute'] = isset( $input['rate_limit_per_minute'] ) ? absint( $input['rate_limit_per_minute'] ) : 20;
        $output['system_prompt'] = isset( $input['system_prompt'] ) ? sanitize_textarea_field( $input['system_prompt'] ) : self::default_system_prompt();

        // 暂停语言由 Queue 页维护，Settings 表单不含该字段。
        // 若不保留原值，保存一次设置就会让所有已暂停语言恢复翻译，直接影响 API 账单。
        if ( isset( $input['disabled_languages'] ) && is_array( $input['disabled_languages'] ) ) {
            $output['disabled_languages'] = array_map( 'sanitize_text_field', $input['disabled_languages'] );
        } elseif ( isset( $existing['disabled_languages'] ) && is_array( $existing['disabled_languages'] ) ) {
            $output['disabled_languages'] = array_map( 'sanitize_text_field', $existing['disabled_languages'] );
        } else {
            $output['disabled_languages'] = array();
        }

        // 白名单校验：languages/ 下只有 zh_CN 一份语言包，en_US 为源语言
        $allowed_languages = array( 'zh_CN', 'en_US' );
        $plugin_language = isset( $input['plugin_language'] ) ? sanitize_text_field( $input['plugin_language'] ) : 'zh_CN';
        $output['plugin_language'] = in_array( $plugin_language, $allowed_languages, true ) ? $plugin_language : 'zh_CN';

        return $output;
    }

    public static function default_system_prompt() {
        return "You are a professional website localization translator. Your task is to translate WordPress website content into the target language for use with the TranslatePress plugin.\n\nTranslation requirements:\n1. Be accurate, natural, and follow the web content expression habits of the target language.\n2. Keep all <protect-N> placeholders exactly as they are. These placeholders represent HTML tags, shortcodes, variables, or URLs.\n3. Do not translate HTML tags, WordPress shortcodes (e.g., [shortcode]), format placeholders (e.g., %s), variable interpolations (e.g., {{var}}), and URLs.\n4. Maintain consistent terminology, tone, and style within the same page.\n5. Brand names, product names, and proper nouns can be kept in the original language, or translated uniformly using common translations.\n6. Button copy and navigation text should be concise and match the click habits of users in the target language.\n7. Return ONLY a JSON array in the same order as the input, with no extra explanations or markdown formatting.";
    }

    public function render_settings_page() {
        $settings = get_option( 'opentranslation_settings', array() );
        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_models_page() {
        $this->handle_models_actions();
        settings_errors( 'opentranslation_models' );
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-models.php';
    }

    public function render_queue_page() {
        $counts = Cache::get_counts();
        $logs = Log::get_recent( 50 );
        $languages = TP_Storage_Adapter::get_target_languages();
        $settings = get_option( 'opentranslation_settings', array() );
        $disabled = isset( $settings['disabled_languages'] ) ? $settings['disabled_languages'] : array();
        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-queue.php';
    }

    private function handle_models_actions() {
        if ( ! isset( $_POST['opentranslation_models_nonce'] ) ) {
            return;
        }
        // nonce 只防 CSRF，不防越权：能力检查必须独立存在
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentranslation_models_nonce'] ) ), 'opentranslation_models_action' ) ) {
            return;
        }

        $models = Encrypted_Options::get( 'opentranslation_models', array() );

        if ( isset( $_POST['add_model'] ) ) {
            $base_url = esc_url_raw( wp_unslash( $_POST['base_url'] ?? '' ) );

            // 留空表示使用官方地址（由 Client 构造器回落），无需校验
            if ( '' !== $base_url ) {
                $reason = URL_Guard::get_rejection_reason( $base_url );
                if ( '' !== $reason ) {
                    add_settings_error( 'opentranslation_models', 'invalid_base_url', $reason, 'error' );
                    return;
                }
            }

            $models[] = array(
                'provider'    => sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) ),
                // API Key 用 trim 而非 sanitize_text_field，理由同上（S8）
                'api_key'     => isset( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : '',
                'base_url'    => $base_url,
                'model'       => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4o',
                'priority'    => isset( $_POST['priority'] ) ? absint( $_POST['priority'] ) : 10,
                'temperature' => isset( $_POST['temperature'] ) ? (float) $_POST['temperature'] : 0.3,
                'max_tokens'  => isset( $_POST['max_tokens'] ) ? absint( $_POST['max_tokens'] ) : 0,
            );
            Encrypted_Options::set( 'opentranslation_models', $models );
        }

        if ( isset( $_POST['delete_model'] ) && isset( $_POST['model_index'] ) ) {
            $index = absint( $_POST['model_index'] );
            if ( isset( $models[ $index ] ) ) {
                array_splice( $models, $index, 1 );
                Encrypted_Options::set( 'opentranslation_models', $models );
            }
        }
    }

    public function handle_run_queue() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_run_queue' );
        Scheduler::trigger_manual();
        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-queue&message=queued' ) );
        exit;
    }

    public function handle_retry_failed() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_retry_failed' );
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_cache';
        $wpdb->query( "UPDATE {$table} SET status = 'pending', retry_count = 0, next_retry_at = NULL WHERE status = 'failed'" );
        Scheduler::trigger_manual();
        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-queue&message=retried' ) );
        exit;
    }

    public function handle_toggle_language() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'opentranslation' ) );
        }
        check_admin_referer( 'opentranslation_toggle_language' );
        $lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
        $settings = get_option( 'opentranslation_settings', array() );
        $disabled = isset( $settings['disabled_languages'] ) ? $settings['disabled_languages'] : array();
        if ( in_array( $lang, $disabled, true ) ) {
            $disabled = array_diff( $disabled, array( $lang ) );
        } else {
            $disabled[] = $lang;
        }
        $settings['disabled_languages'] = array_values( $disabled );
        update_option( 'opentranslation_settings', $settings );
        wp_safe_redirect( admin_url( 'admin.php?page=opentranslation-queue&message=toggled' ) );
        exit;
    }
}
