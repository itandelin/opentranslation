<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OpenAI_Client implements Model_Client {
    private $api_key;
    private $base_url;
    private $model;
    private $temperature;
    private $max_tokens;
    private $timeout = 60;
    private $last_request_units = 0;
    public function __construct( $config ) {
        $this->api_key = $config['api_key'];
        $this->base_url = trailingslashit( $config['base_url'] );
        $this->model = $config['model'];
        $this->temperature = isset( $config['temperature'] ) ? (float) $config['temperature'] : 0.3;
        $this->max_tokens = isset( $config['max_tokens'] ) ? (int) $config['max_tokens'] : 0;
    }
    public function translate( $items, $target_lang, $system_prompt = '' ) {
        $this->last_request_units = 0;
        return $this->translate_resilient( $items, $target_lang, $system_prompt );
    }
    public function test_connection( $items, $target_lang, $system_prompt = '' ) {
        $request = $this->request_with_retries( $items, $target_lang, $system_prompt );
        $response = $request['response'];
        $diagnostic = array( 'provider' => 'openai', 'base_url' => $this->base_url, 'endpoint' => $request['url'], 'model' => $this->model, 'target_language' => $target_lang, 'items_count' => count( $items ), 'sample_input' => isset( $items[0] ) ? (string) $items[0] : '', 'temperature' => $this->temperature, 'max_tokens' => $this->max_tokens > 0 ? $this->max_tokens : null, 'transport' => 'wp_remote_post', 'system_prompt_enabled' => ! empty( $system_prompt ), 'request_attempts' => isset( $request['attempts'] ) ? $request['attempts'] : array() );
        if ( is_wp_error( $response ) ) {
            $diagnostic['success'] = false;
            $diagnostic['error'] = $response->get_error_message();
            return $diagnostic;
        }
        $raw_body = isset( $request['raw_body'] ) ? $request['raw_body'] : '';
        $status_code = wp_remote_retrieve_response_code( $response );
        $status_message = wp_remote_retrieve_response_message( $response );
        $data = json_decode( $raw_body, true );
        $parsed = null;
        $content = $this->extract_message_content( $data );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $parsed = $this->build_http_error( $status_code, $raw_body );
        } elseif ( '' === $content ) {
            $parsed = new \WP_Error( 'openai_empty', __( 'OpenAI-compatible endpoint returned empty content.', 'opentranslation' ) );
        } else {
            $parsed = $this->parse_response( $content, $raw_body );
        }
        $diagnostic['http_code'] = $status_code;
        $diagnostic['http_message'] = $status_message;
        $diagnostic['upstream_body_preview'] = $this->limit_preview( $raw_body );
        $diagnostic['upstream_model'] = isset( $data['model'] ) ? $data['model'] : '';
        $diagnostic['usage'] = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
        if ( is_wp_error( $parsed ) ) {
            $diagnostic['success'] = false;
            $diagnostic['error'] = $parsed->get_error_message();
            return $diagnostic;
        }
        $diagnostic['success'] = $status_code >= 200 && $status_code < 300;
        $diagnostic['translated_preview'] = isset( $parsed[0] ) ? $parsed[0] : '';
        $diagnostic['translations_count'] = count( $parsed );
        if ( ! $diagnostic['success'] ) {
            $diagnostic['error'] = sprintf( __( 'Upstream service returned HTTP %d.', 'opentranslation' ), $status_code );
        }
        return $diagnostic;
    }
    public function get_last_request_units() {
        return max( 0, (int) $this->last_request_units );
    }
    private function build_prompt( $items, $target_lang ) {
        $lines = array();
        foreach ( $items as $index => $text ) {
            $lines[] = ( $index + 1 ) . '. ' . $text;
        }
        $prompt  = "Translate the following texts to {$target_lang}.\n\n";
        $prompt .= "Rules:\n";
        $prompt .= "- Preserve all <protect-N> placeholders exactly as they appear.\n";
        $prompt .= "- Do not translate HTML tags, shortcodes, variables, or URLs.\n";
        $prompt .= "- Return ONLY a JSON array of translated strings in the same order.\n";
        $prompt .= "- No extra explanations, no markdown formatting.\n\n";
        $prompt .= "Texts:\n" . implode( "\n", $lines );
        return $prompt;
    }
    private function dispatch_request( $items, $target_lang, $system_prompt = '' ) {
        $prompt = $this->build_prompt( $items, $target_lang );
        $url = $this->base_url . 'chat/completions';
        $messages = array();
        if ( ! empty( $system_prompt ) ) {
            $messages[] = array( 'role' => 'system', 'content' => $system_prompt );
        }
        $messages[] = array( 'role' => 'user', 'content' => $prompt );
        $body = array( 'model' => $this->model, 'messages' => $messages, 'temperature' => $this->temperature );
        if ( $this->max_tokens > 0 ) {
            $body['max_tokens'] = $this->max_tokens;
        }
        return array( 'url' => $url, 'response' => wp_remote_post( $url, array( 'method' => 'POST', 'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->api_key ), 'body' => wp_json_encode( $body ), 'timeout' => $this->timeout ) ) );
    }
    private function request_with_retries( $items, $target_lang, $system_prompt = '' ) {
        $attempts = array();
        $max_attempts = (int) apply_filters( 'opentranslation_openai_max_attempts', 3, $this->model, $this->base_url );
        $max_attempts = max( 1, min( 5, $max_attempts ) );
        $base_delay_ms = (int) apply_filters( 'opentranslation_openai_retry_delay_ms', 800, $this->model, $this->base_url );
        $base_delay_ms = max( 100, min( 3000, $base_delay_ms ) );
        $last_request = array( 'url' => '', 'response' => new \WP_Error( 'openai_request_failed', __( 'OpenAI request failed before dispatch.', 'opentranslation' ) ), 'raw_body' => '', 'attempts' => array() );
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $request = $this->dispatch_request( $items, $target_lang, $system_prompt );
            $response = $request['response'];
            $raw_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
            $status_code = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );
            $attempts[] = array( 'attempt' => $attempt, 'http_code' => $status_code, 'body_len' => strlen( $raw_body ), 'wp_error' => is_wp_error( $response ) ? $response->get_error_message() : '' );
            $last_request = array( 'url' => $request['url'], 'response' => $response, 'raw_body' => $raw_body, 'attempts' => $attempts );
            if ( ! $this->should_retry_request( $response, $status_code, $raw_body, $attempt, $max_attempts ) ) {
                break;
            }
            usleep( $base_delay_ms * $attempt * 1000 );
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
        if ( in_array( (int) $status_code, array( 408, 409, 429, 500, 502, 503, 504, 520, 522, 524 ), true ) ) {
            return true;
        }
        return $status_code >= 200 && $status_code < 300 && '' === trim( (string) $raw_body );
    }
    private function extract_message_content( $data ) {
        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            return '';
        }
        $content = $data['choices'][0]['message']['content'];
        if ( is_string( $content ) ) {
            return $content;
        }
        if ( ! is_array( $content ) ) {
            return '';
        }
        $parts = array();
        foreach ( $content as $part ) {
            if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
                $parts[] = $part['text'];
            } elseif ( isset( $part['content'] ) && is_string( $part['content'] ) ) {
                $parts[] = $part['content'];
            }
        }
        return implode( "\n", $parts );
    }
    private function build_http_error( $status_code, $raw_body ) {
        $message = sprintf( __( 'OpenAI-compatible endpoint returned HTTP %d.', 'opentranslation' ), (int) $status_code );
        $preview = $this->limit_preview( $raw_body, 500 );
        if ( '' === $preview ) {
            $preview = '(empty body)';
        }
        return new \WP_Error( 'openai_http_error', $message . ' Debug: ' . $preview, array( 'status_code' => (int) $status_code ) );
    }
    private function parse_response( $content, $raw_body = '' ) {
        $content = trim( $content );
        if ( preg_match( '/\[.*\]/s', $content, $matches ) ) {
            $candidate = $matches[0];
            $decoded = json_decode( $candidate, true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                return $decoded;
            }
        }
        if ( strpos( $content, '```json' ) !== false ) {
            if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
                $decoded = json_decode( $matches[1], true );
                if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                    return $decoded;
                }
            }
        }
        if ( strpos( $content, '```' ) === 0 ) {
            $content = preg_replace( '/^```\s*/', '', $content );
            $content = preg_replace( '/\s*```$/', '', $content );
        }
        $decoded = json_decode( $content, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
            return $decoded;
        }
        $lines = preg_split( '/\r\n|\r|\n/', $content );
        $results = array();
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( preg_match( '/^\d+[\.\)\-:]\s*(.+)$/', $line, $matches ) ) {
                $results[] = trim( $matches[1] );
            }
        }
        if ( ! empty( $results ) ) {
            return $results;
        }
        $debug = substr( $raw_body, 0, 500 );
        return new \WP_Error( 'parse_error', __( 'Failed to parse model response as JSON array.', 'opentranslation' ) . ' Debug: ' . $debug );
    }
    private function translate_resilient( $items, $target_lang, $system_prompt = '', $depth = 0 ) {
        $request = $this->request_with_retries( $items, $target_lang, $system_prompt );
        $this->last_request_units += isset( $request['request_units'] ) ? (int) $request['request_units'] : 0;
        $response = $request['response'];
        $raw_body = isset( $request['raw_body'] ) ? $request['raw_body'] : '';
        if ( is_wp_error( $response ) ) {
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, $response, $depth );
        }
        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, $this->build_http_error( $status_code, $raw_body ), $depth );
        }
        $data = json_decode( $raw_body, true );
        $content = $this->extract_message_content( $data );
        if ( '' === $content ) {
            $debug = $this->limit_preview( $raw_body, 500 );
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, new \WP_Error( 'openai_empty', __( 'OpenAI-compatible endpoint returned empty content.', 'opentranslation' ) . ' Debug: ' . $debug ), $depth );
        }
        $parsed = $this->parse_response( $content, $raw_body );
        if ( is_wp_error( $parsed ) ) {
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, $parsed, $depth );
        }
        if ( count( $parsed ) !== count( $items ) ) {
            return $this->maybe_split_request( $items, $target_lang, $system_prompt, new \WP_Error( 'count_mismatch', __( 'Model response count does not match request count.', 'opentranslation' ) ), $depth );
        }
        return $parsed;
    }
    private function maybe_split_request( $items, $target_lang, $system_prompt, $error, $depth ) {
        if ( ! $this->should_split_request( $items, $error, $depth ) ) {
            return $error;
        }
        $translations = array();
        foreach ( array_chunk( $items, (int) ceil( count( $items ) / 2 ) ) as $chunk ) {
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
        $max_depth = (int) apply_filters( 'opentranslation_openai_split_max_depth', 3, $this->model, $this->base_url );
        if ( $depth >= max( 1, min( 5, $max_depth ) ) ) {
            return false;
        }
        $code = $error instanceof \WP_Error ? $error->get_error_code() : '';
        if ( in_array( $code, array( 'openai_empty', 'parse_error', 'count_mismatch' ), true ) ) {
            return true;
        }
        if ( 'openai_http_error' !== $code ) {
            return false;
        }
        $status_code = (int) $error->get_error_data( 'status_code' );
        return in_array( $status_code, array( 408, 409, 429, 500, 502, 503, 504, 520, 522, 524 ), true );
    }
    private function limit_preview( $text, $limit = 1500 ) {
        $text = trim( (string) $text );
        if ( strlen( $text ) <= $limit ) {
            return $text;
        }
        return substr( $text, 0, $limit ) . '...';
    }
}
