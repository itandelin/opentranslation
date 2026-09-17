<?php
use OpenTranslation\Claude_Client;

// 缩短重试延迟，避免测试因 usleep 变慢
add_filter( 'opentranslation_claude_retry_delay_ms', function () { return 100; } );

function ot_claude() {
    return new Claude_Client( array( 'api_key' => 'k', 'model' => 'claude-x', 'max_tokens' => 0 ) );
}
function ot_claude_ok_body( array $arr ) {
    return json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => json_encode( $arr, JSON_UNESCAPED_UNICODE ) ) ) ) );
}

ot_test_group( 'Claude_Client：构造与请求体' );
ot_http_reset();
ot_http_enqueue( 200, ot_claude_ok_body( array( '你好' ) ) );
$c = ot_claude();
$r = $c->translate( array( 'Hello' ), 'zh_CN', 'SYS' );
ot_assert_same( array( '你好' ), $r, '单条正常返回' );
ot_assert_same( 'https://api.anthropic.com/v1/messages', $GLOBALS['ot_http_log'][0]['url'], 'base_url 留空回落官方地址' );
ot_assert_same( 4096, $GLOBALS['ot_http_log'][0]['body']['max_tokens'], 'max_tokens=0 回落 4096' );
ot_assert_same( 'SYS', $GLOBALS['ot_http_log'][0]['body']['system'], 'system prompt 进 body.system' );
ot_assert_same( 1, $c->get_last_request_units(), '单次成功 request_units=1' );

ot_test_group( 'Claude_Client：重试' );
ot_http_reset();
ot_http_enqueue( 524, '' );
ot_http_enqueue( 200, '' );
ot_http_enqueue( 200, ot_claude_ok_body( array( 'A', 'B' ) ) );
$c = ot_claude();
$r = $c->translate( array( 'a', 'b' ), 'zh_CN' );
ot_assert_same( array( 'A', 'B' ), $r, '524 → 空 2xx → 成功，最终返回译文' );
ot_assert_same( 3, count( $GLOBALS['ot_http_log'] ), '共发出 3 次请求' );
ot_assert_same( 3, $c->get_last_request_units(), 'request_units 计入全部尝试' );

ot_http_reset();
ot_http_enqueue( 401, json_encode( array( 'error' => array( 'message' => 'invalid x-api-key' ) ) ) );
$c = ot_claude();
$r = $c->translate( array( 'a', 'b' ), 'zh_CN' );
ot_assert_true( is_wp_error( $r ), '401 不重试直接返回错误' );
ot_assert_same( 'claude_http_error', $r->get_error_code(), '错误码 claude_http_error' );
ot_assert_same( 'Claude endpoint returned HTTP 401. invalid x-api-key', $r->get_error_message(), '消息含状态码与上游 message' );
ot_assert_same( 1, count( $GLOBALS['ot_http_log'] ), '401 只发 1 次请求' );
ot_assert_same( 1, $c->get_last_request_units(), '401 request_units=1' );

ot_http_reset();
ot_http_enqueue( 524, '' );
ot_http_enqueue( 524, '' );
ot_http_enqueue( 524, '' );
$c = ot_claude();
$r = $c->translate( array( 'only' ), 'zh_CN' );
ot_assert_true( is_wp_error( $r ) && 524 === $r->get_error_data( 'status_code' ), '连续 524 达上限后返回错误并带 status_code' );
ot_assert_same( 3, $c->get_last_request_units(), '单条不可切块，3 次尝试后放弃' );

ot_test_group( 'Claude_Client：切块' );
ot_http_reset();
ot_http_enqueue( 200, ot_claude_ok_body( array( 'A', 'B', 'C' ) ) ); // 4 条只回 3 条 → count_mismatch
ot_http_enqueue( 200, ot_claude_ok_body( array( 'A', 'B' ) ) );      // 前半
ot_http_enqueue( 200, ot_claude_ok_body( array( 'C', 'D' ) ) );      // 后半
$c = ot_claude();
$r = $c->translate( array( 'a', 'b', 'c', 'd' ), 'zh_CN' );
ot_assert_same( array( 'A', 'B', 'C', 'D' ), $r, '数量不匹配时对半切块并合并' );
$second_prompt = $GLOBALS['ot_http_log'][1]['body']['messages'][0]['content'];
ot_assert_same( 2, preg_match_all( '/^\d+\. /m', $second_prompt ), '切块后第二次请求只含 2 条' );
ot_assert_same( 3, $c->get_last_request_units(), '切块累计 request_units=3' );

ot_http_reset();
ot_http_enqueue( 200, json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => 'not json at all' ) ) ) ) );
ot_http_enqueue( 200, ot_claude_ok_body( array( 'A' ) ) );
ot_http_enqueue( 200, ot_claude_ok_body( array( 'B' ) ) );
$c = ot_claude();
ot_assert_same( array( 'A', 'B' ), $c->translate( array( 'a', 'b' ), 'zh_CN' ), 'parse_error 触发切块' );

ot_http_reset();
ot_http_enqueue( 200, json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => 'nope' ) ) ) ) );
$c = ot_claude();
$r = $c->translate( array( 'a' ), 'zh_CN' );
ot_assert_true( is_wp_error( $r ) && 'parse_error' === $r->get_error_code(), '单条解析失败不切块，返回 parse_error' );

ot_test_group( 'Claude_Client：解析与 OpenAI 对齐' );
$m = new ReflectionMethod( Claude_Client::class, 'parse_response' );
$m->setAccessible( true );
$c = ot_claude();
ot_assert_same( array( 'x', 'y' ), $m->invoke( $c, "Here you go:\n[\"x\",\"y\"]\nDone." ), '任意位置的 JSON 数组' );
ot_assert_same( array( 'x' ), $m->invoke( $c, "```json\n[\"x\"]\n```" ), '```json 围栏' );
ot_assert_same( array( 'x' ), $m->invoke( $c, "```\n[\"x\"]\n```" ), '裸 ``` 围栏' );
ot_assert_same( array( '甲', '乙' ), $m->invoke( $c, "1. 甲\n2) 乙" ), '编号列表兜底' );
ot_assert_true( is_wp_error( $m->invoke( $c, 'plain text' ) ), '无法解析返回 WP_Error' );

ot_test_group( 'Claude_Client：test_connection 走重试路径' );
ot_http_reset();
ot_http_enqueue( 524, '' );
ot_http_enqueue( 200, ot_claude_ok_body( array( '你好' ) ) );
$d = ot_claude()->test_connection( array( 'Hello' ), 'zh_CN' );
ot_assert_same( true, $d['success'], '重试后 success=true' );
ot_assert_same( 2, count( $d['request_attempts'] ), '诊断含 2 次尝试' );
ot_assert_same( '你好', $d['translated_preview'], '预览为首条译文' );
ot_http_reset();
ot_http_enqueue( 401, json_encode( array( 'error' => array( 'message' => 'bad key' ) ) ) );
$d = ot_claude()->test_connection( array( 'Hello' ), 'zh_CN' );
ot_assert_same( false, $d['success'], '401 时 success=false' );
ot_assert_same( 'Claude endpoint returned HTTP 401. bad key', $d['error'], '401 报状态码而非「空响应」' );
