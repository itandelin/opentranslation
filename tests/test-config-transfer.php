<?php
use OpenTranslation\Config_Transfer;
use OpenTranslation\Model_Identity;

/**
 * 构造一份最小可用的导出数据。
 */
function ot_transfer_payload( array $over = array() ) {
    $base = array(
        'format_version'    => 1,
        'plugin_version'    => '1.0.0',
        'exported_at'       => '2026-09-17T12:00:00Z',
        'site_url'          => 'https://source.test',
        'includes_api_keys' => false,
        'settings'          => array(
            'batch_size'            => 20,
            'cron_interval'         => 1,
            'rate_limit_per_minute' => 20,
            'system_prompt'         => 'prompt',
            'plugin_language'       => 'zh_CN',
            'scope'                 => array(),
            'model_pricing'         => array(),
        ),
        'models'            => array(
            array(
                'provider'    => 'openai',
                'model'       => 'agnes-3.0-flash',
                'base_url'    => 'https://cpa.tandelin.cn/v1/',
                'priority'    => 10,
                'temperature' => 0.3,
                'max_tokens'  => 0,
                'api_key'     => '',
            ),
        ),
        'glossary'          => array(),
    );
    return array_merge( $base, $over );
}

function ot_transfer_model( array $over = array() ) {
    return array_merge( array(
        'provider'    => 'openai',
        'model'       => 'agnes-3.0-flash',
        'base_url'    => 'https://cpa.tandelin.cn/v1/',
        'priority'    => 10,
        'temperature' => 0.3,
        'max_tokens'  => 0,
        'api_key'     => '',
    ), $over );
}

ot_test_group( 'Config_Transfer：format_version 严格等值' );

$bad_version = Config_Transfer::validate( ot_transfer_payload( array( 'format_version' => 2 ) ), array(), array( 'zh_CN' ) );
ot_assert_same( false, $bad_version['ok'], 'format_version=2 被拒' );
ot_assert_true( false !== strpos( $bad_version['reason'], '2' ), '拒绝原因含实际版本号' );

$no_version = ot_transfer_payload();
unset( $no_version['format_version'] );
ot_assert_same( false, Config_Transfer::validate( $no_version, array(), array( 'zh_CN' ) )['ok'], '缺 format_version 被拒' );

ot_assert_same( false, Config_Transfer::validate( array(), array(), array( 'zh_CN' ) )['ok'], '空数组被拒' );

ot_test_group( 'Config_Transfer：合法文件通过' );

$ok = Config_Transfer::validate( ot_transfer_payload(), array(), array( 'zh_CN' ) );
ot_assert_true( $ok['ok'], '合法文件通过' );
ot_assert_same( 1, count( $ok['normalized']['models'] ), '保留 1 个模型' );
ot_assert_same( array(), $ok['skipped'], '无跳过项' );
ot_assert_same( 'https://source.test', $ok['summary']['site_url'], '摘要含来源站点' );

ot_test_group( 'Config_Transfer：内网 base_url 的模型被跳过' );

$with_internal = Config_Transfer::validate(
    ot_transfer_payload( array( 'models' => array(
        ot_transfer_model( array( 'base_url' => 'http://127.0.0.1/v1/', 'model' => 'evil' ) ),
        ot_transfer_model(),
    ) ) ),
    array(),
    array( 'zh_CN' )
);
ot_assert_true( $with_internal['ok'], '其余模型仍通过' );
ot_assert_same( 1, count( $with_internal['normalized']['models'] ), '只保留合法模型' );
ot_assert_same( 'agnes-3.0-flash', $with_internal['normalized']['models'][0]['model'], '保留的是合法那个' );
ot_assert_same( 1, count( $with_internal['skipped'] ), '跳过 1 项' );
ot_assert_same( 'model', $with_internal['skipped'][0]['type'], '跳过项类型为 model' );
ot_assert_true( '' !== $with_internal['skipped'][0]['reason'], '跳过项带原因' );

ot_test_group( 'Config_Transfer：不含密钥时按 identity 沿用目标站 key' );

$existing = array( ot_transfer_model( array( 'api_key' => 'sk-existing-key-value' ) ) );
$reused   = Config_Transfer::validate( ot_transfer_payload(), $existing, array( 'zh_CN' ) );
ot_assert_same( 'sk-existing-key-value', $reused['normalized']['models'][0]['api_key'], '同 identity 沿用已有 key' );
ot_assert_same( 0, $reused['summary']['missing_keys'], '沿用成功时不计缺失' );

$unmatched = Config_Transfer::validate(
    ot_transfer_payload( array( 'models' => array( ot_transfer_model( array( 'model' => 'other-model' ) ) ) ) ),
    $existing,
    array( 'zh_CN' )
);
ot_assert_same( '', $unmatched['normalized']['models'][0]['api_key'], '无匹配时 key 为空' );
ot_assert_same( 1, $unmatched['summary']['missing_keys'], '无匹配时计入缺失数' );

ot_test_group( 'Config_Transfer：含密钥时直接采用文件中的 key' );

$with_keys = Config_Transfer::validate(
    ot_transfer_payload( array(
        'includes_api_keys' => true,
        'models'            => array( ot_transfer_model( array( 'api_key' => '  sk-from-file  ' ) ) ),
    ) ),
    array(),
    array( 'zh_CN' )
);
ot_assert_same( 'sk-from-file', $with_keys['normalized']['models'][0]['api_key'], '文件中的 key 被 trim 后采用' );
ot_assert_same( 0, $with_keys['summary']['missing_keys'], '含密钥时不计缺失' );

ot_test_group( 'Config_Transfer：术语走 Glossary 校验' );

$terms = Config_Transfer::validate(
    ot_transfer_payload( array( 'glossary' => array(
        array( 'source' => '1TP1T', 'target' => 'x' ),
        array( 'source' => 'EliteLUME', 'target' => '' ),
    ) ) ),
    array(),
    array( 'zh_CN' )
);
ot_assert_same( 1, count( $terms['normalized']['glossary'] ), '非法术语被剔除' );
ot_assert_same( 'EliteLUME', $terms['normalized']['glossary'][0]['source'], '保留合法术语' );
ot_assert_same( 'EliteLUME', $terms['normalized']['glossary'][0]['target'], 'target 空回落为 source' );
ot_assert_same( 'term', $terms['skipped'][0]['type'], '跳过项类型为 term' );

ot_test_group( 'Config_Transfer：settings 白名单与夹取' );

$settings = Config_Transfer::validate(
    ot_transfer_payload( array( 'settings' => array(
        'batch_size'         => 7,
        'system_prompt'      => 'hello',
        'plugin_language'    => 'zh_CN',
        'disabled_languages' => array( 'ru_RU' ),
        'evil'               => 'x',
    ) ) ),
    array(),
    array( 'zh_CN' )
);
$keys = array_keys( $settings['normalized']['settings'] );
sort( $keys );
ot_assert_same( false, in_array( 'disabled_languages', $keys, true ), 'disabled_languages 不被导入' );
ot_assert_same( false, in_array( 'evil', $keys, true ), '未知键被丢弃' );
ot_assert_same( 7, $settings['normalized']['settings']['batch_size'], 'batch_size 被保留' );
ot_assert_same( 'hello', $settings['normalized']['settings']['system_prompt'], 'system_prompt 被保留' );

ot_test_group( 'Config_Transfer：temperature 夹取到 0-2' );

$temps = Config_Transfer::validate(
    ot_transfer_payload( array( 'models' => array(
        ot_transfer_model( array( 'temperature' => 9.5 ) ),
    ) ) ),
    array(),
    array( 'zh_CN' )
);
ot_assert_same( 2.0, $temps['normalized']['models'][0]['temperature'], 'temperature 超界被夹到 2' );

ot_test_group( 'Config_Transfer：scope 走目标站语言列表' );

$scoped = Config_Transfer::validate(
    ot_transfer_payload( array( 'settings' => array(
        'scope' => array(
            'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ) ),
            'de_DE' => array( 'mode' => 'include', 'buckets' => array( 'page' ) ),
        ),
    ) ) ),
    array(),
    array( 'zh_CN' )
);
$scope_out = $scoped['normalized']['settings']['scope'];
ot_assert_same( array( 'zh_CN' ), array_keys( $scope_out ), '只保留目标站存在的语言' );
ot_assert_same( 'include', $scope_out['zh_CN']['mode'], 'zh_CN 范围被保留' );

ot_test_group( 'Config_Transfer：摘要计数' );

$summary = Config_Transfer::validate(
    ot_transfer_payload( array(
        'glossary' => array( array( 'source' => 'Acme', 'target' => '' ) ),
    ) ),
    array(),
    array( 'zh_CN' )
);
ot_assert_same( 1, $summary['summary']['models'], '摘要模型数' );
ot_assert_same( 1, $summary['summary']['glossary'], '摘要术语数' );
ot_assert_same( false, $summary['summary']['includes_api_keys'], '摘要记录是否含密钥' );

ot_test_group( 'Config_Transfer：如实记录每条模型的密钥来源' );

$src_reused = Config_Transfer::validate( ot_transfer_payload(), $existing, array( 'zh_CN' ) );
ot_assert_same( array( 'reused' ), $src_reused['key_sources'], '沿用本站密钥记为 reused' );

$src_missing = Config_Transfer::validate(
    ot_transfer_payload( array( 'models' => array( ot_transfer_model( array( 'model' => 'other-model' ) ) ) ) ),
    $existing,
    array( 'zh_CN' )
);
ot_assert_same( array( 'missing' ), $src_missing['key_sources'], '无匹配记为 missing' );

$src_file = Config_Transfer::validate(
    ot_transfer_payload( array(
        'includes_api_keys' => true,
        'models'            => array( ot_transfer_model( array( 'api_key' => 'sk-from-file' ) ) ),
    ) ),
    array(),
    array( 'zh_CN' )
);
ot_assert_same( array( 'from_file' ), $src_file['key_sources'], '文件提供记为 from_file' );

// 边界：文件声明含密钥，但该条为空，实际沿用了本站密钥。
// 只靠 includes_api_keys 反推会误标成 from_file。
$src_edge = Config_Transfer::validate(
    ot_transfer_payload( array(
        'includes_api_keys' => true,
        'models'            => array( ot_transfer_model( array( 'api_key' => '' ) ) ),
    ) ),
    $existing,
    array( 'zh_CN' )
);
ot_assert_same( 'sk-existing-key-value', $src_edge['normalized']['models'][0]['api_key'], '该条实际沿用本站密钥' );
ot_assert_same( array( 'reused' ), $src_edge['key_sources'], '声明含密钥但该条为空时记为 reused 而非 from_file' );

ot_test_group( 'Config_Transfer：key_sources 与 models 同序' );

$src_aligned = Config_Transfer::validate(
    ot_transfer_payload( array( 'models' => array(
        ot_transfer_model( array( 'base_url' => 'http://127.0.0.1/v1/', 'model' => 'evil' ) ),
        ot_transfer_model(),
    ) ) ),
    $existing,
    array( 'zh_CN' )
);
ot_assert_same( 1, count( $src_aligned['key_sources'] ), '被跳过的模型不占 key_sources 位置' );
ot_assert_same( array( 'reused' ), $src_aligned['key_sources'], '跳过后仍与保留的模型对齐' );

ot_assert_same( array(), Config_Transfer::validate( array(), array(), array( 'zh_CN' ) )['skipped'], '拒绝时 skipped 为空数组' );
