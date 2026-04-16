<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Claude_Client implements Model_Client {
    private $api_key;
    private $base_url;
    private $model;
    private $temperature;
    private $max_tokens;
    private $timeout = 60;

    public function __construct( $config ) {
        $this->api_key = $config['api_key'];
        $this->base_url = trailingslashit( $config['base_url'] );
        $this->model = $config['model'];
        $this->temperature = isset( $config['temperature'] ) ? (float) $config['temperature'] : 0.3;
        $this->max_tokens = isset( $config['max_tokens'] ) ? (int) $config['max_tokens'] : 4096;
    }

    public function translate( $items, $target_lang, $system_prompt = '' ) {
        $request = $this->dispatch_request( $items, $target_lang, $system_prompt );
        $response = $request['response'];

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $raw_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw_body, true );
        if ( empty( $data['content'][0]['text'] ) ) {
            return new \WP_Error( 'claude_empty', __( 'Claude returned empty content.', 'opentranslation' ) );
        }

        return $this->parse_response( $data['content'][0]['text'] );
    }

    public function test_connection( $items, $target_lang, $system_prompt = '' ) {
        $request = $this->dispatch_request( $items, $target_lang, $system_prompt );
        $response = $request['response'];

        $diagnostic = array(
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
        );

        if ( is_wp_error( $response ) ) {
            $diagnostic['success'] = false;
            $diagnostic['error'] = $response->get_error_message();
            return $diagnostic;
        }

        $raw_body = wp_remote_retrieve_body( $response );
        $status_code = wp_remote_retrieve_response_code( $response );
        $status_message = wp_remote_retrieve_response_message( $response );
        $data = json_decode( $raw_body, true );
        $parsed = null;

        if ( empty( $data['content'][0]['text'] ) ) {
            $parsed = new \WP_Error( 'claude_empty', __( 'Claude returned empty content.', 'opentranslation' ) );
        } else {
            $parsed = $this->parse_response( $data['content'][0]['text'] );
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
            $diagnostic['error'] = sprintf(
                /* translators: %d is the HTTP status code. */
                __( 'Upstream service returned HTTP %d.', 'opentranslation' ),
                $status_code
            );
        }

        return $diagnostic;
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

        return array(
            'url' => $url,
            'response' => wp_remote_post( $url, array(
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $this->api_key,
                    'anthropic-version' => '2023-06-01',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => $this->timeout,
            ) ),
        );
    }

    private function parse_response( $content ) {
        $content = trim( $content );
        if ( strpos( $content, '```json' ) === 0 ) {
            $content = preg_replace( '/^```json\s*/', '', $content );
            $content = preg_replace( '/\s*```$/', '', $content );
        } elseif ( strpos( $content, '```' ) === 0 ) {
            $content = preg_replace( '/^```\s*/', '', $content );
            $content = preg_replace( '/\s*```$/', '', $content );
        }
        $decoded = json_decode( $content, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return new \WP_Error( 'parse_error', __( 'Failed to parse model response as JSON array.', 'opentranslation' ) );
        }
        return $decoded;
    }

    private function limit_preview( $text, $limit = 1500 ) {
        $text = trim( (string) $text );
        if ( strlen( $text ) <= $limit ) {
            return $text;
        }

        return substr( $text, 0, $limit ) . '...';
    }
}
