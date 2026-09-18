<?php
use OpenTranslation\Admin_Transfer;

ot_test_group( 'Admin_Transfer：上传内容解码' );

$too_big = Admin_Transfer::decode_payload( str_repeat( 'x', Admin_Transfer::MAX_UPLOAD_BYTES + 1 ) );
ot_assert_true( is_wp_error( $too_big ), '超过 1MB 被拒' );
ot_assert_same( 'too_large', $too_big->get_error_code(), '超限错误码为 too_large' );

ot_assert_true( is_wp_error( Admin_Transfer::decode_payload( '' ) ), '空内容被拒' );

$bad_json = Admin_Transfer::decode_payload( '{"format_version":' );
ot_assert_true( is_wp_error( $bad_json ), '非法 JSON 被拒' );
ot_assert_same( 'invalid_json', $bad_json->get_error_code(), '非法 JSON 错误码' );

$scalar = Admin_Transfer::decode_payload( '123' );
ot_assert_true( is_wp_error( $scalar ), '顶层非数组被拒' );

$ok = Admin_Transfer::decode_payload( '{"format_version":1,"models":[]}' );
ot_assert_same( false, is_wp_error( $ok ), '合法 JSON 通过' );
ot_assert_same( 1, $ok['format_version'], '解码结果可读' );

ot_test_group( 'Admin_Transfer：预览按记录的来源展示密钥状态' );

// 直接采用 validate() 记录的来源，不从 includes_api_keys 反推
ot_assert_same(
    'from_file',
    Admin_Transfer::key_status( array( 'api_key' => 'sk-x' ), 'from_file' ),
    '记录为 from_file 即显示 from_file'
);
ot_assert_same(
    'reused',
    Admin_Transfer::key_status( array( 'api_key' => 'sk-x' ), 'reused' ),
    '记录为 reused 即显示 reused'
);
ot_assert_same(
    'missing',
    Admin_Transfer::key_status( array( 'api_key' => '' ), 'missing' ),
    '记录为 missing 即显示 missing'
);

ot_test_group( 'Admin_Transfer：无记录时按 api_key 兜底' );

// 升级前存下的预览 transient 没有 key_sources
ot_assert_same(
    'missing',
    Admin_Transfer::key_status( array( 'api_key' => '' ), null ),
    '无记录且 key 为空判为缺失'
);
ot_assert_same(
    'reused',
    Admin_Transfer::key_status( array( 'api_key' => 'sk-x' ), null ),
    '无记录且 key 非空回落为沿用'
);
ot_assert_same(
    'missing',
    Admin_Transfer::key_status( array( 'api_key' => '' ), 'garbage' ),
    '非法记录值走兜底'
);
