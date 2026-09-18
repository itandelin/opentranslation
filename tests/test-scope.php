<?php
use OpenTranslation\Scope;

function ot_scope_set( $configs ) {
    $GLOBALS['ot_test_options']['opentranslation_settings'] = array( 'scope' => $configs );
}
function ot_scope_unset() {
    unset( $GLOBALS['ot_test_options']['opentranslation_settings'] );
}

ot_test_group( 'Scope：未配置与 all' );

ot_scope_unset();
ot_assert_same( '', Scope::sql_where( 'zh_CN' ), '未配置返回空片段' );
ot_assert_same( 'all', Scope::get( 'zh_CN' )['mode'], '未配置默认 mode=all' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'all' ) ) );
ot_assert_same( '', Scope::sql_where( 'zh_CN' ), 'mode=all 返回空片段' );

ot_test_group( 'Scope：include 构建' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page', 'product' ), 'published_only' => false ) ) );
$sql = Scope::sql_where( 'zh_CN' );
ot_assert_same( false, '' === $sql, 'include 有片段' );
ot_assert_same( true, false !== strpos( $sql, "p.post_type IN ('page','product')" ), '含两个 post_type IN' );
ot_assert_same( true, false !== strpos( $sql, 'm.original_id' ), 'IN 子查询取 original_id' );
ot_assert_same( true, false === strpos( $sql, 'NOT IN' ), '不含 NOT IN' );

ot_test_group( 'Scope：include 含 unlinked' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page', Scope::UNLINKED ) ) ) );
$sql = Scope::sql_where( 'zh_CN' );
ot_assert_same( true, false !== strpos( $sql, 'd.original_id NOT IN' ), '含 OR d.original_id NOT IN' );
ot_assert_same( true, false !== strpos( $sql, 'OR d.original_id' ), '未关联桶用 OR 连接' );

ot_test_group( 'Scope：exclude 构建' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'exclude', 'buckets' => array( 'product' ), 'published_only' => true ) ) );
$sql = Scope::sql_where( 'zh_CN' );
ot_assert_same( true, false !== strpos( $sql, 'd.original_id NOT IN' ), 'exclude 用 NOT IN' );
ot_assert_same( true, false === strpos( $sql, 'p.post_status' ), 'exclude 忽略 published_only（不出现 publish）' );

ot_test_group( 'Scope：exclude 含 unlinked' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'exclude', 'buckets' => array( Scope::UNLINKED ) ) ) );
$sql = Scope::sql_where( 'zh_CN' );
ot_assert_same( true, false !== strpos( $sql, 'd.original_id IN ( SELECT original_id FROM' ), '排除未关联=要求已关联' );

ot_test_group( 'Scope：published_only' );

ot_scope_set( array( 'zh_CN' => array( 'mode' => 'include', 'buckets' => array( 'page' ), 'published_only' => true ) ) );
ot_assert_same( true, false !== strpos( Scope::sql_where( 'zh_CN' ), "p.post_status = 'publish'" ), 'include 含 publish 过滤' );

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
ot_assert_same( array( 'mode' => 'all', 'buckets' => array(), 'published_only' => false ), Scope::get( 'de_DE' ), '未配置语言返回默认结构' );