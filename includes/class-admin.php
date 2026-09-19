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
                'edit_model'   => __( 'Edit Model', 'opentranslation' ),
                'add_model'    => __( 'Add Model', 'opentranslation' ),
                'edit_term'    => __( 'Edit Term', 'opentranslation' ),
                'add_term'     => __( 'Add Term', 'opentranslation' ),
                'insecure_transport' => __( '当前后台不是 HTTPS，API Key 将以明文经网络传输。建议为后台启用 HTTPS。', 'opentranslation' ),
            ),
            'test_nonce' => wp_create_nonce( 'opentranslation_test_model_nonce' ),
        ) );
    }

    public function register_menus() {
        add_menu_page( __( 'OpenTranslation', 'opentranslation' ), __( 'OpenTranslation', 'opentranslation' ), 'manage_options', 'opentranslation', array( $this, 'render_settings_page' ), 'dashicons-translation', 80 );
        add_submenu_page( 'opentranslation', __( 'Settings', 'opentranslation' ), __( 'Settings', 'opentranslation' ), 'manage_options', 'opentranslation', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'opentranslation', __( 'Models', 'opentranslation' ), __( 'Models', 'opentranslation' ), 'manage_options', 'opentranslation-models', array( $this, 'render_models_page' ) );
        add_submenu_page( 'opentranslation', __( 'Queue & Logs', 'opentranslation' ), __( 'Queue & Logs', 'opentranslation' ), 'manage_options', 'opentranslation-queue', array( $this, 'render_queue_page' ) );
        add_submenu_page( 'opentranslation', __( 'Failures', 'opentranslation' ), __( 'Failures', 'opentranslation' ), 'manage_options', 'opentranslation-failures', array( $this, 'render_failures_page' ) );
    }

    public function register_settings() {
        register_setting( 'opentranslation_settings', 'opentranslation_settings', array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $existing = get_option( 'opentranslation_settings', array() );

        $output = self::sanitize_numeric_settings( $input );

        // 暂停语言由 Queue 页维护，Settings 表单不含该字段。
        // 若不保留原值，保存一次设置就会让所有已暂停语言恢复翻译，直接影响 API 账单。
        if ( isset( $input['disabled_languages'] ) && is_array( $input['disabled_languages'] ) ) {
            $output['disabled_languages'] = array_map( 'sanitize_text_field', $input['disabled_languages'] );
        } elseif ( isset( $existing['disabled_languages'] ) && is_array( $existing['disabled_languages'] ) ) {
            $output['disabled_languages'] = array_map( 'sanitize_text_field', $existing['disabled_languages'] );
        } else {
            $output['disabled_languages'] = array();
        }

        // 本方法从空数组重建，未列出的键会被静默丢弃。
        // 单价由 Usage 页维护，Settings 表单不含该字段，必须保留原值。
        $output['model_pricing'] = isset( $existing['model_pricing'] ) && is_array( $existing['model_pricing'] ) ? $existing['model_pricing'] : array();

        // 白名单校验：languages/ 下只有 zh_CN 一份语言包，en_US 为源语言
        $allowed_languages = array( 'zh_CN', 'en_US' );
        $plugin_language = isset( $input['plugin_language'] ) ? sanitize_text_field( $input['plugin_language'] ) : 'zh_CN';
        $output['plugin_language'] = in_array( $plugin_language, $allowed_languages, true ) ? $plugin_language : 'zh_CN';

        // 翻译范围：委托 Scope::sanitize_settings 规范化 + 告警
        $languages = TP_Storage_Adapter::get_target_languages();
        // Settings 表单含 scope 字段则用表单值，否则保留原值（与 model_pricing 同类陷阱）
        $raw_scope = isset( $input['scope'] ) && is_array( $input['scope'] ) ? $input['scope'] : array();
        if ( empty( $raw_scope ) && isset( $existing['scope'] ) && is_array( $existing['scope'] ) ) {
            $raw_scope = $existing['scope'];
        }
        $output['scope'] = Scope::sanitize_settings( $raw_scope, $languages );

        return $output;
    }

    /**
     * 数值与提示词字段的规范化，抽出供配置导入复用（P2-5）。
     *
     * 只做 absint / sanitize_textarea_field，不额外夹取上下限——与抽取前完全一致。
     * 范围限制在使用处完成（Scheduler::run_rounds、Plugin::add_cron_interval）。
     *
     * @param array $input 原始输入
     * @return array 只含 batch_size / cron_interval / rate_limit_per_minute / system_prompt
     */
    public static function sanitize_numeric_settings( $input ) {
        $input = is_array( $input ) ? $input : array();

        return array(
            'batch_size'            => isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : 10,
            'cron_interval'         => isset( $input['cron_interval'] ) ? absint( $input['cron_interval'] ) : 5,
            'rate_limit_per_minute' => isset( $input['rate_limit_per_minute'] ) ? absint( $input['rate_limit_per_minute'] ) : 20,
            'system_prompt'         => isset( $input['system_prompt'] )
                ? sanitize_textarea_field( $input['system_prompt'] )
                : self::default_system_prompt(),
        );
    }

    public static function default_system_prompt() {
        return "You are a professional website localization translator. Your task is to translate WordPress website content into the target language for use with the TranslatePress plugin.\n\nTranslation requirements:\n1. Be accurate, natural, and follow the web content expression habits of the target language.\n2. Keep all <protect-N> placeholders exactly as they are. These placeholders represent HTML tags, shortcodes, variables, or URLs.\n3. Do not translate HTML tags, WordPress shortcodes (e.g., [shortcode]), format placeholders (e.g., %s), variable interpolations (e.g., {{var}}), and URLs.\n4. Maintain consistent terminology, tone, and style within the same page.\n5. Brand names, product names, and proper nouns can be kept in the original language, or translated uniformly using common translations.\n6. Button copy and navigation text should be concise and match the click habits of users in the target language.\n7. Return ONLY a JSON array in the same order as the input, with no extra explanations or markdown formatting.";
    }

    public function render_settings_page() {
        $settings = get_option( 'opentranslation_settings', array() );
        $languages = TP_Storage_Adapter::get_target_languages();
        $scope = isset( $settings['scope'] ) && is_array( $settings['scope'] ) ? $settings['scope'] : array();
        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_models_page() {
        $this->handle_models_actions();
        settings_errors( 'opentranslation_models' );
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        $health = new Model_Health();
        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-models.php';
    }

    public function render_queue_page() {
        $counts         = Cache::get_counts();
        $counts_by_lang = Cache::get_counts_by_language();
        $languages      = TP_Storage_Adapter::get_target_languages();
        $settings       = get_option( 'opentranslation_settings', array() );
        $disabled       = isset( $settings['disabled_languages'] ) ? $settings['disabled_languages'] : array();

        $log_action   = isset( $_GET['log_action'] ) ? sanitize_text_field( wp_unslash( $_GET['log_action'] ) ) : '';
        $log_page     = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;
        $log_per_page = 50;

        // 白名单净化：不在已知动作列表内一律置空，见 Log::sanitize_action
        $log_action = Log::sanitize_action( $log_action );

        $logs      = Log::get_recent( $log_per_page, ( $log_page - 1 ) * $log_per_page, $log_action );
        $log_total = Log::count_all( $log_action );

        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-queue.php';
    }

    public function render_failures_page() {
        $language = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';

        // 白名单净化：不在目标语言列表内一律置空，见 TP_Storage_Adapter::sanitize_language
        $language = TP_Storage_Adapter::sanitize_language( $language );

        $page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;

        $items = Cache::get_failed_items( $language, $per_page, ( $page - 1 ) * $per_page );
        $total = Cache::count_failed_items( $language );

        require OPENTRANSLATION_PLUGIN_DIR . 'templates/admin-failures.php';
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
        $index  = isset( $_POST['model_index'] ) ? absint( $_POST['model_index'] ) : -1;

        if ( isset( $_POST['add_model'] ) ) {
            $this->add_model( $models );
        } elseif ( isset( $_POST['update_model'] ) ) {
            $this->update_model( $models, $index );
        } elseif ( isset( $_POST['delete_model'] ) && isset( $models[ $index ] ) ) {
            array_splice( $models, $index, 1 );
            Encrypted_Options::set( 'opentranslation_models', $models );
        }
    }

    /**
     * 从表单读取模型字段（不含 api_key）。
     *
     * Base URL 非法时已写入 settings_error，返回 null。
     * 留空表示使用官方地址（由 Client 构造器回落），无需校验。
     *
     * @return array|null
     */
    private function read_model_fields() {
        $base_url = esc_url_raw( wp_unslash( $_POST['base_url'] ?? '' ) );

        if ( '' !== $base_url ) {
            $reason = URL_Guard::get_rejection_reason( $base_url );
            if ( '' !== $reason ) {
                add_settings_error( 'opentranslation_models', 'invalid_base_url', $reason, 'error' );
                return null;
            }
        }

        return array(
            'provider'    => sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) ),
            'base_url'    => $base_url,
            'model'       => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4o',
            'priority'    => isset( $_POST['priority'] ) ? absint( $_POST['priority'] ) : 10,
            'temperature' => self::clamp_temperature( $_POST['temperature'] ?? 0.3 ),
            'max_tokens'  => isset( $_POST['max_tokens'] ) ? absint( $_POST['max_tokens'] ) : 0,
        );
    }

    /**
     * API Key 用 trim 而非 sanitize_text_field：
     * 后者会剥离特殊字符，可能静默损坏部分网关的自定义格式密钥（S8）。
     */
    private static function posted_api_key() {
        return isset( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : '';
    }

    /**
     * temperature 夹取到 0-2。
     *
     * OpenAI 与 Claude 均要求该范围，超界会被上游拒绝。
     */
    public static function clamp_temperature( $raw ) {
        return max( 0.0, min( 2.0, (float) $raw ) );
    }

    private function add_model( array $models ) {
        $fields = $this->read_model_fields();
        if ( null === $fields ) {
            return;
        }

        $fields['api_key'] = self::posted_api_key();
        $models[]          = $fields;
        Encrypted_Options::set( 'opentranslation_models', $models );
    }

    private function update_model( array $models, $index ) {
        if ( ! isset( $models[ $index ] ) ) {
            return;
        }

        $fields = $this->read_model_fields();
        if ( null === $fields ) {
            return;
        }

        // API Key 留空表示保持原值，避免为改其他字段而重新输入密钥
        $new_key = self::posted_api_key();
        if ( '' !== $new_key ) {
            $fields['api_key'] = $new_key;
        }

        $models[ $index ] = array_merge( $models[ $index ], $fields );
        Encrypted_Options::set( 'opentranslation_models', $models );
        add_settings_error( 'opentranslation_models', 'model_updated', __( '模型已更新。', 'opentranslation' ), 'success' );
    }
}
