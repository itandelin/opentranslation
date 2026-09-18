<?php
use OpenTranslation\Claude_Client;
use OpenTranslation\OpenAI_Client;

add_filter( 'opentranslation_openai_retry_delay_ms', function () { return 100; } );

function ot_openai() { return new OpenAI_Client( array( 'api_key' => 'k', 'model' => 'm', 'base_url' => '' ) ); }
function ot_openai_body( array $arr, $usage = null ) {
    $b = array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => json_encode( $arr, JSON_UNESCAPED_UNICODE ) ) ) ) );
    if ( null !== $usage ) { $b['usage'] = $usage; }
    return json_encode( $b );
}
function ot_claude_body_u( array $arr, $usage = null ) {
    $b = array( 'content' => array( array( 'type' => 'text', 'text' => json_encode( $arr, JSON_UNESCAPED_UNICODE ) ) ) );
    if ( null !== $usage ) { $b['usage'] = $usage; }
    return json_encode( $b );
}
$zero = array( 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 );

ot_test_group( 'OpenAI_Client：用量归一化' );
ot_http_reset();
ot_http_enqueue( 200, ot_openai_body( array( 'A' ), array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ) ) );
$c = ot_openai(); $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_same( array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ), $c->get_last_usage(), '单次成功 usage 正确' );
ot_http_reset();
ot_http_enqueue( 200, ot_openai_body( array( 'A' ), array( 'prompt_tokens' => 3, 'completion_tokens' => 4 ) ) );
$c = ot_openai(); $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_same( 7, $c->get_last_usage()['total_tokens'], '缺 total 用 p+c 补' );
ot_http_reset();
ot_http_enqueue( 200, ot_openai_body( array( 'A' ) ) );
$c = ot_openai(); $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_same( $zero, $c->get_last_usage(), '缺 usage 字段全 0' );
ot_http_reset();
ot_http_enqueue( 401, json_encode( array( 'error' => array( 'message' => 'bad' ) ) ) );
$c = ot_openai(); $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_same( $zero, $c->get_last_usage(), '失败调用 usage 全 0' );

ot_test_group( 'OpenAI_Client：切块累加与 translate 重置' );
ot_http_reset();
ot_http_enqueue( 200, ot_openai_body( array( 'A', 'B', 'C' ), array( 'prompt_tokens' => 100, 'completion_tokens' => 1, 'total_tokens' => 101 ) ) ); // 4 条回 3 → 切块
ot_http_enqueue( 200, ot_openai_body( array( 'A', 'B' ), array( 'prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12 ) ) );
ot_http_enqueue( 200, ot_openai_body( array( 'C', 'D' ), array( 'prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12 ) ) );
$c = ot_openai(); $r = $c->translate( array( 'a', 'b', 'c', 'd' ), 'zh_CN' );
ot_assert_same( array( 'A', 'B', 'C', 'D' ), $r, '切块成功' );
ot_assert_same( 125, $c->get_last_usage()['total_tokens'], '三次响应 usage 累加（含被丢弃的首次）' );
ot_http_enqueue( 200, ot_openai_body( array( 'X' ), array( 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2 ) ) );
$c->translate( array( 'x' ), 'zh_CN' );
ot_assert_same( 2, $c->get_last_usage()['total_tokens'], '再次 translate 时重置' );

ot_test_group( 'Claude_Client：input/output 归一化' );
ot_http_reset();
ot_http_enqueue( 200, ot_claude_body_u( array( 'A' ), array( 'input_tokens' => 88, 'output_tokens' => 8 ) ) );
$c = new Claude_Client( array( 'api_key' => 'k', 'model' => 'm', 'max_tokens' => 0 ) ); $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_same( array( 'prompt_tokens' => 88, 'completion_tokens' => 8, 'total_tokens' => 96 ), $c->get_last_usage(), 'input→prompt, output→completion, total=和' );
ot_http_reset();
ot_http_enqueue( 200, ot_claude_body_u( array( 'A' ) ) );
$c = new Claude_Client( array( 'api_key' => 'k', 'model' => 'm', 'max_tokens' => 0 ) ); $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_same( $zero, $c->get_last_usage(), '缺 usage 全 0' );

ot_test_group( '接口契约' );
ot_assert_true( ( new ReflectionClass( 'OpenTranslation\\Model_Client' ) )->hasMethod( 'get_last_usage' ), '接口声明 get_last_usage' );
ot_assert_true( ( new ReflectionClass( 'OpenTranslation\\Model_Client' ) )->hasMethod( 'get_last_request_units' ), '接口声明 get_last_request_units' );
