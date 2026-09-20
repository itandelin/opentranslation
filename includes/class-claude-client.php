<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Claude Messages API 客户端。
 *
 * 重试、失败切块、请求单位统计与 OpenAI_Client 对等。
 * 两个客户端约有 120 行相似的重试/切块逻辑，暂不抽公共基类：
 * 两者的请求体、响应体、错误结构都不同，强行抽象的耦合比重复更差，
 * 留待出现第三个 Provider 时再评估。
 */
class Claude_Client implements Model_Client {
    private $api_key;
    private $base_url;
    private $model;
    private $temperature;
    private $max_tokens;
    private $timeout = 30;
    private $source_lang = '';
    private $last_request_units = 0;
    private $last_usage = array( 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 );

    public function __construct( $config ) {
        $this->api_key = $config['api_key'];
        $base_url      = isset( $config['base_url'] ) ? trim( (string) $config['base_url'] ) : '';
        if ( '' === $base_url ) {
            $base_url = Translator::default_base_url( 'claude' );
        }
        $this->base_url = trailingslashit( $base_url );
        $this->model = $config['model'];
        $this->temperature = isset( $config['temperature'] ) ? (float) $config['temperature'] : 0.3;
        // Claude Messages API 要求 max_tokens >= 1，界面填 0 表示「自动」需回落到默认值
        $this->max_tokens = ! empty( $config['max_tokens'] ) ? (int) $config['max_tokens'] : 4096;
        $timeout = isset( $config['timeout'] ) ? (int) $config['timeout'] : 0;
        $this->timeout = $timeout > 0 ? $timeout : (int) apply_filters( 'opentranslation_request_timeout', 30 );
    }

    public function translate( $items, $target_lang, $system_prompt = '', $source_lang = '' ) {
        $this->last_request_units = 0;
        $this->last_usage = array( 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 );
        $this->source_lang = (string) $source_lang;
        return $this->translate_resilient( $items, $target_lang, $system_prompt );
    }

    public function get_last_request_units() {
        return max( 0, (int) $this->last_request_units );
    }

    public function get_last_usage() {
        return $this->last_usage;
    }

    /**
     * 累加一次成功响应的 usage。Messages API 的字段是 input_tokens / output_tokens，
     * 归一化为 prompt / completion 与 OpenAI 对齐。
     */
    private function add_usage( $data ) {
        $u = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
        $p = isset( $u['input_tokens'] ) ? (int) $u['input_tokens'] : 0;
        $c = isset( $u['output_tokens'] ) ? (int) $u['output_tokens'] : 0;
        $this->last_usage['prompt_tokens']     += $p;
        $this->last_usage['completion_tokens'] += $c;
        $this->last_usage['total_tokens']      += $p + $c;
    }

    public function test_connection( $items, $target_lang, $system_prompt = '' ) {
        $request    = $this->request_with_retries( $items, $target_lang, $system_prompt );
        $response   = $request['response'];
        $diagnostic = $this->base_diagnostic( $items, $target_lang, $system_prompt, $request );

        if ( is_wp_error( $response ) ) {
            $diagnostic['success'] = false;
            $diagnostic['error']   = $response->get_error_message();
            return $diagnostic;
        }

        $raw_body = $request['raw_body'];
        $data     = json_decode( $raw_body, true );

        $diagnostic['http_code']             = wp_remote_retrieve_response_code( $response );
        $diagnostic['http_message']          = wp_remote_retrieve_response_message( $response );
        $diagnostic['upstream_body_preview'] = $this->limit_preview( $raw_body );
        $diagnostic['upstream_model']        = isset( $data['model'] ) ? $data['model'] : '';
        $diagnostic['usage']                 = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

        // 与 translate() 同一套判定：非 2xx / 空内容 / 解析失败都归为失败，不再把 401 误报成空响应
        $parsed = $this->extract_error( $request );
        if ( null === $parsed ) {
            $parsed = $this->parse_response( $data['content'][0]['text'] );
        }

        if ( is_wp_error( $parsed ) ) {
            $diagnostic['success'] = false;
            $diagnostic['error']   = $parsed->get_error_message();
            return $diagnostic;
        }

        $diagnostic['success']            = true;
        $diagnostic['translated_preview'] = isset( $parsed[0] ) ? $parsed[0] : '';
        $diagnostic['translations_count'] = count( $parsed );

        return $diagnostic;
    }

    /**
     * 诊断信息的固定部分（与响应无关的请求参数）。
     */
    private function base_diagnostic( $items, $target_lang, $system_prompt, $request ) {
        return array(
            'provider'              => 'claude',
            'base_url'              => $this->base_url,
            'endpoint'              => $request['url'],
            'model'                 => $this->model,
            'target_language'       => $target_lang,
            'items_count'           => count( $items ),
            'sample_input'          => isset( $items[0] ) ? (string) $items[0] : '',
            'temperature'           => $this->temperature,
            'max_tokens'            => $this->max_tokens,
            'transport'             => 'wp_remote_post',
            'system_prompt_enabled' => ! empty( $system_prompt ),
            'request_attempts'      => isset( $request['attempts'] ) ? $request['attempts'] : array(),
        );
    }

    private function build_prompt( $items, $target_lang ) {
        $lines = array();
        foreach ( $items as $index => $text ) {
            $lines[] = ( $index + 1 ) . '. ' . $text;
        }
        $source = '' !== $this->source_lang ? " from {$this->source_lang}" : '';
        $prompt  = "Translate the following numbered texts{$source} to {$target_lang}.\n\n";
        $prompt .= "Rules:\n";
        $prompt .= "- Preserve all <protect-N> placeholders exactly as they appear.\n";
        $prompt .= "- Do not translate HTML tags, shortcodes, variables, or URLs.\n";
        $prompt .= "- Return ONLY a JSON object mapping each input number to its translation,\n";
        $prompt .= "  for example {\"1\":\"...\",\"2\":\"...\"}. Every input number must appear exactly once.\n";
        $prompt .= "- No extra explanations, no markdown formatting.\n\n";
        $prompt .= "Texts:\n" . implode( "\n", $lines );
        return $prompt;
    }

    private function dispatch_request( $items, $target_lang, $system_prompt = '' ) {
        $prompt = $this->build_prompt( $items, $target_lang );
        $url = $this->base_url . 'messages';

        $body = array(
            'model'       => $this->model,
            'max_tokens'  => $this->max_tokens,
            'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
            'temperature' => $this->temperature,
        );
        if ( ! empty( $system_prompt ) ) {
            $body['system'] = $system_prompt;
        }

        $request_args = URL_Guard::harden_request_args( array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => $this->timeout,
        ) );

        return array(
            'url'      => $url,
            'response' => wp_remote_post( $url, $request_args ),
        );
    }

    /**
     * 带重试的请求。
     *
     * @return array{url:string,response:mixed,raw_body:string,attempts:array,request_units:int}
     */
    private function request_with_retries( $items, $target_lang, $system_prompt = '' ) {
        $attempts     = array();
        $max_attempts = (int) apply_filters( 'opentranslation_claude_max_attempts', 3, $this->model, $this->base_url );
        $max_attempts = max( 1, min( 5, $max_attempts ) );

        $base_delay_ms = (int) apply_filters( 'opentranslation_claude_retry_delay_ms', 800, $this->model, $this->base_url );
        $base_delay_ms = max( 100, min( 3000, $base_delay_ms ) );

        $last_request = array(
            'url'      => '',
            'response' => new \WP_Error( 'claude_request_failed', __( 'Anthropic request failed before dispatch.', 'opentranslation' ) ),
            'raw_body' => '',
            'attempts' => array(),
        );

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $request  = $this->dispatch_request( $items, $target_lang, $system_prompt );
            $response = $request['response'];
            $raw_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
            $status   = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );

            $attempts[] = array(
                'attempt'   => $attempt,
                'http_code' => $status,
                'body_len'  => strlen( $raw_body ),
                'wp_error'  => is_wp_error( $response ) ? $response->get_error_message() : '',
            );

            $last_request = array(
                'url'      => $request['url'],
                'response' => $response,
                'raw_body' => $raw_body,
                'attempts' => $attempts,
            );

            if ( ! $this->should_retry_request( $response, $status, $raw_body, $attempt, $max_attempts ) ) {
                break;
            }

            // 上游明确给了 Retry-After 就听它的，否则线性退避
            $delay_ms    = $base_delay_ms * $attempt;
            $retry_after = $this->retry_after_ms( $response );
            if ( $retry_after > 0 ) {
                $delay_ms = min( $retry_after, 30000 );
            }
            usleep( $delay_ms * 1000 );
        }

        $last_request['request_units'] = count( $attempts );
        return $last_request;
    }

    private function should_retry_request( $response, $status_code, $raw_body, $attempt, $max_attempts ) {
        if ( $attempt >= $max_attempts ) {
            return false;
        }
        if ( is_wp_error( $response ) ) {
            return true;
        }
        if ( in_array( (int) $status_code, self::retryable_status_codes(), true ) ) {
            return true;
        }
        return $status_code >= 200 && $status_code < 300 && '' === trim( (string) $raw_body );
    }

    /**
    /**
     * 上游 429 / 503 给出的 Retry-After（秒数或 HTTP 日期）转成毫秒。
     */
    private function retry_after_ms( $response ) {
        if ( is_wp_error( $response ) ) {
            return 0;
        }

        $header = wp_remote_retrieve_header( $response, 'retry-after' );
        if ( is_array( $header ) ) {
            $header = reset( $header );
        }
        if ( '' === $header || null === $header ) {
            return 0;
        }
        if ( is_numeric( $header ) ) {
            return (int) ( (float) $header * 1000 );
        }

        $timestamp = strtotime( $header );

        return $timestamp ? max( 0, ( $timestamp - time() ) * 1000 ) : 0;
    }

    /**
     * 可重试的 HTTP 状态码。与 OpenAI 客户端保持一致。
     */
    private static function retryable_status_codes() {
        return array( 408, 409, 429, 500, 502, 503, 504, 520, 522, 524 );
    }

    private function translate_resilient( $items, $target_lang, $system_prompt = '', $depth = 0 ) {
        $request = $this->request_with_retries( $items, $target_lang, $system_prompt );

        $this->last_request_units += isset( $request['request_units'] ) ? (int) $request['request_units'] : 0;

        $error = $this->extract_error( $request );
        if ( null !== $error ) {
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, $error, $depth );
        }

        $data = json_decode( $request['raw_body'], true );
        $this->add_usage( $data );
        $parsed = $this->parse_response( $data['content'][0]['text'], count( $items ) );

        if ( is_wp_error( $parsed ) ) {
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, $parsed, $depth );
        }

        if ( count( $parsed ) !== count( $items ) ) {
            return $this->maybe_split_request(
                $items,
                $target_lang,
                $system_prompt,
                new \WP_Error( 'count_mismatch', __( 'Model response count does not match request count.', 'opentranslation' ) ),
                $depth
            );
        }

        return $parsed;
    }

    /**
     * 从请求结果提取错误；无错误时返回 null。
     *
     * @return \WP_Error|null
     */
    private function extract_error( $request ) {
        $response = $request['response'];

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            return $this->build_http_error( $status_code, $request['raw_body'] );
        }

        $data = json_decode( $request['raw_body'], true );
        if ( empty( $data['content'][0]['text'] ) ) {
            // 上游 body 常含请求头片段、账号 ID、key 前缀，仅在 WP_DEBUG 下附加
            $message = __( 'Anthropic returned empty content.', 'opentranslation' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $message .= ' Debug: ' . $this->limit_preview( $request['raw_body'], 500 );
            }
            return new \WP_Error( 'claude_empty', $message );
        }

        return null;
    }

    private function maybe_split_request( $items, $target_lang, $system_prompt, $error, $depth ) {
        if ( ! $this->should_split_request( $items, $error, $depth ) ) {
            return $error;
        }

        $translations = array();
        $chunk_size   = (int) ceil( count( $items ) / 2 );

        foreach ( array_chunk( $items, $chunk_size ) as $chunk ) {
            $result = $this->translate_resilient( $chunk, $target_lang, $system_prompt, $depth + 1 );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $translations = array_merge( $translations, $result );
        }

        return $translations;
    }

    private function should_split_request( $items, $error, $depth ) {
        if ( count( $items ) <= 1 ) {
            return false;
        }

        $max_depth = (int) apply_filters( 'opentranslation_claude_split_max_depth', 3, $this->model, $this->base_url );
        if ( $depth >= max( 1, min( 5, $max_depth ) ) ) {
            return false;
        }

        $code = $error instanceof \WP_Error ? $error->get_error_code() : '';

        if ( in_array( $code, array( 'claude_empty', 'parse_error', 'count_mismatch' ), true ) ) {
            return true;
        }

        if ( 'claude_http_error' !== $code ) {
            return false;
        }

        // 429 不切块：限流下拆成更多请求只会放大问题，
        // 正确处理是按 Retry-After 退避重试。
        $status = (int) $error->get_error_data( 'status_code' );

        return 429 !== $status && in_array( $status, self::retryable_status_codes(), true );
    }

    private function build_http_error( $status_code, $raw_body ) {
        $message = sprintf(
            /* translators: %d is the HTTP status code. */
            __( 'Anthropic endpoint returned HTTP %d.', 'opentranslation' ),
            (int) $status_code
        );

        $data     = json_decode( $raw_body, true );
        $upstream = isset( $data['error']['message'] ) ? $data['error']['message'] : '';

        if ( '' !== $upstream ) {
            // 上游结构化错误消息通常不含凭据，保留有助排查
            $message .= ' ' . $upstream;
        } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $preview  = $this->limit_preview( $raw_body, 500 );
            $message .= ' Debug: ' . ( '' === $preview ? '(empty body)' : $preview );
        }

        return new \WP_Error( 'claude_http_error', $message, array( 'status_code' => (int) $status_code ) );
    }

    /**
     * 解析模型返回的文本为字符串数组。
     *
     * @return array|\WP_Error
     */
    private function parse_response( $content, $expected = null ) {
        $content = trim( (string) $content );

        $decoded = $this->decode_payload( $content );
        if ( is_array( $decoded ) ) {
            $normalized = $this->normalize_decoded( $decoded, $expected );
            if ( null !== $normalized ) {
                return $normalized;
            }
        }

        // 兜底：逐行解析编号列表
        $results = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
            if ( preg_match( '/^\d+[\.\)\-:]\s*(.+)$/', trim( $line ), $matches ) ) {
                $results[] = trim( $matches[1] );
            }
        }

        if ( ! empty( $results ) ) {
            return $results;
        }

        $message = __( 'Failed to parse model response as JSON.', 'opentranslation' );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $message .= ' Debug: ' . $this->limit_preview( $content, 500 );
        }

        return new \WP_Error( 'parse_error', $message );
    }

    /**
     * 从模型输出里抠出 JSON 负载。
     *
     * 整体解析优先：旧实现先用贪婪的 /\[.*\]/ 去抓，会把正文里的方括号一并吞进来。
     */
    private function decode_payload( $content ) {
        $candidates = array( $content );

        if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $m ) ) {
            $candidates[] = $m[1];
        }
        if ( preg_match( '/```\s*(.*?)\s*```/s', $content, $m ) ) {
            $candidates[] = $m[1];
        }
        if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
            $candidates[] = $m[0];
        }
        if ( preg_match( '/\[.*\]/s', $content, $m ) ) {
            $candidates[] = $m[0];
        }

        foreach ( $candidates as $candidate ) {
            $decoded = json_decode( trim( $candidate ), true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 把解码结果归一成按输入顺序排列的数组。
     *
     * 编号对象的键必须恰好是 1..N。纯文本条目没有占位符，
     * 一旦顺序错乱后续校验发现不了，因此这里宁可判失败。
     */
    private function normalize_decoded( $decoded, $expected ) {
        if ( array() === $decoded ) {
            return array();
        }

        if ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
            return array_values( $decoded );
        }

        $count = ( null === $expected ) ? count( $decoded ) : (int) $expected;
        if ( $count < 1 ) {
            return null;
        }

        $keys   = array_map( 'strval', array_keys( $decoded ) );
        $wanted = array_map( 'strval', range( 1, $count ) );
        sort( $keys, SORT_STRING );
        sort( $wanted, SORT_STRING );

        if ( $keys !== $wanted ) {
            return null;
        }

        $ordered = array();
        for ( $i = 1; $i <= $count; $i++ ) {
            $ordered[] = $decoded[ $i ];
        }

        return $ordered;
    }

    private function limit_preview( $text, $limit = 1500 ) {
        // 上游 body 可能回显请求头、账号 ID、key 前缀，进诊断面板前必须脱敏
        $text = Log::redact( trim( (string) $text ) );
        if ( strlen( $text ) <= $limit ) {
            return $text;
        }

        return substr( $text, 0, $limit ) . '...';
    }
}
