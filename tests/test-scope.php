<?php
use OpenTranslation\Scope;

function ot_scope_set( $configs ) {
    $GLOBALS['ot_test_options']['opentranslation_settings'] = array( 'scope' => $configs );
}
function ot_scope_unset() {
    unset( $GLOBALS['ot_test_options']['opentranslation_settings'] );
}

ot_test_group( 'Scope：sanitize 规范化' );

$warnings = array();
$out = Scope::sanitize( array(
    'zh_CN' => array( 'mode' => 'bogus', 'buckets' => array( 'page' ) ),
    'ru_RU' => array( 'mode' => 'include', 'buckets' => array( 'prod;x', 'page', '__unlinked__' ), 'published_only' => true ),
), array( 'zh_CN', 'ru_RU' ), $warnings );

ot_assert_same( 'all', $out['zh_CN']['mode'], '非法 mode 回落 all' );
ot_assert_same( true, in_array( 'page', $out['ru_RU']['buckets'], true ), '合法桶保留' );
ot_assert_same( true, in_array( Scope::UNLINKED, $out['ru_RU']['buckets'], true ), '未关联桶保留' );
ot_assert_same( true, ! in_array( 'prod;x', $out['ru_RU']['buckets'], true ), '含非法字符的桶被丢弃' );

$warnings2 = array();
$out2 = Scope::sanitize( array(
    'zh_CN' => array( 'mode' => 'include', 'buckets' => array( ';;;' ) ),
), array( 'zh_CN' ), $warnings2 );
ot_assert_same( 'all', $out2['zh_CN']['mode'], 'include 空桶回落 all' );
ot_assert_same( true, ! empty( $warnings2 ), '空桶回落产生告警' );

$warnings3 = array();
$out3 = Scope::sanitize( array(
    'zh_CN' => array( 'mode' => 'exclude', 'buckets' => array( 'page' ), 'published_only' => true ),
), array( 'zh_CN' ), $warnings3 );
ot_assert_same( false, $out3['zh_CN']['published_only'], 'exclude 清掉 published_only' );

$warnings4 = array();
$out4 = Scope::sanitize( array(
    'zh_CN' => array( 'mode' => 'exclude' ),
), array( 'zh_CN' ), $warnings4 );
ot_assert_same( 'all', $out4['zh_CN']['mode'], 'exclude 无桶回落 all' );
ot_assert_same( true, ! empty( $warnings4 ), 'exclude 无桶产生告警' );

ot_test_group( 'Scope：get 规范化结构' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ) ) ) );
$g = Scope::get( 'zh_CN' );
ot_assert_same( 'include', $g['mode'], 'mode 保留' );
ot_assert_same( array( 'page' ), $g['buckets'], 'buckets 保留' );
ot_assert_same( false, $g['published_only'], '缺省 published_only=false' );

ot_scope_unset();
ot_assert_same( 'all', Scope::get( 'zh_CN' )['mode'], '未配置默认 mode=all' );
ot_assert_same( array( 'mode' => 'all', 'buckets' => array(), 'published_only' => false ), Scope::get( 'de_DE' ), '未配置语言返回默认结构' );

ot_test_group( 'Scope：allow_for_current_page 不干预的场景' );

// 当前渲染 page 单篇，语言 zh_CN
ot_wp_page_set( array( 'singular' => true, 'post_type' => 'page' ) );
$GLOBALS['TRP_LANGUAGE'] = 'zh_CN';

// 别人已否决：即使范围命中也不翻案
ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ) ) ) );
ot_assert_same( false, Scope::allow_for_current_page( false, 'Hello' ), '$allow=false 原样返回' );

// mode=all：原样返回传入值
ot_scope_set( array( 'zh_CN' => array( 'mode' => 'all' ) ) );
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), 'mode=all 原样返回 true' );

// 未配置该语言（默认 all）
ot_scope_unset();
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), '未配置 scope 原样返回 true' );

// 无 $TRP_LANGUAGE：不干预
ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'product' ) ) ) );
unset( $GLOBALS['TRP_LANGUAGE'] );
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), '无 TRP_LANGUAGE 原样返回 true' );
$GLOBALS['TRP_LANGUAGE'] = 'zh_CN';

ot_test_group( 'Scope：allow_for_current_page include/exclude' );

ot_wp_page_set( array( 'singular' => true, 'post_type' => 'page' ) );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page', 'product' ) ) ) );
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), 'include 命中当前 post_type → true' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'product' ) ) ) );
ot_assert_same( false, Scope::allow_for_current_page( true, 'Hello' ), 'include 未命中 → false' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'exclude', 'buckets' => array( 'page' ) ) ) );
ot_assert_same( false, Scope::allow_for_current_page( true, 'Hello' ), 'exclude 命中 → false' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'exclude', 'buckets' => array( 'product' ) ) ) );
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), 'exclude 未命中 → true' );

ot_test_group( 'Scope：allow_for_current_page 未关联桶' );

// 归档/首页/搜索/404 等非单篇页面归入 __unlinked__
ot_wp_page_set( array( 'singular' => false ) );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( Scope::UNLINKED ) ) ) );
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), '非单篇页面命中未关联桶 → true' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ) ) ) );
ot_assert_same( false, Scope::allow_for_current_page( true, 'Hello' ), '非单篇页面不属于 page 桶 → false' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'exclude', 'buckets' => array( Scope::UNLINKED ) ) ) );
ot_assert_same( false, Scope::allow_for_current_page( true, 'Hello' ), 'exclude 未关联桶 → false' );

// 主查询未就绪（后台、cron）同样归未关联桶
ot_wp_page_set( array( 'wp_done' => 0, 'singular' => true, 'post_type' => 'page' ) );
ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ) ) ) );
ot_assert_same( false, Scope::allow_for_current_page( true, 'Hello' ), '主查询未就绪时不按 post_type 判定' );

ot_test_group( 'Scope：allow_for_current_page published_only' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ), 'published_only' => true ) ) );

ot_wp_page_set( array( 'singular' => true, 'post_type' => 'page', 'post_status' => 'publish' ) );
ot_assert_same( true, Scope::allow_for_current_page( true, 'Hello' ), 'published_only：已发布单篇 → true' );

ot_wp_page_set( array( 'singular' => true, 'post_type' => 'page', 'post_status' => 'draft' ) );
ot_assert_same( false, Scope::allow_for_current_page( true, 'Hello' ), 'published_only：草稿单篇 → false' );

// 清理全局状态，避免影响后续测试文件
unset( $GLOBALS['TRP_LANGUAGE'] );
ot_wp_page_set();
ot_scope_unset();
