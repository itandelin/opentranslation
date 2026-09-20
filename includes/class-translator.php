<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 请求/响应转换器。
 *
 * 只做一件事：把 TranslatePress 交来的一批字符串转成模型请求，
 * 再把模型响应转回 TP 能消费的形式。不缓存、不排队、不碰任何数据表。
 *
 * 失败分成两类，语义完全不同：
 *
 * - 整块级失败（网络、HTTP、解析、条数不符、熔断）一律判为【瞬时】。
 *   这类失败与具体条目无关，一个坏条目不该拖垮同批其它条目。
 *   瞬时失败的条目不返回给 TP，TP 下次渲染会自然重新提交。
 *
 * - 单条级失败（占位符校验失败、模型拒答、空译文、类型异常）判为【永久】。
 *   重翻也不会变好，返回空串让 TP 按「已机器翻译」记下并停止重试
 *   （TP 的既有惯用法，见 class-translation-render.php:1774-1781）。
 */
class Translator {

    public function __construct() {
    }

    /**
     * 翻译一个 chunk。
     *
     * @param array  $chunk             tp_key => 原文
     * @param string $target_code       目标语言的引擎码（如 zh-CN），进模型提示词
     * @param string $source_code       源语言的引擎码（如 en）
     * @param string $glossary_language 术语表查询用的 TP 语言码（如 zh_CN）
     * @return array{
     *     results:array<string,array{ok:bool,text:string,permanent:bool,code:string}>,
     *     succeeded_sources:array<int,string>,
     *     raw_response:mixed,
     *     elapsed:float
     * }
     */
    public function translate_chunk( array $chunk, $target_code, $source_code = '', $glossary_language = '' ) {
        $started = microtime( true );

        $outcome = array(
            'results'           => array(),
            'succeeded_sources' => array(),
            'raw_response'      => null,
            'elapsed'           => 0.0,
        );

        if ( empty( $chunk ) ) {
            return $outcome;
        }

        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) ) {
            $outcome['results'] = self::fail_all( $chunk, 'no_models', false );
            return self::stamp( $outcome, $started );
        }

        usort( $models, function ( $a, $b ) {
            return (int) $a['priority'] - (int) $b['priority'];
        } );

        // 直通：URL、邮箱、语言码、媒体路径没有翻译的意义，也不该消耗 token
        $pending = array();
        foreach ( $chunk as $key => $original ) {
            $passthrough = self::get_passthrough_translation( $original );
            if ( null !== $passthrough ) {
                $outcome['results'][ $key ] = self::ok( $passthrough );
                continue;
            }
            $pending[ $key ] = $original;
        }

        if ( empty( $pending ) ) {
            return self::stamp( $outcome, $started );
        }

        $health    = new Model_Health();
        $available = $health->filter_available( $models );

        if ( empty( $available ) ) {
            Log::add( '', 'model_circuit_open', __( 'All configured models are circuit-open; skipping.', 'opentranslation' ) );
            $outcome['results'] += self::fail_all( $pending, 'circuit_open', false );
            return self::stamp( $outcome, $started );
        }

        // 每条独立一个 Protector：token 序号是按条目重新计数的，共用会串号
        $glossary   = Glossary::for_language( $glossary_language );
        $protectors = array();
        $texts      = array();
        foreach ( $pending as $key => $original ) {
            $protector          = new Protector( $glossary );
            $protectors[ $key ] = $protector;
            $texts[]            = $protector->protect( $original );
        }

        $settings      = get_option( 'opentranslation_settings', array() );
        $system_prompt = isset( $settings['system_prompt'] ) ? trim( $settings['system_prompt'] ) : '';

        $response = new \WP_Error( 'no_client', __( 'No usable model client.', 'opentranslation' ) );

        foreach ( $available as $model_config ) {
            $client = $this->create_client( $model_config );
            if ( ! $client ) {
                continue;
            }

            $response  = $client->translate( $texts, $target_code, $system_prompt, $source_code );
            $model_key = Model_Identity::key( $model_config );
            $units     = max( 1, (int) $client->get_last_request_units() );

            Usage::record( $model_key, Model_Identity::label( $model_config ), $client->get_last_usage(), $units );

            if ( ! is_wp_error( $response ) ) {
                $health->record_success( $model_key );
                break;
            }

            // 能到这里说明客户端内部的重试与切块都已用尽
            $health->record_failure( $model_key, $response->get_error_message() );
            Log::add( '', 'model_fallback', $model_config['model'] . ': ' . $response->get_error_message() );
        }

        $health->flush();

        if ( is_wp_error( $response ) ) {
            $outcome['raw_response'] = $response->get_error_message();
            $outcome['results']     += self::fail_all( $pending, $response->get_error_code(), false );
            return self::stamp( $outcome, $started );
        }

        if ( count( $response ) !== count( $pending ) ) {
            Log::add( '', 'count_mismatch', sprintf( 'expected %d, got %d', count( $pending ), count( $response ) ) );
            $outcome['raw_response'] = $response;
            $outcome['results']     += self::fail_all( $pending, 'count_mismatch', false );
            return self::stamp( $outcome, $started );
        }

        $outcome['raw_response'] = $response;

        // $response 与 $pending 同序：$texts 按 $pending 的顺序构建，
        // 客户端保证返回条数一致且顺序对应（见 parse_response 的编号校验）
        $index = 0;
        foreach ( $pending as $key => $original ) {
            $raw = isset( $response[ $index ] ) ? $response[ $index ] : null;
            $index++;

            $result = $this->finalize_item( $raw, $protectors[ $key ] );
            $outcome['results'][ $key ] = $result;

            if ( $result['ok'] ) {
                $outcome['succeeded_sources'][] = $original;
            } else {
                Log::add( '', 'item_failed', $result['code'] . ': ' . self::preview( $original ) );
            }
        }

        return self::stamp( $outcome, $started );
    }

    /**
     * 单条响应的校验与还原。
     *
     * 顺序不能变：必须先 validate 再 restore。restore() 会把占位符换回真实内容，
     * 之后再校验必然把所有 token 都报成缺失。
     *
     * @param mixed     $raw       模型返回的原始条目
     * @param Protector $protector 该条目对应的保护器
     * @return array{ok:bool,text:string,permanent:bool,code:string}
     */
    private function finalize_item( $raw, Protector $protector ) {
        // 模型可能返回对象/数组元素，直接丢给 strpos 会是未捕获的 TypeError
        if ( ! is_string( $raw ) ) {
            return self::failed( 'invalid_response_type', true );
        }

        if ( '' === trim( $raw ) ) {
            return self::failed( 'empty_translation', true );
        }

        if ( self::looks_like_refusal( $raw ) ) {
            return self::failed( 'model_refusal', true );
        }

        $validation = $protector->validate( $raw );
        if ( true !== $validation ) {
            return self::failed( 'placeholder_validation_failed', true );
        }

        $restored = $protector->restore( $raw );

        // 兜底：任何漏过 validate() 的占位符都不写入译文
        if ( Protector::has_residual_placeholder( $restored ) ) {
            return self::failed( 'residual_placeholder', true );
        }

        return self::ok( $restored );
    }

    /**
     * 模型拒答识别。
     *
     * 模式刻意收得很窄，只认「明确表示无法翻译/协助」的元回答。
     * 泛化的 sorry / 抱歉 不能算——「Sorry, page not found」译成
     * 「抱歉，页面未找到」是完全合法的译文。
     */
    private static function looks_like_refusal( $text ) {
        $patterns = array(
            '/\b(cannot|can\'?t|unable to|won\'?t be able to)\s+(translate|assist|help|comply|process)\b/i',
            '/\bas an? (ai|artificial intelligence|language model)\b/i',
            '/(无法|不能|不便|没办法)(为你|为您)?(进行)?(翻译|协助|处理)/u',
            '/作为(一个)?(AI|人工智能|语言模型)/iu',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }

        return false;
    }

    private static function ok( $text ) {
        return array( 'ok' => true, 'text' => $text, 'permanent' => false, 'code' => '' );
    }

    private static function failed( $code, $permanent ) {
        return array( 'ok' => false, 'text' => '', 'permanent' => (bool) $permanent, 'code' => $code );
    }

    /**
     * 把一批条目统一标记为失败。
     *
     * @param array  $items     tp_key => 原文
     * @param string $code      失败码
     * @param bool   $permanent 是否永久失败
     * @return array tp_key => result
     */
    private static function fail_all( array $items, $code, $permanent ) {
        $results = array();
        foreach ( array_keys( $items ) as $key ) {
            $results[ $key ] = self::failed( $code, $permanent );
        }

        return $results;
    }

    private static function stamp( array $outcome, $started ) {
        $outcome['elapsed'] = microtime( true ) - $started;

        return $outcome;
    }

    private static function preview( $text ) {
        $text = trim( (string) $text );

        return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 60 ) . '…' : $text;
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

    /**
     * 不需要送模型的内容，直接原样返回；不属于此类返回 null。
     */
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
     * 这正是「测试通过但翻译全失败」的根源。
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
