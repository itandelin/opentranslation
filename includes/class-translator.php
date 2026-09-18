<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Translator {
    public function __construct() {
    }
    public function translate_batch( $items, $language ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $system_prompt = isset( $settings['system_prompt'] ) ? $settings['system_prompt'] : '';
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) ) {
            return array( 'translations' => array(), 'error' => __( 'No models configured.', 'opentranslation' ) );
        }

        usort( $models, function ( $a, $b ) {
            return (int) $a['priority'] - (int) $b['priority'];
        } );

        $glossary = Glossary::for_language( $language );

        $to_translate = array();
        $mappings = array();
        $errors = array();
        $request_units = 0;

        foreach ( $items as $item ) {
            $context = isset( $item['context'] ) ? $item['context'] : '';
            $cache_key = Cache::generate_key( $item['original'], $language, $context );
            $cached = Cache::get( $cache_key );
            $passthrough = self::get_passthrough_translation( $item['original'] );

            if ( null !== $passthrough ) {
                if ( ! $cached || $cached['translated_text'] !== $passthrough || 'translated' !== $cached['status'] ) {
                    Cache::set( $cache_key, $item['original'], $language, $passthrough, $context, 'passthrough', 'translated' );
                }
                $mappings[] = array(
                    'id'         => $item['id'],
                    'translated' => $passthrough,
                    'cache_key'  => $cache_key,
                    'from_cache' => false,
                );
                continue;
            }

            if ( $cached && ! empty( $cached['translated_text'] ) ) {
                $mappings[] = array(
                    'id'         => $item['id'],
                    'translated' => $cached['translated_text'],
                    'cache_key'  => $cache_key,
                    'from_cache' => true,
                );
                continue;
            }

            if ( $this->should_skip_retry( $cached ) ) {
                continue;
            }

            if ( ! $cached ) {
                Cache::set( $cache_key, $item['original'], $language, '', $context, '', 'pending' );
            }

            $protector = new Protector( $glossary );
            $to_translate[] = array(
                'id'        => $item['id'],
                'original'  => $item['original'],
                'protected' => $protector->protect( $item['original'] ),
                'context'   => $context,
                'cache_key' => $cache_key,
                'protector' => $protector,
            );
        }

        if ( empty( $to_translate ) ) {
            return array( 'translations' => $mappings, 'request_units' => $request_units );
        }

        $chunk_size = (int) apply_filters( 'opentranslation_translate_chunk_size', 10, $language, count( $to_translate ) );
        $chunk_size = max( 1, min( 20, $chunk_size ) );
        $chunk_chars = (int) apply_filters( 'opentranslation_translate_chunk_chars', 2600, $language, count( $to_translate ) );
        $chunk_chars = max( 500, min( 12000, $chunk_chars ) );
        $chunks = $this->chunk_items( $to_translate, $chunk_size, $chunk_chars );

        foreach ( $chunks as $chunk ) {
            $texts = array_column( $chunk, 'protected' );
            $response = null;
            $used_model = '';

            foreach ( $models as $model_config ) {
                $client = $this->create_client( $model_config );
                if ( ! $client ) {
                    continue;
                }
                $response = $client->translate( $texts, $language, trim( $system_prompt ) );
                $units = max( 1, (int) $client->get_last_request_units() );
                $request_units += $units;
                Usage::record( Model_Identity::key( $model_config ), Model_Identity::label( $model_config ), $client->get_last_usage(), $units );
                if ( ! is_wp_error( $response ) ) {
                    $used_model = $model_config['model'];
                    break;
                }
                Log::add( '', 'model_fallback', $model_config['model'] . ': ' . $response->get_error_message() );
            }

            if ( is_wp_error( $response ) ) {
                $this->handle_error( $chunk, $response );
                $errors[] = $response->get_error_message();
                continue;
            }

            if ( count( $response ) !== count( $chunk ) ) {
                $error = new \WP_Error( 'count_mismatch', __( 'Model response count does not match request count.', 'opentranslation' ) );
                $this->handle_error( $chunk, $error );
                $errors[] = $error->get_error_message();
                continue;
            }

            foreach ( $chunk as $index => $item ) {
                $raw = isset( $response[ $index ] ) ? $response[ $index ] : '';

                // 必须先校验再还原。restore() 会把占位符换回真实内容，
                // 之后校验必然把所有 token 报成缺失。
                $validation = $item['protector']->validate( $raw );
                if ( true !== $validation ) {
                    $this->mark_item_failed(
                        $item,
                        'Placeholder validation failed: ' . implode( ', ', $validation )
                    );
                    continue;
                }

                $restored = $item['protector']->restore( $raw );

                // 兜底：任何漏过 validate() 的占位符都不写入译文
                if ( Protector::has_residual_placeholder( $restored ) ) {
                    $this->mark_item_failed( $item, 'Residual placeholder after restore.' );
                    continue;
                }

                $cache_result = Cache::set( $item['cache_key'], $item['original'], $language, $restored, $item['context'], $used_model, 'translated' );
                if ( ! $cache_result ) {
                    Log::add( $item['cache_key'], 'cache_set_failed', 'Failed to save translation to cache.' );
                }
                $mappings[] = array(
                    'id'         => $item['id'],
                    'translated' => $restored,
                    'cache_key'  => $item['cache_key'],
                    'from_cache' => false,
                );
            }
        }

        $result = array( 'translations' => $mappings, 'request_units' => $request_units );
        if ( ! empty( $errors ) ) {
            $result['error'] = implode( ' | ', array_unique( $errors ) );
            $result['errors'] = array_values( array_unique( $errors ) );
        }

        return $result;
    }

    public function test_connection( $language, $source_language = null ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $system_prompt = isset( $settings['system_prompt'] ) ? trim( $settings['system_prompt'] ) : '';
        $models = Encrypted_Options::get( 'opentranslation_models', array() );

        if ( empty( $models ) ) {
            return new \WP_Error(
                'no_models',
                __( 'No models configured.', 'opentranslation' ),
                array(
                    'engine'            => 'OpenTranslation AI',
                    'status'            => 'error',
                    'configured_models' => 0,
                )
            );
        }

        usort( $models, function ( $a, $b ) {
            return (int) $a['priority'] - (int) $b['priority'];
        } );

        $sample_input = 'Hello world';
        $attempts = array();

        foreach ( $models as $model_config ) {
            $client = $this->create_client( $model_config );
            if ( ! $client || ! method_exists( $client, 'test_connection' ) ) {
                continue;
            }

            $diagnostic = $client->test_connection( array( $sample_input ), $language, $system_prompt );
            $diagnostic['priority'] = isset( $model_config['priority'] ) ? (int) $model_config['priority'] : null;
            $attempts[] = $diagnostic;

            if ( ! empty( $diagnostic['success'] ) ) {
                return array(
                    'engine'                => 'OpenTranslation AI',
                    'status'                => 'success',
                    'configured_models'     => count( $models ),
                    'selected_provider'     => isset( $diagnostic['provider'] ) ? $diagnostic['provider'] : '',
                    'selected_model'        => isset( $diagnostic['model'] ) ? $diagnostic['model'] : '',
                    'priority'              => isset( $diagnostic['priority'] ) ? $diagnostic['priority'] : null,
                    'source_language'       => $source_language,
                    'target_language'       => $language,
                    'base_url'              => isset( $diagnostic['base_url'] ) ? $diagnostic['base_url'] : '',
                    'endpoint'              => isset( $diagnostic['endpoint'] ) ? $diagnostic['endpoint'] : '',
                    'upstream_model'        => isset( $diagnostic['upstream_model'] ) ? $diagnostic['upstream_model'] : '',
                    'usage'                 => isset( $diagnostic['usage'] ) ? $diagnostic['usage'] : array(),
                    'system_prompt_enabled' => ! empty( $system_prompt ),
                    'sample_input'          => $sample_input,
                    'sample_output'         => isset( $diagnostic['translated_preview'] ) ? $diagnostic['translated_preview'] : '',
                    'http'                  => array(
                        'code'    => isset( $diagnostic['http_code'] ) ? $diagnostic['http_code'] : null,
                        'message' => isset( $diagnostic['http_message'] ) ? $diagnostic['http_message'] : '',
                    ),
                    'attempts'              => $attempts,
                );
            }
        }

        $last_attempt = ! empty( $attempts ) ? end( $attempts ) : array();
        $message = ! empty( $last_attempt['error'] ) ? $last_attempt['error'] : __( 'All configured models failed the connection test.', 'opentranslation' );

        return new \WP_Error(
            'test_failed',
            $message,
            array(
                'engine'                => 'OpenTranslation AI',
                'status'                => 'error',
                'configured_models'     => count( $models ),
                'source_language'       => $source_language,
                'target_language'       => $language,
                'system_prompt_enabled' => ! empty( $system_prompt ),
                'attempts'              => $attempts,
            )
        );
    }

    private function create_client( $config ) {
        $provider = isset( $config['provider'] ) ? $config['provider'] : 'openai';
        if ( 'claude' === $provider ) {
            return new Claude_Client( $config );
        }
        return new OpenAI_Client( $config );
    }
    private function chunk_items( $items, $chunk_size, $chunk_chars ) {
        $chunks = array();
        $current = array();
        $current_chars = 0;
        foreach ( $items as $item ) {
            $text_chars = max( 1, strlen( $item['protected'] ) );
            if ( ! empty( $current ) && ( count( $current ) >= $chunk_size || ( $current_chars + $text_chars ) > $chunk_chars ) ) {
                $chunks[] = $current;
                $current = array();
                $current_chars = 0;
            }
            $current[] = $item;
            $current_chars += $text_chars;
        }
        if ( ! empty( $current ) ) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function handle_error( $items, $error ) {
        foreach ( $items as $item ) {
            $this->mark_item_failed( $item, $error->get_error_message() );
        }
    }

    private function mark_item_failed( $item, $message ) {
        $cached = Cache::get( $item['cache_key'] );
        $retry = ( $cached && isset( $cached['retry_count'] ) ) ? (int) $cached['retry_count'] : 0;
        if ( $retry >= 3 ) {
            Cache::update_status( $item['cache_key'], 'failed' );
            Log::add( $item['cache_key'], 'failed', $message );
        } else {
            Cache::mark_retry( $item['cache_key'], $retry );
            Log::add( $item['cache_key'], 'retry', $message );
        }
    }

    private function should_skip_retry( $cached ) {
        if ( empty( $cached ) || ! is_array( $cached ) ) {
            return false;
        }

        if ( isset( $cached['status'] ) && 'failed' === $cached['status'] ) {
            return true;
        }

        if ( isset( $cached['status'] ) && 'pending' === $cached['status'] && ! empty( $cached['next_retry_at'] ) ) {
            $next_retry = strtotime( $cached['next_retry_at'] . ' GMT' );
            if ( false !== $next_retry && $next_retry > current_time( 'timestamp', true ) ) {
                return true;
            }
        }

        return false;
    }

    public static function get_passthrough_translation( $text ) {
        $text = trim( (string) $text );

        if ( '' === $text ) {
            return '';
        }

        if ( filter_var( $text, FILTER_VALIDATE_URL ) ) {
            return $text;
        }

        if ( filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
            return $text;
        }

        if ( preg_match( '/^(?:mailto:|tel:|sms:|fax:)/i', $text ) ) {
            return $text;
        }

        if ( preg_match( '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})+$/i', $text ) ) {
            return $text;
        }

        if ( preg_match( '/\.(?:jpe?g|png|gif|svg|webp|avif|ico|bmp|mp4|webm|mov|avi|pdf)(?:\?.*)?$/i', $text ) ) {
            return $text;
        }

        return null;
    }

    /**
     * 各 provider 的官方 Base URL。
     *
     * 供 Client 构造器与后台测试共用，避免两处默认值不一致——
     * 这正是「测试通过但队列全失败」的根源。
     *
     * @param string $provider openai|claude
     * @return string 带尾斜杠的 URL
     */
    public static function default_base_url( $provider ) {
        return 'claude' === $provider
            ? 'https://api.anthropic.com/v1/'
            : 'https://api.openai.com/v1/';
    }
}
