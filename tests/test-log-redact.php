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

ot_assert_same(
    '{"x-api-key":"[redacted]"}',
    Log::redact( '{"x-api-key":"abcdef123456789012345"}' ),
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

ot_assert_same( 'debug', Log::level_for( 'scheduler_run' ), '调度心跳为 debug 级' );
ot_assert_same( 'error', Log::level_for( 'failed' ), '失败为 error 级' );
ot_assert_same( 'warn', Log::level_for( 'retry' ), '重试为 warn 级' );
ot_assert_same( 'warn', Log::level_for( 'model_fallback' ), '模型降级为 warn 级' );
ot_assert_same( 'info', Log::level_for( 'unknown_action' ), '未列出的动作默认 info 级' );
