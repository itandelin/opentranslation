<?php
use OpenTranslation\Model_Health;
use OpenTranslation\Model_Identity;

$K = 'abc';

function ot_health_fresh() {
    $GLOBALS['ot_test_options'] = array();
    return new Model_Health();
}

ot_test_group( 'Model_Health：阈值前可用' );

$h = ot_health_fresh();
ot_assert_true( $h->is_available( $K, 1000 ), '新模型可用' );
$h->record_failure( $K, 'HTTP 524', 1000 );
$h->record_failure( $K, 'HTTP 524', 1001 );
ot_assert_true( $h->is_available( $K, 1002 ), '2 次失败仍可用' );

ot_test_group( 'Model_Health：第 3 次失败熔断 300 秒' );

$h->record_failure( $K, 'HTTP 524', 1002 );
ot_assert_same( false, $h->is_available( $K, 1003 ), '熔断中不可用' );
ot_assert_same( 1302, $h->state( $K )['open_until'], 'open_until = 1002 + 300' );
ot_assert_same( false, $h->is_available( $K, 1301 ), '到期前 1 秒仍不可用' );

ot_test_group( 'Model_Health：半开探测' );

ot_assert_true( $h->is_available( $K, 1302 ), '到期后放行一次' );
ot_assert_true( $h->state( $K )['half_open'], '进入半开' );
$h->record_success( $K, 1303 );
$s = $h->state( $K );
ot_assert_same( 0, $s['consecutive_failures'], '成功后连续失败清零' );
ot_assert_same( 0, $s['open_until'], '成功后关闭熔断' );
ot_assert_same( false, $s['half_open'], '成功后退出半开' );
ot_assert_same( 1, $s['total_success'], '累计成功 +1' );

ot_test_group( 'Model_Health：半开失败退避加倍' );

$h = ot_health_fresh();
foreach ( array( 1, 2, 3 ) as $i ) {
    $h->record_failure( $K, 'x', 1000 + $i );
}
$h->is_available( $K, 1400 );                 // 半开
$h->record_failure( $K, 'x', 1400 );
ot_assert_same( 4, $h->state( $K )['consecutive_failures'], '连续失败 4' );
ot_assert_same( 2000, $h->state( $K )['open_until'], '第 4 次 → 600 秒' );
ot_assert_same( 300, Model_Health::open_seconds( 3 ), '3 → 300' );
ot_assert_same( 1200, Model_Health::open_seconds( 5 ), '5 → 1200' );
ot_assert_same( 3600, Model_Health::open_seconds( 9 ), '上限 3600' );
ot_assert_same( 0, Model_Health::open_seconds( 2 ), '阈值前返回 0' );

ot_test_group( 'Model_Health：过滤与全开' );

$h = ot_health_fresh();
$models = array(
    array( 'provider' => 'openai', 'model' => 'a', 'base_url' => '' ),
    array( 'provider' => 'openai', 'model' => 'b', 'base_url' => '' ),
);
$ka = Model_Identity::key( $models[0] );
foreach ( array( 1, 2, 3 ) as $i ) {
    $h->record_failure( $ka, 'x', 1000 );
}
ot_assert_same( array( 'b' ), array_column( $h->filter_available( $models, 1001 ), 'model' ), '熔断的 a 被过滤' );
ot_assert_same( false, $h->all_open( $models, 1001 ), '仍有 b 可用' );

$kb = Model_Identity::key( $models[1] );
foreach ( array( 1, 2, 3 ) as $i ) {
    $h->record_failure( $kb, 'x', 1000 );
}
ot_assert_true( $h->all_open( $models, 1001 ), '全部熔断' );
ot_assert_same( false, ( new Model_Health() )->all_open( array(), 1001 ), '没有配置模型时不算全熔断' );

ot_test_group( 'Model_Health：落盘与重置' );

$h = ot_health_fresh();
$h->record_failure( $K, 'Bearer sk-abcdefghijklmnop', 1000 );
ot_assert_same( array(), $GLOBALS['ot_test_options'], 'flush 前不写 option' );
$h->flush();
ot_assert_same(
    'Bearer [redacted]',
    $GLOBALS['ot_test_options'][ Model_Health::OPTION ][ $K ]['last_error'],
    'last_error 已脱敏'
);
ot_assert_same( 1000, $GLOBALS['ot_test_options'][ Model_Health::OPTION ][ $K ]['last_error_at'], '记录失败时间' );

Model_Health::reset( $K );
ot_assert_same( 0, ( new Model_Health() )->state( $K )['consecutive_failures'], '重置后清零' );
ot_assert_same( 0, Model_Health::open_remaining( $K, 1001 ), '重置后无剩余熔断时间' );

ot_test_group( 'Model_Health：flush 幂等与剩余时间' );

$h = ot_health_fresh();
foreach ( array( 1, 2, 3 ) as $i ) {
    $h->record_failure( $K, 'x', 1000 );
}
$h->flush();
ot_assert_same( false, $h->flush(), '无改动时 flush 不重复写' );
ot_assert_same( 300, Model_Health::open_remaining( $K, 1000 ), '剩余时间 = open_until - now' );
ot_assert_same( 0, Model_Health::open_remaining( $K, 1302 ), '到期后剩余 0' );

$long = str_repeat( 'x', 300 );
$h = ot_health_fresh();
$h->record_failure( $K, $long, 1000 );
$h->flush();
ot_assert_same( 200, mb_strlen( $GLOBALS['ot_test_options'][ Model_Health::OPTION ][ $K ]['last_error'] ), 'last_error 截断到 200 字符' );