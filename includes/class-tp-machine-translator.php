<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TranslatePress 的机器翻译引擎实现。
 *
 * 职责边界：只把 TP 交来的字符串转接给自定义 AI 模型，再把译文交回 TP。
 * 何时翻译、翻译什么、译文存哪，全部由 TP 决定。
 *
 * 与官方 Google / DeepL 引擎的契约保持一致：
 * - 保键返回，允许少返回（TP 会在下次渲染重新提交）
 * - 失败返回空数组，不返回 WP_Error
 * - 自行调用 machine_translator_logger 的查询日志与字符配额
 * - chunk 之间检查 quota_exceeded()
 */
class TP_Machine_Translator extends \TRP_Machine_Translator {

    /** @var Translator */
    private $translator;

    public function __construct( $settings ) {
        parent::__construct( $settings );

        $this->translator = new Translator();
    }

    /**
     * TP 用它判断引擎是否已配置。
     *
     * 本引擎的凭据存在插件自己的加密选项里，不走 TP 的单一 key 字段，
     * 因此这里返回一个非空占位值表示「已配置」。
     * verify_request_parameters() 会用 empty() 校验它。
     */
    public function get_api_key() {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );

        return ! empty( $models ) ? 'configured' : '';
    }

    /**
     * 供 TP 设置页显示凭据是否正确。
     *
     * 不实现本方法时，TP 会回落到 automatic_translate_error_check()，
     * 而那个 switch 只认 google_translate_v2 与 deepl，
     * 对自定义引擎一律判定为「正确」（class-machine-translator.php:153-193）。
     *
     * @return array{message:string,error:bool}
     */
    public function check_api_key_validity() {
        $result = $this->test_request();

        if ( is_wp_error( $result ) ) {
            return array( 'message' => $result->get_error_message(), 'error' => true );
        }

        return array( 'message' => '', 'error' => false );
    }

    /**
     * 连通性测试。
     *
     * 返回形态与 TP 的 AJAX 处理器对齐（class-machine-translation-tab.php:267-307）：
     * WP_Error 走 wp_send_json_error 并把 error_data 当作 body；
     * 否则要求可被 wp_remote_retrieve_response_code() 解析。
     */
    public function test_request() {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) ) {
            return new \WP_Error( 'no_models', __( 'No AI models configured in OpenTranslation.', 'opentranslation' ) );
        }

        $settings = TP_Storage_Adapter::get_settings();
        $source_language = isset( $settings['default-language'] ) ? $settings['default-language'] : 'en_US';
        $target_languages = TP_Storage_Adapter::get_target_languages();
        $target_language = ! empty( $target_languages ) ? reset( $target_languages ) : 'zh_CN';

        $diagnostic = $this->translator->test_connection( $target_language, $source_language );
        if ( is_wp_error( $diagnostic ) ) {
            return new \WP_Error(
                $diagnostic->get_error_code(),
                $diagnostic->get_error_message(),
                $this->format_test_diagnostic( $diagnostic->get_error_data() )
            );
        }

        return array(
            'response' => array(
                'code'    => 200,
                'message' => 'OpenTranslation diagnostic completed.',
            ),
            'body'     => $this->format_test_diagnostic( $diagnostic ),
        );
    }

    /**
     * 翻译一批字符串——TP 渲染页面时调用的主入口。
     *
     * 传入的 $new_strings 已经过 TP 的预处理：去重、最短长度过滤、
     * 纯标点跳过、%s/%d 等替换为 1TPnT 占位符、执行指定 shortcode
     * （class-machine-translator.php:368-418）。插件不再重复这些工作。
     *
     * @param array  $new_strings          待翻译字符串，键是 DOM 节点号，必须原样保留
     * @param string $target_language_code 目标语言的 TP 语言码（如 zh_CN）
     * @param string $source_language_code 源语言的 TP 语言码（如 en_US）
     * @return array 保键的译文数组；出错返回空数组
     */
    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( empty( $new_strings ) || ! is_array( $new_strings ) ) {
            return array();
        }

        // 与官方引擎一致：TP 的签名允许不传源语言
        if ( null === $source_language_code ) {
            $source_language_code = isset( $this->settings['default-language'] ) ? $this->settings['default-language'] : null;
        }

        // 基类校验：语言码有效、非爬虫、未超出 TP 的每日字符配额
        if ( ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
            return array();
        }

        // 基类构造时已备好 TP 语言码 → 引擎码 的映射，不要自行转换
        $source_language = $this->machine_translation_codes[ $source_language_code ];
        $target_language = $this->machine_translation_codes[ $target_language_code ];

        $budget             = Request_Budget::for_current_request();
        $translated_strings = array();

        foreach ( array_chunk( $new_strings, $budget->chunk_size(), true ) as $chunk ) {
            // 预算用尽：剩余条目本轮不返回，TP 下次渲染会重新提交
            if ( $budget->exhausted() ) {
                break;
            }

            $outcome = $this->translator->translate_chunk(
                $chunk,
                $target_language,
                $source_language,
                $target_language_code
            );

            $this->log_query( $chunk, $outcome, $source_language, $target_language );

            foreach ( $outcome['results'] as $key => $result ) {
                if ( $result['ok'] ) {
                    $translated_strings[ $key ] = $result['text'];
                } elseif ( $result['permanent'] ) {
                    /*
                     * 永久失败写空串。TP 会以 MACHINE_TRANSLATED 状态落库，
                     * 之后跳过不再重翻——这是 TP 自己的放弃惯用法，
                     * 见 class-translation-render.php:1774-1781 的注释。
                     * 前台显示原文，优于显示破损译文。
                     */
                    $translated_strings[ $key ] = '';
                }
                // 瞬时失败：不写入该 key，交给 TP 下次渲染自然重试
            }

            $budget->consume( count( $chunk ), $outcome['elapsed'] );

            if ( $this->machine_translator_logger && $this->machine_translator_logger->quota_exceeded() ) {
                break;
            }
        }

        return $translated_strings;
    }

    /**
     * 上报查询日志与字符配额。
     *
     * 缺了这一步，TP 设置页的「今日已翻译字符数」永远是 0，
     * 每日字符上限也永不触发（quota_exceeded() 恒为 false）。
     */
    private function log_query( $chunk, $outcome, $source_language, $target_language ) {
        if ( ! $this->machine_translator_logger ) {
            return;
        }

        // 仅在 TP 的「记录机器翻译查询」开启时真正写入
        $this->machine_translator_logger->log( array(
            'strings'     => serialize( $chunk ),
            'response'    => serialize( $outcome['raw_response'] ),
            'lang_source' => $source_language,
            'lang_target' => $target_language,
        ) );

        if ( ! empty( $outcome['succeeded_sources'] ) ) {
            $this->machine_translator_logger->count_towards_quota( $outcome['succeeded_sources'] );
        }
    }

    /**
     * 引擎支持的语言。
     *
     * AI 模型不像 Google/DeepL 有固定语言清单，凡 TP 配置了的语言都能翻。
     * 与 get_engine_specific_language_codes() 返回同样的码，
     * 基类的 array_diff 比对（class-machine-translator.php:106-108）因而恒为空。
     */
    public function get_supported_languages() {
        $languages = isset( $this->settings['translation-languages'] ) ? $this->settings['translation-languages'] : array();

        return array_values( $this->trp_languages->get_iso_codes( $languages ) );
    }

    /**
     * TP 语言码 → 引擎码。与官方 Google 引擎实现一致。
     */
    public function get_engine_specific_language_codes( $languages ) {
        return array_values( $this->trp_languages->get_iso_codes( $languages ) );
    }

    /**
     * 语言可用性。
     *
     * 恒为真，且刻意绕开基类实现。基类会把 get_supported_languages() 的结果
     * 缓存进 trp_db_stored_data（class-machine-translator.php:90-104），
     * 之后新增目标语言时缓存不会自动刷新，对「支持任意语言」的 AI 引擎
     * 只会造成「不支持的语言」误报。
     */
    public function check_languages_availability( $languages, $force_recheck = false ) {
        return true;
    }

    /**
     * 额外的请求校验：至少配置了一个模型。
     */
    public function extra_request_validations( $to_language ) {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );

        return ! empty( $models );
    }

    /**
     * 自动翻译是否可用。
     */
    public function is_available( $languages = array() ) {
        $settings = isset( $this->settings['trp_machine_translation_settings'] ) ? $this->settings['trp_machine_translation_settings'] : array();
        $enabled  = ( isset( $settings['machine-translation'] ) ? $settings['machine-translation'] : '' ) === 'yes';

        $models       = Encrypted_Options::get( 'opentranslation_models', array() );
        $is_available = $enabled && ! empty( $models );

        if ( $is_available && ! empty( $languages ) ) {
            $is_available = $this->check_languages_availability( $languages );
        }

        // 基类同样暴露这个扩展点，覆写时不能把它弄丢
        return apply_filters( 'trp_machine_translator_is_available', $is_available, $languages, $settings );
    }

    private function format_test_diagnostic( $diagnostic ) {
        $json = wp_json_encode(
            $diagnostic,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ( false !== $json ) {
            return $json;
        }

        return print_r( $diagnostic, true );
    }
}
