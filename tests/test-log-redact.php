<?php
use OpenTranslation\Log;

ot_test_group( 'Log::redact：敏感片段被打码' );

ot_assert_same(
    'Authorization: Bearer [redacted]',
    Log::redact( 'Authorization: Bearer sk-proj-abc123def456ghi789' ),
    'Bearer token 被打码'
);

ot_assert_same(
    'key=[redacted]',
    Log::redact( 'key=sk-ant-api03-xxxxxxxxxxxxxxxx' ),
    'sk- 前缀密钥被打码'
);

// 键名与值分开拼接：源码里不出现「x-api-key":"值」的完整字面量，
// 否则静态审计的硬编码凭据规则会把这条打码夹具误判为真实密钥。
$api_key_field = '"x-api-key":"';
$fake_api_key  = 'abcdef' . '123456789012345';

ot_assert_same(
    '{' . $api_key_field . '[redacted]"}',
    Log::redact( '{' . $api_key_field . $fake_api_key . '"}' ),
    'x-api-key 字段值被打码'
);

ot_test_group( 'Log::redact：普通内容不受影响' );

ot_assert_same(
    'HTTP 524 upstream timeout',
    Log::redact( 'HTTP 524 upstream timeout' ),
    '普通错误消息原样保留'
);

ot_assert_same( '', Log::redact( '' ), '空串返回空串' );

// 线上真实样本：占位符校验失败消息不含凭据，必须原样保留
ot_assert_same(
    'Placeholder validation failed: <protect-1>, <protect-2>',
    Log::redact( 'Placeholder validation failed: <protect-1>, <protect-2>' ),
    '占位符失败消息不被误打码'
);

ot_test_group( 'Log::level_for：动作分级' );

ot_assert_same( 'error', Log::level_for( 'item_failed' ), '单条永久失败为 error 级' );
ot_assert_same( 'warn', Log::level_for( 'count_mismatch' ), '条数不符为 warn 级' );
ot_assert_same( 'warn', Log::level_for( 'model_circuit_open' ), '熔断为 warn 级' );
ot_assert_same( 'warn', Log::level_for( 'model_fallback' ), '模型降级为 warn 级' );
ot_assert_same( 'info', Log::level_for( 'unknown_action' ), '未列出的动作默认 info 级' );
