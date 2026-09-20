<?php
use OpenTranslation\Protector;

ot_test_group( 'Protector：单条目保护与还原' );

$p = new Protector();
$protected = $p->protect( '<a href="/cart">Buy</a> now' );
ot_assert_same( '<protect-1>Buy<protect-2> now', $protected, 'HTML 标签被替换为占位符' );
ot_assert_same( '<a href="/cart">Buy</a> now', $p->restore( $protected ), '还原后与原文一致' );

ot_test_group( 'Protector：一批多条各含不同 HTML，每条独立还原' );

// 合约验收标准 1 第一条
$items = array(
    '<a href="/cart">Buy</a> now',
    '<b>Hello</b>',
    'Price: <span class="p">100</span> USD',
);

$protectors       = array();
$protected_texts  = array();
foreach ( $items as $i => $text ) {
    $protectors[ $i ]      = new Protector();
    $protected_texts[ $i ] = $protectors[ $i ]->protect( $text );
}

foreach ( $items as $i => $original ) {
    $restored = $protectors[ $i ]->restore( $protected_texts[ $i ] );
    ot_assert_same( $original, $restored, "第 {$i} 条独立还原正确" );
}

ot_test_group( 'Protector：最后一条为纯文本不影响前面条目（线上真实失效路径）' );

// 复现线上 1548 条污染的根因之一：
// 共享 Protector 时，最后一条纯文本会把 tokens 清空，
// 导致 restore() 短路，前面条目的占位符全部不被还原。
$batch = array(
    'News&amp;Blog',              // 含 HTML 实体，有 token
    'Partner &#038; Distributor', // 含 HTML 实体，有 token
    'Simple plain text',          // 纯文本，无 token —— 关键
);

$batch_protectors = array();
$batch_protected  = array();
foreach ( $batch as $i => $text ) {
    $batch_protectors[ $i ] = new Protector();
    $batch_protected[ $i ]  = $batch_protectors[ $i ]->protect( $text );
}

ot_assert_same( 'News<protect-1>Blog', $batch_protected[0], '第 1 条实体被保护' );
ot_assert_same( 'Simple plain text', $batch_protected[2], '第 3 条纯文本无占位符' );

ot_assert_same( '新闻&amp;博客', $batch_protectors[0]->restore( '新闻<protect-1>博客' ), '第 1 条实体正确还原' );
ot_assert_same( '合作伙伴 &#038; 经销商', $batch_protectors[1]->restore( '合作伙伴 <protect-1> 经销商' ), '第 2 条实体正确还原' );
ot_assert_same( '简单纯文本', $batch_protectors[2]->restore( '简单纯文本' ), '第 3 条纯文本原样返回' );

ot_test_group( 'Protector：validate 校验模型原始响应（restore 之前）' );

$pv = new Protector();
$pv->protect( '<b>Hi</b>' );

ot_assert_same( true, $pv->validate( '<protect-1>你好<protect-2>' ), '占位符齐全时通过' );

$all_missing = $pv->validate( '你好' );
ot_assert_true( is_array( $all_missing ), '占位符全部被吞时判失败' );
ot_assert_same( 2, count( $all_missing ), '报告 2 个问题占位符' );

$one_missing = $pv->validate( '<protect-1>你好' );
ot_assert_true( is_array( $one_missing ), '漏一个占位符时判失败' );
ot_assert_same( array( '<protect-2>' ), $one_missing, '只报告缺失的那一个' );

ot_test_group( 'Protector：串号占位符被检出（线上 WFS 样本）' );

// 原文无可保护内容，token 映射为空，
// 但模型把别条目的占位符串了过来。
$p4 = new Protector();
$p4->protect( 'Water flow sensor WFS-B11A-GD-FM spare parts for boilers' );
ot_assert_same( array(), $p4->get_tokens(), '原文无可保护内容时 token 映射为空' );

$leaked = $p4->validate( '<protect-1> 水流传感器 WFS-B11A-GD-FM 锅炉备件' );
ot_assert_true( is_array( $leaked ), '串号占位符被检出为失败' );
ot_assert_same( array( '<protect-1>' ), $leaked, '报告的正是串进来的那个' );

$p5 = new Protector();
$p5->protect( '<b>Hi</b>' );
$extra = $p5->validate( '<protect-1>你好<protect-2><protect-9>' );
ot_assert_true( is_array( $extra ), '多出无主占位符被检出为失败' );
ot_assert_same( array( '<protect-9>' ), $extra, '只报告多出的那一个' );

ot_test_group( 'Protector：restore 后残留占位符兜底检查' );

ot_assert_same( false, Protector::has_residual_placeholder( '这是干净的译文' ), '干净译文无残留' );
ot_assert_same( false, Protector::has_residual_placeholder( '' ), '空串无残留' );
ot_assert_same( true, Protector::has_residual_placeholder( '<protect-1> 残留了' ), '残留占位符被检出' );
ot_assert_same( true, Protector::has_residual_placeholder( '尾部残留 <protect-42>' ), '任意编号的残留都被检出' );

ot_test_group( 'Protector：完整流水线顺序（validate → restore → 残留检查）' );

$pipe = new Protector();
$src  = 'Buy <b>now</b> &amp; save';
$prot = $pipe->protect( $src );

ot_assert_same( 'Buy <protect-1>now<protect-2> <protect-3> save', $prot, '三处内容被保护' );

// 模型理想返回
$model_ok = '立即<protect-1>购买<protect-2> <protect-3> 省钱';
ot_assert_same( true, $pipe->validate( $model_ok ), '理想响应通过校验' );

$final = $pipe->restore( $model_ok );
ot_assert_same( '立即<b>购买</b> &amp; 省钱', $final, '还原出正确 HTML 与实体' );
ot_assert_same( false, Protector::has_residual_placeholder( $final ), '还原后无残留' );

// 模型吞掉一个占位符 —— 应判失败，而非拼接到末尾
$model_bad = '立即<protect-1>购买<protect-2> 省钱';
ot_assert_true( is_array( $pipe->validate( $model_bad ) ), '吞占位符的响应判失败' );

ot_test_group( 'Protector：TP 自身占位符（Task 3 目标）' );

$p3    = new Protector();
$prot3 = $p3->protect( 'Total 1TP1T off' );
ot_assert_same( 'Total <protect-1> off', $prot3, 'TP 的 1TPnT 占位符被保护' );
ot_assert_same( 'Total 1TP1T off', $p3->restore( $prot3 ), 'TP 占位符正确还原' );

ot_test_group( 'Protector：模型把占位符当标签闭合（线上 4 条落库的缺陷）' );

// 线上实测样本 ru_RU id=6720：
//   原文 DALI Bus Power&amp;Repeater
//   模型 Питание шины DALI<protect-1>Реpeater</protect-1>
// 已知 token 在场，validate 曾判通过；而 </protect-1> 不匹配
// 旧正则 /<protect-\d+>/，restore 也换不掉它，最终污染落库。
$pc = new Protector();
$pc_prot = $pc->protect( 'DALI Bus Power&amp;Repeater' );
ot_assert_same( 'DALI Bus Power<protect-1>Repeater', $pc_prot, '实体被保护为单个占位符' );

$pc_model = 'Питание шины DALI<protect-1>Реpeater</protect-1>';
$pc_result = $pc->validate( $pc_model );
ot_assert_true( is_array( $pc_result ), '闭标签形式被判失败（旧代码此处漏过）' );
ot_assert_same( array( '</protect-1>' ), $pc_result, '报告的正是那个闭标签' );

// 兜底同样必须认得闭标签
ot_assert_same(
    true,
    Protector::has_residual_placeholder( 'Питание шины DALI&amp;Реpeater</protect-1>' ),
    '兜底检查认出闭标签残留'
);
ot_assert_same(
    true,
    Protector::has_residual_placeholder( '开标签残留 <protect-2>' ),
    '兜底检查仍认得开标签残留'
);
ot_assert_same(
    false,
    Protector::has_residual_placeholder( '干净译文，无任何占位符' ),
    '干净译文不误报'
);

// 开闭标签混杂：两者都不属于本条目时都要报
$pc2 = new Protector();
$pc2->protect( '纯文本无可保护内容' );
$pc2_mixed = $pc2->validate( '译文<protect-5>混杂</protect-7>' );
ot_assert_true( is_array( $pc2_mixed ), '开闭混杂的无主占位符被判失败' );
ot_assert_same( 2, count( $pc2_mixed ), '开闭标签各报 1 个' );

// 回归：URL 紧邻 HTML 标签
//
// 旧的 URL 模式 /https?:\/\/[^\s]+/ 不排除 `<`，会把前一轮生成的
// <protect-N> 卷进 URL 形成嵌套 token，restore() 后必然残留，
// 使这类源串永久翻译失败。
ot_test_group( 'Protector：URL 紧邻标签不产生嵌套 token' );

$pu = new Protector();
$pu_protected = $pu->protect( 'visit https://example.com<br/> now' );

ot_assert_same(
    false,
    strpos( $pu_protected, 'https://' ) !== false,
    'URL 已被保护'
);

foreach ( $pu->get_tokens() as $pu_token => $pu_value ) {
    ot_assert_same(
        false,
        Protector::has_residual_placeholder( $pu_value ),
        'token 值内不含嵌套占位符：' . $pu_token
    );
}

// 模型原样回传所有占位符时，还原结果必须干净
ot_assert_same( true, $pu->validate( $pu_protected ), '原样回传通过校验' );
ot_assert_same(
    false,
    Protector::has_residual_placeholder( $pu->restore( $pu_protected ) ),
    '还原后无残留占位符'
);
ot_assert_same(
    'visit https://example.com<br/> now',
    $pu->restore( $pu_protected ),
    '还原结果与原文一致'
);
