<?php
/**
 * 清理被占位符缺陷污染的译文。
 *
 * 用法（WordPress 根目录执行）：
 *   php clean-polluted.php --lang=zh_CN            # 预演
 *   php clean-polluted.php --lang=zh_CN --confirm  # 实际执行
 *
 * 安全设计：
 *   - 必须显式传 --lang，不支持一次清所有语言
 *   - 不传 --confirm 只预演
 *   - 跳过 TP status=2（人工已校对）
 *   - 依赖 scan-polluted.php 生成的 polluted-ids.json
 *
 * 缓存删除采用双条件，缺一不可：
 *   1. translated_text 匹配 </?protect-N>  —— A 类
 *   2. source_text 命中被重置条目的原文    —— C 类（跨条目标签污染，
 *      其缓存行不含 protect 占位符，只按模式删会被漏掉，
 *      而 class-scheduler.php 会把 cached_translation 直接写回 TP，
 *      导致下一轮又把脏数据灌回去）
 */

$wp_load = __DIR__ . '/../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    fwrite( STDERR, "找不到 wp-load.php\n" );
    exit( 1 );
}
require_once $wp_load;

global $wpdb;

$lang    = '';
$confirm = false;
foreach ( $argv as $arg ) {
    if ( 0 === strpos( $arg, '--lang=' ) ) {
        $lang = substr( $arg, 7 );
    }
    if ( '--confirm' === $arg ) {
        $confirm = true;
    }
}

if ( '' === $lang ) {
    fwrite( STDERR, "必须指定 --lang=xx_XX\n" );
    exit( 1 );
}

$id_file = __DIR__ . '/polluted-ids.json';
if ( ! file_exists( $id_file ) ) {
    fwrite( STDERR, "缺少 polluted-ids.json，请先运行 scan-polluted.php\n" );
    exit( 1 );
}

$report = json_decode( file_get_contents( $id_file ), true );
if ( empty( $report[ $lang ]['ids'] ) ) {
    printf( "语言 %s 无待清理条目\n", $lang );
    exit( 0 );
}

$ids   = array_map( 'absint', $report[ $lang ]['ids'] );
$table = $report[ $lang ]['table'];
$cache = $wpdb->prefix . 'opentranslation_cache';

// 表名白名单校验：只允许 TP 字典表
if ( 1 !== preg_match( '/^[a-z0-9_]+trp_dictionary_[a-z0-9_]+$/', $table ) ) {
    fwrite( STDERR, "表名不合法: {$table}\n" );
    exit( 1 );
}

$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

// 排除人工已校对
$protected = $wpdb->get_col( $wpdb->prepare(
    "SELECT id FROM `{$table}` WHERE id IN ({$ph}) AND status = 2",
    ...$ids
) );
$protected  = array_map( 'absint', $protected );
$target_ids = array_values( array_diff( $ids, $protected ) );

printf( "语言: %s\n字典表: %s\n", $lang, $table );
printf( "污染条目: %d\n", count( $ids ) );
printf( "跳过人工已校对(status=2): %d\n", count( $protected ) );
printf( "待重置: %d\n", count( $target_ids ) );

if ( empty( $target_ids ) ) {
    printf( "无需处理\n" );
    exit( 0 );
}

// 取待重置条目的原文，用于关联删除缓存（覆盖 C 类）
$originals = array();
foreach ( array_chunk( $target_ids, 300 ) as $chunk ) {
    $cph  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT original FROM `{$table}` WHERE id IN ({$cph})",
        ...$chunk
    ) );
    foreach ( $rows as $o ) {
        $originals[] = (string) $o;
    }
}
$originals = array_values( array_unique( $originals ) );

// 统计将删除的缓存行数
$cache_by_pattern = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$cache} WHERE target_lang = %s AND translated_text REGEXP %s",
    $lang,
    '</?protect-[0-9]+>'
) );

$cache_by_source = 0;
foreach ( array_chunk( $originals, 200 ) as $chunk ) {
    $sph              = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
    $cache_by_source += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$cache} WHERE target_lang = %s AND source_text IN ({$sph})",
        $lang,
        ...$chunk
    ) );
}

printf( "缓存待删（占位符模式命中）: %d\n", $cache_by_pattern );
printf( "缓存待删（原文关联命中，含 C 类）: %d\n", $cache_by_source );

if ( ! $confirm ) {
    printf( "\n[预演模式] 未传 --confirm，未做任何修改。\n" );
    printf( "将执行:\n" );
    printf( "  1. DELETE FROM %s WHERE target_lang='%s' AND translated_text REGEXP '</?protect-[0-9]+>'\n", $cache, $lang );
    printf( "  2. DELETE FROM %s WHERE target_lang='%s' AND source_text IN (%d 条原文)\n", $cache, $lang, count( $originals ) );
    printf( "  3. UPDATE %s SET translated=NULL, status=0 WHERE id IN (%d 个 id)\n", $table, count( $target_ids ) );
    exit( 0 );
}

printf( "\n=== 开始执行 ===\n" );

// 1. 按占位符模式删缓存（A 类）
$deleted_pattern = $wpdb->query( $wpdb->prepare(
    "DELETE FROM {$cache} WHERE target_lang = %s AND translated_text REGEXP %s",
    $lang,
    '</?protect-[0-9]+>'
) );
printf( "已删缓存（占位符模式）: %d 条\n", (int) $deleted_pattern );

// 2. 按原文关联删缓存（C 类）
$deleted_source = 0;
foreach ( array_chunk( $originals, 200 ) as $chunk ) {
    $sph             = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
    $deleted_source += (int) $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$cache} WHERE target_lang = %s AND source_text IN ({$sph})",
        $lang,
        ...$chunk
    ) );
}
printf( "已删缓存（原文关联）: %d 条\n", $deleted_source );

// 3. 分批重置字典表
$reset = 0;
foreach ( array_chunk( $target_ids, 200 ) as $chunk ) {
    $uph    = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE `{$table}` SET translated = NULL, status = 0 WHERE id IN ({$uph}) AND status != 2",
        ...$chunk
    ) );
    if ( false === $result ) {
        fwrite( STDERR, "批次失败: " . $wpdb->last_error . "\n" );
        exit( 1 );
    }
    $reset += (int) $result;
}
printf( "已重置字典条目: %d 条\n", $reset );

// 4. 清对象缓存，避免读到已删除的缓存行
wp_cache_flush();
printf( "已 flush 对象缓存\n" );

printf( "\n=== 完成 ===\n" );
