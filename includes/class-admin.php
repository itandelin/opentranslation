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
        add_action( 'wp_ajax_opentranslation_fetch_models', array( $this, 'ajax_fetch_models' ) );
        add_action( 'wp_ajax_opentranslation_test_model', array( $this, 'ajax_test_model' ) );
        add_action( 'admin_post_opentranslation_run_queue', array( $this, 'handle_run_queue' ) );
        add_action( 'admin_post_opentranslation_retry_failed', array( $this, 'handle_retry_failed' ) );
        add_action( 'admin_post_opentranslation_toggle_language', array( $this, 'handle_toggle_language' ) );
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
            $models[] = array(
                'provider'    => sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) ),
                'api_key'     => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
                'base_url'    => esc_url_raw( wp_unslash( $_POST['base_url'] ?? '' ) ),
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

    public function ajax_fetch_models() {
        check_ajax_referer( 'opentranslation_fetch_models_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'opentranslation' ) );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'openai';
        $api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( __( 'Please enter an API Key.', 'opentranslation' ) );
        }

        if ( empty( $base_url ) ) {
            $base_url = Translator::default_base_url( $provider );
        }

        $base_url = trailingslashit( $base_url );
        $endpoint = $base_url . 'models';

        $headers = array( 'Content-Type' => 'application/json' );
        if ( 'claude' === $provider ) {
            $headers['x-api-key']         = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $urls_to_try = array( $endpoint );
        if ( substr( $base_url, -4 ) === '/v1/' ) {
            $urls_to_try[] = $base_url . 'models';
        } else {
            $urls_to_try[] = $base_url . 'v1/models';
        }
        $urls_to_try = array_unique( $urls_to_try );

        $response = null;
        $last_error = '';
        foreach ( $urls_to_try as $try_url ) {
            $response = wp_remote_get( $try_url, array(
                'headers' => $headers,
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $status = wp_remote_retrieve_response_code( $response );
            if ( 200 === $status ) {
                $endpoint = $try_url;
                break;
            }

            $last_error = 'HTTP ' . $status;
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $last_error );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            wp_send_json_error( 'HTTP ' . $status );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        $models = array();
        if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
            foreach ( $data['data'] as $item ) {
                if ( ! empty( $item['id'] ) ) {
                    $models[] = $item['id'];
                }
            }
        }

        if ( empty( $models ) ) {
            wp_send_json_error( __( 'No models found.', 'opentranslation' ) );
        }

        sort( $models );
        wp_send_json_success( $models );
    }

    public function ajax_test_model() {
        check_ajax_referer( 'opentranslation_test_model_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'opentranslation' ) );
        }

        $index = isset( $_POST['model_index'] ) ? absint( $_POST['model_index'] ) : -1;
        $models = Encrypted_Options::get( 'opentranslation_models', array() );

        if ( ! isset( $models[ $index ] ) ) {
            wp_send_json_error( __( 'Model not found.', 'opentranslation' ) );
        }

        $config = $models[ $index ];
        $provider = isset( $config['provider'] ) ? $config['provider'] : 'openai';
        $api_key  = isset( $config['api_key'] ) ? $config['api_key'] : '';
        $base_url = isset( $config['base_url'] ) ? $config['base_url'] : '';
        $model    = isset( $config['model'] ) ? $config['model'] : '';

        if ( empty( $api_key ) || empty( $model ) ) {
            wp_send_json_error( __( 'API Key or Model is empty.', 'opentranslation' ) );
        }

        if ( empty( $base_url ) ) {
            $base_url = Translator::default_base_url( $provider );
        }

        $base_url = trailingslashit( $base_url );

        $endpoint = 'claude' === $provider ? 'messages' : 'chat/completions';
        $urls_to_try = array( $base_url . $endpoint );
        $resolved_base_url = $base_url;
        if ( substr( $base_url, -4 ) !== '/v1/' ) {
            $urls_to_try[] = $base_url . 'v1/' . $endpoint;
        }

        $body = array(
            'model'    => $model,
            'messages' => array( array( 'role' => 'user', 'content' => 'hello' ) ),
        );

        $headers = array( 'Content-Type' => 'application/json' );
        if ( 'claude' === $provider ) {
            $headers['x-api-key']         = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
            $body['max_tokens'] = 10;
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $response = null;
        $last_error = '';
        foreach ( $urls_to_try as $try_url ) {
            $response = wp_remote_post( $try_url, array(
                'headers' => $headers,
                'body'    => wp_json_encode( $body ),
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $status = wp_remote_retrieve_response_code( $response );
            if ( 200 === $status ) {
                if ( substr( $try_url, -strlen( $endpoint ) ) === $endpoint ) {
                    $candidate = substr( $try_url, 0, -strlen( $endpoint ) );
                    if ( false !== $candidate ) {
                        $resolved_base_url = $candidate;
                    }
                }
                break;
            }

            $last_error = 'HTTP ' . $status;
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $last_error );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            $body_data = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = isset( $body_data['error']['message'] ) ? $body_data['error']['message'] : 'HTTP ' . $status;
            wp_send_json_error( $message );
        }

        if ( $resolved_base_url !== $base_url ) {
            $models[ $index ]['base_url'] = $resolved_base_url;
            Encrypted_Options::set( 'opentranslation_models', $models );
        }

        wp_send_json_success( __( 'Connection successful.', 'opentranslation' ) );
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
