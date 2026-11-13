<?php
use OpenTranslation\Admin;
use OpenTranslation\Admin_Usage;

ot_test_group( 'Admin：单价经过设置校验后保存' );

$settings_options_before = $GLOBALS['ot_test_options'];
$settings_filters_before = $GLOBALS['ot_test_filters'];
$settings_admin = ( new ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
$settings_admin->register_settings();
$settings_model_key = md5( 'settings-pricing' );
$settings_usage_row = array(
    'model_key'         => $settings_model_key,
    'prompt_tokens'     => 1000000,
    'completion_tokens' => 500000,
);

try {
    $GLOBALS['ot_test_options']['opentranslation_settings'] = array(
        'system_prompt' => '原提示词',
        'plugin_language' => 'zh_CN',
    );
    $settings_input = get_option( 'opentranslation_settings' );
    $settings_input['model_pricing'] = Admin_Usage::sanitize_pricing( array(
        $settings_model_key => array( 'prompt_per_1m' => '0.05', 'completion_per_1m' => '0.2', 'currency' => 'USD' ),
    ) );
    update_option( 'opentranslation_settings', $settings_input );
    $settings_saved = get_option( 'opentranslation_settings' );
    $settings_cost = Admin_Usage::estimate( $settings_usage_row, $settings_saved['model_pricing'] );
    ot_assert_true( null !== $settings_cost && abs( 0.15 - $settings_cost ) < 1e-12, '首次保存单价后重新读取可估算费用' );
    ot_assert_same( 0.00005, $settings_saved['model_pricing'][ $settings_model_key ]['prompt_per_1k'] ?? null, '写入前再次校验不丢失或重复换算单价' );
    ot_assert_same( '原提示词', $settings_saved['system_prompt'], '保存单价保留提示词' );

    // 预置已有价格，单独验证修改与清空，不依赖首次保存是否成功。
    $GLOBALS['ot_test_options']['opentranslation_settings']['model_pricing'] = array(
        $settings_model_key => array( 'prompt_per_1k' => 0.003, 'completion_per_1k' => 0.015, 'currency' => 'USD' ),
    );
    $settings_input = get_option( 'opentranslation_settings' );
    $settings_input['model_pricing'] = Admin_Usage::sanitize_pricing( array(
        $settings_model_key => array( 'prompt_per_1m' => '0.1', 'completion_per_1m' => '0.4', 'currency' => 'USD' ),
    ) );
    update_option( 'opentranslation_settings', $settings_input );
    $settings_saved = get_option( 'opentranslation_settings' );
    $settings_cost = Admin_Usage::estimate( $settings_usage_row, $settings_saved['model_pricing'] );
    ot_assert_true( null !== $settings_cost && abs( 0.3 - $settings_cost ) < 1e-12, '修改已有价格后使用新单价估算' );

    $settings_pricing_before = $settings_saved['model_pricing'];
    update_option( 'opentranslation_settings', array( 'system_prompt' => '新提示词', 'plugin_language' => 'zh_CN' ) );
    $settings_saved = get_option( 'opentranslation_settings' );
    ot_assert_same( $settings_pricing_before, $settings_saved['model_pricing'], '设置页未提交单价时保留已有价格' );
    ot_assert_same( '新提示词', $settings_saved['system_prompt'], '设置页其他字段仍可更新' );

    $settings_input = $settings_saved;
    $settings_input['model_pricing'] = array();
    update_option( 'opentranslation_settings', $settings_input );
    $settings_saved = get_option( 'opentranslation_settings' );
    ot_assert_same( array(), $settings_saved['model_pricing'], '明确清空单价时不恢复旧价格' );
    ot_assert_same( null, Admin_Usage::estimate( $settings_usage_row, $settings_saved['model_pricing'] ), '清空后才显示未配置单价' );
} finally {
    $GLOBALS['ot_test_options'] = $settings_options_before;
    $GLOBALS['ot_test_filters'] = $settings_filters_before;
}
