<?php
use OpenTranslation\Glossary;
use OpenTranslation\Protector;

function ot_term( $source, $target = null, $over = array() ) {
    return array_merge( array(
        'source' => $source, 'target' => null === $target ? $source : $target,
        'language' => '', 'case_sensitive' => true, 'whole_word' => true, 'note' => '',
    ), $over );
}

function ot_roundtrip( $text, array $terms, $model_echo = null ) {
    $p = new Protector( $terms );
    $protected = $p->protect( $text );
    $raw = null === $model_echo ? $protected : $model_echo( $protected );
    if ( true !== $p->validate( $raw ) ) {
        return 'VALIDATE_FAILED';
    }
    return $p->restore( $raw );
}

ot_test_group( 'Glossary：空表不改变行为' );

ot_assert_same( 'Hello <b>world</b>', ot_roundtrip( 'Hello <b>world</b>', array() ), '空表原样往返' );
$p = new Protector( array() );
ot_assert_same( 'Hello world', $p->protect( 'Hello world' ), '空表无占位符' );

ot_test_group( 'Glossary：不翻译与固定译法' );

$t = array( ot_term( 'WFS-B11A-GD-FM' ) );
$p = new Protector( $t );
ot_assert_same( 'Sensor <protect-1> spare parts', $p->protect( 'Sensor WFS-B11A-GD-FM spare parts' ), '型号被占位' );
ot_assert_same( '传感器 WFS-B11A-GD-FM 备件', $p->restore( '传感器 <protect-1> 备件' ), '还原为原样' );

$t = array( ot_term( 'sensor', '传感器', array( 'case_sensitive' => false ) ) );
ot_assert_same( 'The 传感器 measures flow', ot_roundtrip( 'The sensor measures flow', $t ), '固定译法还原' );

ot_test_group( 'Glossary：大小写与全词' );

$t = array( ot_term( 'sensor', '传感器' ) );  // case_sensitive=true
ot_assert_same( 'Sensor', ot_roundtrip( 'Sensor', $t ), '大小写敏感时 Sensor 不命中' );
ot_assert_same( 'sensors', ot_roundtrip( 'sensors', $t ), '全词时 sensors 不命中' );

$t = array( ot_term( 'sensor', '传感器', array( 'whole_word' => false ) ) );
ot_assert_same( '传感器s', ot_roundtrip( 'sensors', $t ), '关闭全词时命中前缀' );

$t = array( ot_term( 'LED', 'LED' ) );
ot_assert_same( 'LED灯', ot_roundtrip( 'LED灯', $t ), '全词边界对 CJK 邻接生效（字母后接汉字视为词尾）' );
ot_assert_same( 'LEDs', ot_roundtrip( 'LEDs', $t ), 'LEDs 不命中 LED' );

ot_test_group( 'Glossary：长优先与非重入' );

$t = array( ot_term( 'LED', 'LED' ), ot_term( 'LED Cabinet Light', 'LED 柜灯' ) );
$p = new Protector( $t );
ot_assert_same( '<protect-1> here', $p->protect( 'LED Cabinet Light here' ), '长术语整体命中，不被 LED 拆开' );
ot_assert_same( 'LED 柜灯 here', $p->restore( '<protect-1> here' ), '还原长术语' );

$t = array( ot_term( 'A', 'contains B' ), ot_term( 'B', 'X' ) );
ot_assert_same( 'contains B', ot_roundtrip( 'A', $t ), 'target 含另一条 source 时不二次替换' );

ot_test_group( 'Glossary：与既有保护模式的顺序' );

$t = array( ot_term( 'sensor', '传感器', array( 'case_sensitive' => false ) ) );
ot_assert_same( 'See https://x.com/sensor now', ot_roundtrip( 'See https://x.com/sensor now', $t ), 'URL 内的术语不被替换' );
ot_assert_same( '<a href="sensor">传感器</a>', ot_roundtrip( '<a href="sensor">sensor</a>', $t ), '标签属性内不替换、文本节点替换' );

ot_test_group( 'Glossary：按语言筛选与排序' );

$all = array( ot_term( 'a', 'x', array( 'language' => 'zh_CN' ) ), ot_term( 'bb', 'y' ), ot_term( 'c', 'z', array( 'language' => 'ru_RU' ) ) );
$zh = Glossary::for_language( 'zh_CN', $all );
ot_assert_same( array( 'bb', 'a' ), array_column( $zh, 'source' ), 'zh_CN 得到通用 + zh_CN，按长度降序' );
ot_assert_same( array( 'bb' ), array_column( Glossary::for_language( 'de_DE', $all ), 'source' ), '无专属术语的语言只得到通用项' );

ot_test_group( 'Glossary：保存校验' );

ot_assert_true( is_wp_error( Glossary::sanitize_term( ot_term( '1TP1T', 'x' ) ) ), '命中 TP 占位符模式被拒' );
ot_assert_true( is_wp_error( Glossary::sanitize_term( ot_term( 'protect', 'x' ) ) ), 'protect 被拒' );
ot_assert_true( is_wp_error( Glossary::sanitize_term( ot_term( '123', 'x' ) ) ), '纯数字被拒' );
ot_assert_true( is_wp_error( Glossary::sanitize_term( ot_term( '', 'x' ) ) ), '空 source 被拒' );
ot_assert_true( is_wp_error( Glossary::sanitize_term( ot_term( 'a<b', 'x' ) ) ), '含尖括号被拒' );

$ok = Glossary::sanitize_term( ot_term( '  Acme  ', '' ) );
ot_assert_same( 'Acme', $ok['source'], 'source 被 trim' );
ot_assert_same( 'Acme', $ok['target'], 'target 空时回落为 source' );

ot_test_group( 'Glossary：正则构建与字段规范化' );

ot_assert_same( '', Glossary::build_pattern( array() ), '空术语表返回空正则' );
ot_assert_true( is_wp_error( Glossary::sanitize_term( ot_term( 'Acme', 'x', array( 'language' => 'zh cn!' ) ) ) ), '非法语言码被拒' );

$bool_norm = Glossary::sanitize_term( ot_term( 'Acme', 'Acme', array( 'whole_word' => 0, 'case_sensitive' => 1 ) ) );
ot_assert_same( false, $bool_norm['whole_word'], '布尔字段强制转 bool（0 → false）' );
ot_assert_same( true, $bool_norm['case_sensitive'], '布尔字段强制转 bool（1 → true）' );