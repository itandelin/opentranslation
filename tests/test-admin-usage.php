<?php
use OpenTranslation\Admin_Usage;

ot_test_group( 'Admin_Usage：每百万 Token 单价' );

$usage_model_key = md5( 'usage-pricing' );
$usage_row = array(
    'model_key'         => $usage_model_key,
    'prompt_tokens'     => 1000000,
    'completion_tokens' => 500000,
);
$usage_pricing = Admin_Usage::sanitize_pricing( array(
    $usage_model_key => array(
        'prompt_per_1m'     => '0.05',
        'completion_per_1m' => '0.2',
        'currency'          => 'usd',
    ),
) );
$usage_cost = Admin_Usage::estimate( $usage_row, $usage_pricing );
ot_assert_true( null !== $usage_cost && abs( 0.15 - $usage_cost ) < 1e-12, '百万输入加五十万输出按所填单价计费' );
ot_assert_same( 'USD', $usage_pricing[ $usage_model_key ]['currency'] ?? null, '保存百万单价时保留币种规范化' );

$usage_small_pricing = Admin_Usage::sanitize_pricing( array(
    $usage_model_key => array(
        'prompt_per_1m'     => '0.00005',
        'completion_per_1m' => '0',
    ),
) );
$usage_small_cost = Admin_Usage::estimate( $usage_row, $usage_small_pricing );
ot_assert_true( null !== $usage_small_cost && abs( 0.00005 - $usage_small_cost ) < 1e-12, '小于 0.0001 的百万单价仍保留精度' );

ot_test_group( 'Admin_Usage：已有单价兼容' );

$usage_legacy_pricing = array(
    $usage_model_key => array(
        'prompt_per_1k'     => 0.003,
        'completion_per_1k' => 0.015,
        'currency'          => 'USD',
    ),
);
ot_assert_same( 10.5, Admin_Usage::estimate( $usage_row, $usage_legacy_pricing ), '已有每千单价的估算费用保持不变' );
ot_assert_same( 10.5, Admin_Usage::estimate( $usage_row, Admin_Usage::sanitize_pricing( $usage_legacy_pricing ) ), '旧页面提交的每千单价仍可保存' );
ot_assert_same( $usage_pricing, Admin_Usage::sanitize_pricing( $usage_pricing ), '再次规范化不会重复换算已保存单价' );
ot_assert_same( null, Admin_Usage::estimate( $usage_row, array() ), '未配置价格仍不估算费用' );

ot_test_group( 'Admin_Usage：百万单价输入边界' );

ot_assert_same( array(), Admin_Usage::sanitize_pricing( array(
    $usage_model_key => array( 'prompt_per_1m' => '', 'completion_per_1m' => '0' ),
) ), '空白与全零单价仍视为未配置' );
ot_assert_same( array(), Admin_Usage::sanitize_pricing( array(
    'invalid-key' => array( 'prompt_per_1m' => '0.05' ),
) ), '非法模型标识不保存价格' );

$usage_clamped = Admin_Usage::sanitize_pricing( array(
    $usage_model_key => array( 'prompt_per_1m' => '-1', 'completion_per_1m' => '0.2' ),
) );
$usage_clamped_cost = Admin_Usage::estimate( $usage_row, $usage_clamped );
ot_assert_true( null !== $usage_clamped_cost && abs( 0.1 - $usage_clamped_cost ) < 1e-12, '负单价归零且保留有效的输出单价' );
