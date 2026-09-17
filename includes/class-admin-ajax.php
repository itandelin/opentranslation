<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 后台 AJAX 处理。
 *
 * 从 Admin 拆出以满足单文件 300 行上限。
 */
class Admin_Ajax {

    const TIMEOUT = 15;

    public function __construct() {
        add_action( 'wp_ajax_opentranslation_fetch_models', array( $this, 'fetch_models' ) );
        add_action( 'wp_ajax_opentranslation_test_model', array( $this, 'test_model' ) );
    }

    public function fetch_models() {
        check_ajax_referer( 'opentranslation_fetch_models_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'opentranslation' ) );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'openai';
        // API Key 用 trim 而非 sanitize_text_field：后者会剥离特殊字符，
        // 可能静默损坏部分网关的自定义格式密钥（S8）
        $api_key  = isset( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : '';
        $base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';

        if ( '' === $api_key ) {
            wp_send_json_error( __( 'Please enter an API Key.', 'opentranslation' ) );
        }

        if ( '' === $base_url ) {
            $base_url = Translator::default_base_url( $provider );
        }

        // 保存时已校验，但 AJAX 可被单独调用，必须独立校验
        $reason = URL_Guard::get_rejection_reason( $base_url );
        if ( '' !== $reason ) {
            wp_send_json_error( $reason );
        }

        $models = $this->request_model_list( $provider, $api_key, trailingslashit( $base_url ) );

        if ( is_wp_error( $models ) ) {
            wp_send_json_error( $models->get_error_message() );
        }

        wp_send_json_success( $models );
    }

    /**
     * 拉取模型列表。
     *
     * @param string $provider openai|claude
     * @param string $api_key  明文密钥
     * @param string $base_url 已带尾斜杠
     * @return array|\WP_Error
     */
    private function request_model_list( $provider, $api_key, $base_url ) {
        $headers = $this->auth_headers( $provider, $api_key );

        $urls_to_try = array( $base_url . 'models' );
        if ( '/v1/' !== substr( $base_url, -4 ) ) {
            $urls_to_try[] = $base_url . 'v1/models';
        }

        $last_error = __( 'No models found.', 'opentranslation' );

        foreach ( array_unique( $urls_to_try ) as $try_url ) {
            $response = wp_remote_get( $try_url, URL_Guard::harden_request_args( array(
                'headers' => $headers,
                'timeout' => self::TIMEOUT,
            ) ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $status ) {
                $last_error = 'HTTP ' . $status;
                continue;
            }

            $models = $this->extract_model_ids( wp_remote_retrieve_body( $response ) );
            if ( ! empty( $models ) ) {
                return $models;
            }
        }

        return new \WP_Error( 'fetch_failed', $last_error );
    }

    /**
     * 从响应体提取模型 ID 列表。
     *
     * @param string $raw_body 上游原始响应体
     * @return array
     */
    private function extract_model_ids( $raw_body ) {
        $data   = json_decode( $raw_body, true );
        $models = array();

        if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
            foreach ( $data['data'] as $item ) {
                if ( ! empty( $item['id'] ) ) {
                    $models[] = (string) $item['id'];
                }
            }
        }

        sort( $models );
        return $models;
    }

    /**
     * 按 provider 组装鉴权请求头。
     *
     * @param string $provider openai|claude
     * @param string $api_key  明文密钥
     * @return array
     */
    private function auth_headers( $provider, $api_key ) {
        $headers = array( 'Content-Type' => 'application/json' );
        if ( 'claude' === $provider ) {
            $headers['x-api-key']         = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }
        return $headers;
    }

    public function test_model() {
        check_ajax_referer( 'opentranslation_test_model_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'opentranslation' ) );
        }

        $index  = isset( $_POST['model_index'] ) ? absint( $_POST['model_index'] ) : -1;
        $models = Encrypted_Options::get( 'opentranslation_models', array() );

        if ( ! isset( $models[ $index ] ) ) {
            wp_send_json_error( __( 'Model not found.', 'opentranslation' ) );
        }

        $config = $models[ $index ];

        if ( empty( $config['api_key'] ) || empty( $config['model'] ) ) {
            wp_send_json_error( __( 'API Key or Model is empty.', 'opentranslation' ) );
        }

        $result = $this->probe_model( $config );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'Connection successful.', 'opentranslation' ) );
    }

    /**
     * 发一次最小请求验证连通性。
     *
     * 不再隐式改写已保存的 base_url（U2）：
     * 测试是只读操作，不应产生持久化副作用。
     *
     * @param array $config 单个模型配置
     * @return true|\WP_Error
     */
    private function probe_model( $config ) {
        $provider = isset( $config['provider'] ) ? $config['provider'] : 'openai';
        $base_url = isset( $config['base_url'] ) ? trim( (string) $config['base_url'] ) : '';

        if ( '' === $base_url ) {
            $base_url = Translator::default_base_url( $provider );
        }

        // AJAX 可被单独调用，必须独立校验
        $reason = URL_Guard::get_rejection_reason( $base_url );
        if ( '' !== $reason ) {
            return new \WP_Error( 'invalid_base_url', $reason );
        }

        $base_url = trailingslashit( $base_url );

        $is_claude = 'claude' === $provider;
        $endpoint  = $is_claude ? 'messages' : 'chat/completions';

        $body = array(
            'model'    => $config['model'],
            'messages' => array( array( 'role' => 'user', 'content' => 'hello' ) ),
        );
        if ( $is_claude ) {
            $body['max_tokens'] = 10;
        }

        $response = wp_remote_post( $base_url . $endpoint, URL_Guard::harden_request_args( array(
            'headers' => $this->auth_headers( $provider, $config['api_key'] ),
            'body'    => wp_json_encode( $body ),
            'timeout' => self::TIMEOUT,
        ) ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 === $status ) {
            return true;
        }

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $status;

        return new \WP_Error( 'test_failed', $message );
    }
}
