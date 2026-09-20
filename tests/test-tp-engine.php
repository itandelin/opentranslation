<?php
/**
 * TP_Machine_Translator::translate_array 的契约行为。
 *
 * 这是插件与 TranslatePress 之间唯一的接口。用一个最小的 TRP_Machine_Translator
 * 替身把它跑起来，验证的是真实契约，而不是对实现的推断：
 *
 * - 保键返回
 * - 永久失败写空串（TP 会记为已机器翻译并停止重试）
 * - 瞬时失败不返回该键（TP 下次渲染自然重试）
 * - 预算用尽时提前收工，已完成部分正常返回
 * - 字符配额与查询日志如实上报
 */

// ---------------------------------------------------------------------------
// TranslatePress 替身
// ---------------------------------------------------------------------------

if ( ! class_exists( 'TRP_Machine_Translator' ) ) {

    /**
     * 记录 TP 侧被调用情况的日志器替身。
     */
    class OT_Test_TRP_Logger {
        public $logged           = array();
        public $counted          = array();
        public $quota_exceeded   = false;

        public function log( $args = array() ) {
            $this->logged[] = $args;
        }

        public function count_towards_quota( $strings ) {
            $this->counted[] = $strings;
        }

        public function quota_exceeded() {
            return $this->quota_exceeded;
        }
    }

    /**
     * 语言组件替身：按 TP 的真实行为返回 locale => iso 映射。
     */
    class OT_Test_TRP_Languages {
        public function get_iso_codes( $language_codes, $map_google_codes = true ) {
            $map = array(
                'en_US' => 'en',
                'zh_CN' => 'zh-CN',
                'de_DE' => 'de',
            );

            $out = array();
            foreach ( (array) $language_codes as $code ) {
                $out[ $code ] = isset( $map[ $code ] ) ? $map[ $code ] : $code;
            }

            return $out;
        }
    }

    /**
     * TRP_Machine_Translator 的最小替身，只保留被测类实际依赖的成员。
     */
    class TRP_Machine_Translator {
        protected $settings;
        protected $machine_translator_logger;
        protected $trp_languages;
        protected $machine_translation_codes;

        /** @var bool 供用例强制 verify_request_parameters 失败 */
        public $force_verify_failure = false;

        public function __construct( $settings ) {
            $this->settings                  = $settings;
            $this->machine_translator_logger = new OT_Test_TRP_Logger();
            $this->trp_languages             = new OT_Test_TRP_Languages();
            $this->machine_translation_codes = $this->trp_languages->get_iso_codes(
                isset( $settings['translation-languages'] ) ? $settings['translation-languages'] : array()
            );
        }

        public function verify_request_parameters( $target_language_code, $source_language_code ) {
            if ( $this->force_verify_failure ) {
                return false;
            }

            return ! empty( $this->get_api_key() )
                && ! empty( $target_language_code )
                && ! empty( $source_language_code )
                && ! empty( $this->machine_translation_codes[ $target_language_code ] )
                && ! empty( $this->machine_translation_codes[ $source_language_code ] )
                && $this->machine_translation_codes[ $target_language_code ] !== $this->machine_translation_codes[ $source_language_code ];
        }

        public function get_api_key() {
            return false;
        }

        public function logger() {
            return $this->machine_translator_logger;
        }
    }
}

require_once __DIR__ . '/../includes/class-tp-machine-translator.php';

/**
 * 造一个引擎实例，并重置到干净状态。
 */
function ot_tp_engine() {
    ot_http_reset();
    ot_wp_context_set();

    $GLOBALS['ot_test_options']['opentranslation_models'] = array(
        array(
            'provider' => 'openai',
            'model'    => 'gpt-test',
            'api_key'  => 'sk-test',
            'priority' => 1,
        ),
    );

    unset(
        $GLOBALS['ot_test_options']['opentranslation_model_health'],
        $GLOBALS['ot_test_options']['opentranslation_glossary'],
        $GLOBALS['ot_test_options']['opentranslation_settings']
    );

    return new \OpenTranslation\TP_Machine_Translator( array(
        'default-language'      => 'en_US',
        'translation-languages' => array( 'en_US', 'zh_CN', 'de_DE' ),
    ) );
}

/** OpenAI 兼容的成功响应体 */
function ot_tp_body( $content ) {
    return json_encode( array(
        'choices' => array( array( 'message' => array( 'content' => $content ) ) ),
        'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ),
    ) );
}

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：保键返回译文' );

$engine = ot_tp_engine();
ot_http_enqueue( 200, ot_tp_body( '{"1":"你好","2":"世界"}' ) );

$out = $engine->translate_array(
    array( 12 => 'Hello', 34 => 'World' ),
    'zh_CN',
    'en_US'
);

ot_assert_same( array( 12, 34 ), array_keys( $out ), 'DOM 节点键原样保留' );
ot_assert_same( '你好', $out[12], '第一条译文正确' );
ot_assert_same( '世界', $out[34], '第二条译文正确' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：源语言缺省回落默认语言' );

$engine = ot_tp_engine();
ot_http_enqueue( 200, ot_tp_body( '{"1":"你好"}' ) );

// TP 的签名允许不传源语言。旧实现在这里会因 empty() 校验失败而整批静默返回空数组。
$out = $engine->translate_array( array( 7 => 'Hello' ), 'zh_CN' );

ot_assert_same( '你好', $out[7], '不传源语言时仍能翻译' );

$body = $GLOBALS['ot_http_log'][0]['body']['messages'][0]['content'];
ot_assert_true( false !== strpos( $body, 'from en' ), '提示词里声明了源语言' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：永久失败写空串' );

$engine = ot_tp_engine();
// 模型吞掉了 <strong> 的占位符 → 占位符校验失败 → 永久失败
ot_http_enqueue( 200, ot_tp_body( '{"1":"你好世界","2":"世界"}' ) );

$out = $engine->translate_array(
    array( 12 => '<strong>Hello</strong> world', 34 => 'World' ),
    'zh_CN',
    'en_US'
);

ot_assert_true( array_key_exists( 12, $out ), '永久失败的键仍然返回' );
ot_assert_same( '', $out[12], '永久失败返回空串，TP 据此停止重试' );
ot_assert_same( '世界', $out[34], '同批正常条目不受影响' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：瞬时失败不返回该键' );

$engine = ot_tp_engine();
// 不入队响应：stub 返回 WP_Error，模拟网络层失败
$out = $engine->translate_array(
    array( 12 => 'Hello', 34 => 'World' ),
    'zh_CN',
    'en_US'
);

ot_assert_same( array(), $out, '瞬时失败时整批不返回，交给 TP 下次重试' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：上报字符配额与查询日志' );

$engine = ot_tp_engine();
ot_http_enqueue( 200, ot_tp_body( '{"1":"你好","2":"世界"}' ) );

$engine->translate_array( array( 12 => 'Hello', 34 => 'World' ), 'zh_CN', 'en_US' );

$logger = $engine->logger();
ot_assert_same( 1, count( $logger->logged ), '每个 chunk 上报一次查询日志' );
ot_assert_same( 'en', $logger->logged[0]['lang_source'], '日志记录源语言引擎码' );
ot_assert_same( 'zh-CN', $logger->logged[0]['lang_target'], '日志记录目标语言引擎码' );
ot_assert_same( 1, count( $logger->counted ), '成功条目计入字符配额' );
ot_assert_same( 2, count( $logger->counted[0] ), '两条成功源串都被计数' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：失败条目不计入字符配额' );

$engine = ot_tp_engine();
ot_http_enqueue( 200, ot_tp_body( '{"1":"","2":"世界"}' ) );

$engine->translate_array( array( 12 => 'Hello', 34 => 'World' ), 'zh_CN', 'en_US' );

$logger = $engine->logger();
ot_assert_same( 1, count( $logger->counted[0] ), '空译文不计入配额，只算成功的那条' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：配额超限后停止后续 chunk' );

$engine = ot_tp_engine();
$engine->logger()->quota_exceeded = true;

// chunk 大小设为 1，制造两个 chunk
$GLOBALS['ot_test_filters']['opentranslation_request_chunk_size'] = function () {
    return 1;
};

ot_http_enqueue( 200, ot_tp_body( '{"1":"你好"}' ) );
ot_http_enqueue( 200, ot_tp_body( '{"1":"世界"}' ) );

$out = $engine->translate_array( array( 12 => 'Hello', 34 => 'World' ), 'zh_CN', 'en_US' );

ot_assert_same( 1, count( $GLOBALS['ot_http_log'] ), '配额超限后不再发起第二次请求' );
ot_assert_same( '你好', $out[12], '超限前完成的条目正常返回' );
ot_assert_same( false, array_key_exists( 34, $out ), '未处理的条目不返回' );

unset( $GLOBALS['ot_test_filters']['opentranslation_request_chunk_size'] );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：预算用尽时提前收工' );

$engine = ot_tp_engine();

$GLOBALS['ot_test_filters']['opentranslation_request_chunk_size'] = function () {
    return 1;
};
// 只允许翻 1 条
$GLOBALS['ot_test_filters']['opentranslation_request_max_strings'] = function () {
    return 1;
};

ot_http_enqueue( 200, ot_tp_body( '{"1":"你好"}' ) );
ot_http_enqueue( 200, ot_tp_body( '{"1":"世界"}' ) );

$out = $engine->translate_array( array( 12 => 'Hello', 34 => 'World' ), 'zh_CN', 'en_US' );

ot_assert_same( 1, count( $GLOBALS['ot_http_log'] ), '预算只够一次请求' );
ot_assert_same( 1, count( $out ), '只返回预算内完成的条目' );
ot_assert_same( '你好', $out[12], '返回的是第一条' );

unset(
    $GLOBALS['ot_test_filters']['opentranslation_request_chunk_size'],
    $GLOBALS['ot_test_filters']['opentranslation_request_max_strings']
);

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：前置校验失败时不发请求' );

$engine = ot_tp_engine();
$engine->force_verify_failure = true;

$out = $engine->translate_array( array( 12 => 'Hello' ), 'zh_CN', 'en_US' );

ot_assert_same( array(), $out, '校验失败返回空数组' );
ot_assert_same( 0, count( $GLOBALS['ot_http_log'] ), '校验失败不发起请求' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：空输入直接返回' );

$engine = ot_tp_engine();

ot_assert_same( array(), $engine->translate_array( array(), 'zh_CN', 'en_US' ), '空数组直接返回' );
ot_assert_same( 0, count( $GLOBALS['ot_http_log'] ), '空输入不发起请求' );

// ---------------------------------------------------------------------------

ot_test_group( 'TP 引擎：get_api_key 反映模型配置状态' );

$engine = ot_tp_engine();
ot_assert_same( 'configured', $engine->get_api_key(), '已配置模型时返回非空' );

unset( $GLOBALS['ot_test_options']['opentranslation_models'] );
ot_assert_same( '', $engine->get_api_key(), '未配置模型时返回空串' );

ot_tp_engine();
