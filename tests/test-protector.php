<?php
use OpenTranslation\Protector;

ot_test_group( 'Protector：单条目保护与还原' );

$p = new Protector();
$protected = $p->protect( '<a href="/cart">Buy</a> now' );
ot_assert_same( '<protect-1>Buy<protect-2> now', $protected, 'HTML 标签被替换为占位符' );
ot_assert_same( '<a href="/cart">Buy</a> now', $p->restore( $protected ), '还原后与原文一致' );

ot_test_group( 'Protector：每条目独立实例互不干扰（P0-1 回归）' );

// 模拟 Translator 的真实用法：一批多条各含不同 HTML
$items = array(
    '<a href="/cart">Buy</a> now',
    '<b>Hello</b>',
    'Price: <span class="p">100</span> USD',
);

// 正确做法：每条一个 Protector
$protectors = array();
$protected_texts = array();
foreach ( $items as $i => $text ) {
    $protectors[ $i ] = new Protector();
    $protected_texts[ $i ] = $protectors[ $i ]->protect( $text );
}

// 模拟模型原样返回占位符（理想情况）
foreach ( $items as $i => $original ) {
    $restored = $protectors[ $i ]->restore( $protected_texts[ $i ] );
    ot_assert_same( $original, $restored, "第 {$i} 条独立还原正确" );
}

ot_test_group( 'Protector：validate 检出缺失占位符' );

$p2 = new Protector();
$prot = $p2->protect( '<b>Hi</b>' );
ot_assert_same( true, $p2->validate( $prot ), '占位符齐全时返回 true' );

$missing = $p2->validate( '你好' );
ot_assert_true( is_array( $missing ), '占位符缺失时返回数组' );
ot_assert_same( 2, count( $missing ), '缺失 2 个占位符' );

ot_test_group( 'Protector：最后一条为纯文本不影响前面条目（线上真实失效路径）' );

// 这组复现线上 1548 条污染的根因：
// 共享 Protector 时，最后一条纯文本会把 tokens 清空，
// 导致 restore() 在 :42-44 短路，前面条目的占位符全部不被还原。
$batch = array(
    'News&amp;Blog',                 // 含 HTML 实体，有 token
    'Partner &#038; Distributor',    // 含 HTML 实体，有 token
    'Simple plain text',             // 纯文本，无 token —— 关键
);

$batch_protectors = array();
$batch_protected  = array();
foreach ( $batch as $i => $text ) {
    $batch_protectors[ $i ] = new Protector();
    $batch_protected[ $i ]  = $batch_protectors[ $i ]->protect( $text );
}

ot_assert_same( '新闻&amp;博客', $batch_protectors[0]->restore( '新闻<protect-1>博客' ), '第 1 条实体正确还原' );
ot_assert_same( '合作伙伴 &#038; 经销商', $batch_protectors[1]->restore( '合作伙伴 <protect-1> 经销商' ), '第 2 条实体正确还原' );
ot_assert_same( '简单纯文本', $batch_protectors[2]->restore( '简单纯文本' ), '第 3 条纯文本原样返回' );

ot_test_group( 'Protector：TP 自身占位符（P0-3 回归，此时应失败）' );

$p3 = new Protector();
$prot3 = $p3->protect( 'Total 1TP1T off' );
ot_assert_same( 'Total <protect-1> off', $prot3, 'TP 的 1TPnT 占位符被保护' );
