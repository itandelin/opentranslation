<?php
/**
 * 污染数据归零确认。只读，不修改任何数据。
 *
 * 对应 P0 Task 10 的完成检查：确认占位符污染已清理干净。
 *
 * 用法（在 WordPress 根目录执行）：
 *   php wp-content/plugins/opentranslation/tools/verify-clean.php
 *
 * 检查项（任一不为 0 即判失败，退出码 1）：
 *   1. 每个目标语言的字典表：translated 含残留 protect 占位符的条数
 *   2. 插件缓存表：translated_text 含残留 protect 占位符的条数
 *   3. 日志表：placeholder_restored 动作的条数（该动作随 restore_missing 一并移除，不应再新增）
 *
 * 正则用 `</?protect-[0-9]+>` 而非 `<protect-`：线上出现过模型输出闭合形式
 * `</protect-1>` 的样本，只匹配开标签会漏检。
 *
 * 与 tools/scan-polluted.php 的区别：scan 做的是多类别判定并产出待清理 ID 清单，
 * 本脚本只回答「清理后是否归零」这一个问题，供 P0 验收门禁使用。
 */

$wp_load = '';
foreach ( array( getcwd() . '/wp-load.php', dirname( __DIR__, 4 ) . '/wp-load.php' ) as $candidate ) {
    if ( file_exists( $candidate ) ) {
        $wp_load = $candidate;
        break;
    }
}

if ( '' === $wp_load ) {
    fwrite( STDERR, "找不到 wp-load.php，请在 WordPress 根目录执行\n" );
    exit( 1 );
}

require_once $wp_load;

if ( ! class_exists( 'OpenTranslation\\TP_Storage_Adapter' ) ) {
    fwrite( STDERR, "OpenTranslation 未加载\n" );
    exit( 1 );
}

/** 残留占位符的匹配模式，兼容开闭两种形式。 */
const OT_VERIFY_PATTERN = '</?protect-[0-9]+>';

/**
 * 表是否存在。
 */
function ot_verify_table_exists( $table ) {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/**
 * 某表某列的残留计数。
 *
 * @param string $table  表名（来自白名单化的适配器或 $wpdb->prefix）
 * @param string $column 列名（本脚本内固定字面量）
 * @return int
 */
function ot_verify_residual( $table, $column ) {
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$column} REGEXP %s", OT_VERIFY_PATTERN )
    );
}

/**
 * 打印一条检查结果并返回其计数。
 */
function ot_verify_report( $label, $count ) {
    printf( "  %-52s %s\n", $label, 0 === $count ? '0  ✓' : $count . '  ✗' );
    return $count;
}

global $wpdb;

printf( "污染数据归零确认（只读）\n%s\n", str_repeat( '=', 62 ) );

$total  = 0;
$missing = array();

printf( "字典表残留:\n" );
foreach ( \OpenTranslation\TP_Storage_Adapter::get_target_languages() as $language ) {
    $table = \OpenTranslation\TP_Storage_Adapter::get_dictionary_table( $language );

    if ( ! ot_verify_table_exists( $table ) ) {
        printf( "  %-52s 表不存在\n", $language );
        $missing[] = $table;
        continue;
    }

    $total += ot_verify_report( $language . '（' . $table . '）', ot_verify_residual( $table, 'translated' ) );
}

printf( "\n缓存表残留:\n" );
$cache_table = $wpdb->prefix . 'opentranslation_cache';
if ( ot_verify_table_exists( $cache_table ) ) {
    $total += ot_verify_report( 'translated_text', ot_verify_residual( $cache_table, 'translated_text' ) );
} else {
    printf( "  %-52s 表不存在\n", $cache_table );
    $missing[] = $cache_table;
}

printf( "\n日志表残留动作:\n" );
$log_table = $wpdb->prefix . 'opentranslation_log';
if ( ot_verify_table_exists( $log_table ) ) {
    $restored = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM `{$log_table}` WHERE action = %s", 'placeholder_restored' )
    );
    $total += ot_verify_report( 'placeholder_restored', $restored );
} else {
    printf( "  %-52s 表不存在\n", $log_table );
    $missing[] = $log_table;
}

printf( "\n%s\n", str_repeat( '=', 62 ) );

if ( ! empty( $missing ) ) {
    printf( "有表不存在，无法完成确认：%s\n", implode( ', ', $missing ) );
    exit( 1 );
}

if ( 0 === $total ) {
    printf( "全部通过：三项残留计数均为 0\n" );
    exit( 0 );
}

printf( "未通过：残留合计 %d 条，需重新执行 tools/clean-polluted.php\n", $total );
exit( 1 );
