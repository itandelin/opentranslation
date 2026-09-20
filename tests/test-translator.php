<?php
/**
 * Translator::translate_chunk 的转换与失败分类。
 *
 * 这条链路此前完全没有覆盖：旧实现依赖缓存表，而 $wpdb stub 不支持查询。
 * 现在转换器不触库了，可以直接测。
 */

namespace OpenTranslation;

/**
 * 造一个 OpenAI 兼容的成功响应。
 *
 * @param string $content 模型输出的正文
 */
function ot_openai_body( $content ) {
    return json_encode( array(
        'choices' => array( array( 'message' => array( 'content' => $content ) ) ),
        'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ),
    ) );
}

/**
 * 重置到「配置了单个 openai 模型、无术语、无熔断」的干净状态。
 */
function ot_translator_reset() {
    ot_http_reset();

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
}

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：正常批次保键返回' );

ot_translator_reset();
ot_http_enqueue( 200, ot_openai_body( '{"1":"你好","2":"世界"}' ) );

$translator = new Translator();
$outcome    = $translator->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( true, $outcome['results'][5]['ok'], '第一条成功' );
ot_assert_same( '你好', $outcome['results'][5]['text'], '第一条译文正确' );
ot_assert_same( '世界', $outcome['results'][9]['text'], '第二条译文正确' );
ot_assert_same( array( 5, 9 ), array_keys( $outcome['results'] ), 'DOM 键原样保留' );
ot_assert_same( 2, count( $outcome['succeeded_sources'] ), '成功源串计入配额' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：响应顺序错乱不造成错位' );

ot_translator_reset();
// 模型把编号顺序颠倒返回。纯文本条目没有占位符，
// 一旦按数组下标回填就会静默错位——编号协议正是为此存在。
ot_http_enqueue( 200, ot_openai_body( '{"2":"世界","1":"你好"}' ) );

$outcome = ( new Translator() )->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( '你好', $outcome['results'][5]['text'], 'Hello 仍对应「你好」' );
ot_assert_same( '世界', $outcome['results'][9]['text'], 'World 仍对应「世界」' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：编号缺失判失败而非错位' );

ot_translator_reset();
// 只回 1 条却声称对应 2 条输入。宁可整块失败，也不能让译文落到错的条目上。
// 客户端会对半切块重试，队列耗尽后返回 WP_Error。
ot_http_enqueue( 200, ot_openai_body( '{"1":"你好"}' ) );

$outcome = ( new Translator() )->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( false, $outcome['results'][5]['ok'], '编号不全时第一条不落成功' );
ot_assert_same( false, $outcome['results'][9]['ok'], '编号不全时第二条不落成功' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：空译文判永久失败' );

ot_translator_reset();
ot_http_enqueue( 200, ot_openai_body( '{"1":"","2":"世界"}' ) );

$outcome = ( new Translator() )->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( false, $outcome['results'][5]['ok'], '空译文不算成功' );
ot_assert_same( true, $outcome['results'][5]['permanent'], '空译文是永久失败' );
ot_assert_same( 'empty_translation', $outcome['results'][5]['code'], '失败码为 empty_translation' );
ot_assert_same( true, $outcome['results'][9]['ok'], '同批其它条目不受影响' );
ot_assert_same( 1, count( $outcome['succeeded_sources'] ), '空译文不计入配额' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：非字符串元素不抛 TypeError' );

ot_translator_reset();
// 模型返回对象元素。旧实现会把数组丢给 strpos()，在前台渲染链路上就是 Fatal。
ot_http_enqueue( 200, ot_openai_body( '{"1":{"text":"你好"},"2":"世界"}' ) );

$outcome = ( new Translator() )->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( false, $outcome['results'][5]['ok'], '对象元素判失败' );
ot_assert_same( true, $outcome['results'][5]['permanent'], '类型异常是永久失败' );
ot_assert_same( 'invalid_response_type', $outcome['results'][5]['code'], '失败码为 invalid_response_type' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：模型拒答不当作译文' );

ot_translator_reset();
ot_http_enqueue( 200, ot_openai_body( '{"1":"I cannot translate this content.","2":"世界"}' ) );

$outcome = ( new Translator() )->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( false, $outcome['results'][5]['ok'], '拒答判失败' );
ot_assert_same( 'model_refusal', $outcome['results'][5]['code'], '失败码为 model_refusal' );

ot_translator_reset();
// 反例：正常译文里出现「抱歉」不能误判
ot_http_enqueue( 200, ot_openai_body( '{"1":"抱歉，页面未找到"}' ) );

$outcome = ( new Translator() )->translate_chunk( array( 5 => 'Sorry, page not found' ), 'zh-CN', 'en' );

ot_assert_same( true, $outcome['results'][5]['ok'], '含「抱歉」的正常译文不误判为拒答' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：占位符丢失判永久失败' );

ot_translator_reset();
// 模型吞掉了 <strong> 对应的占位符
ot_http_enqueue( 200, ot_openai_body( '{"1":"你好世界"}' ) );

$outcome = ( new Translator() )->translate_chunk(
    array( 5 => '<strong>Hello</strong> world' ),
    'zh-CN',
    'en'
);

ot_assert_same( false, $outcome['results'][5]['ok'], '占位符丢失判失败' );
ot_assert_same( true, $outcome['results'][5]['permanent'], '占位符丢失是永久失败' );
ot_assert_same( 'placeholder_validation_failed', $outcome['results'][5]['code'], '失败码正确' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：整块失败判瞬时' );

ot_translator_reset();
// 不入队任何响应：stub 直接返回 WP_Error，模拟网络层失败
$outcome = ( new Translator() )->translate_chunk(
    array( 5 => 'Hello', 9 => 'World' ),
    'zh-CN',
    'en'
);

ot_assert_same( false, $outcome['results'][5]['ok'], '网络失败判失败' );
ot_assert_same( false, $outcome['results'][5]['permanent'], '网络失败是瞬时，不写空串' );
ot_assert_same( false, $outcome['results'][9]['permanent'], '同批都判瞬时' );
ot_assert_same( 0, count( $outcome['succeeded_sources'] ), '整块失败不计配额' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：所有模型熔断时判瞬时' );

ot_translator_reset();
$health = new Model_Health();
$key    = Model_Identity::key( array( 'provider' => 'openai', 'model' => 'gpt-test' ) );
for ( $i = 0; $i < Model_Health::FAILURE_THRESHOLD; $i++ ) {
    $health->record_failure( $key, 'boom' );
}
$health->flush();

$outcome = ( new Translator() )->translate_chunk( array( 5 => 'Hello' ), 'zh-CN', 'en' );

ot_assert_same( 'circuit_open', $outcome['results'][5]['code'], '熔断时失败码为 circuit_open' );
ot_assert_same( false, $outcome['results'][5]['permanent'], '熔断是瞬时失败' );
ot_assert_same( 0, count( $GLOBALS['ot_http_log'] ), '熔断时不发起请求' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：直通条目不消耗模型' );

ot_translator_reset();
$outcome = ( new Translator() )->translate_chunk(
    array(
        5 => 'https://example.com/page',
        9 => 'hello@example.com',
    ),
    'zh-CN',
    'en'
);

ot_assert_same( true, $outcome['results'][5]['ok'], 'URL 直通成功' );
ot_assert_same( 'https://example.com/page', $outcome['results'][5]['text'], 'URL 原样返回' );
ot_assert_same( 'hello@example.com', $outcome['results'][9]['text'], '邮箱原样返回' );
ot_assert_same( 0, count( $GLOBALS['ot_http_log'] ), '全直通时不发起请求' );

// ---------------------------------------------------------------------------

ot_test_group( 'Translator：未配置模型时不发请求' );

ot_translator_reset();
unset( $GLOBALS['ot_test_options']['opentranslation_models'] );

$outcome = ( new Translator() )->translate_chunk( array( 5 => 'Hello' ), 'zh-CN', 'en' );

ot_assert_same( 'no_models', $outcome['results'][5]['code'], '无模型时失败码为 no_models' );
ot_assert_same( 0, count( $GLOBALS['ot_http_log'] ), '无模型时不发起请求' );

ot_translator_reset();
