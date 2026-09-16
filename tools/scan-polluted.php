<?php
/**
 * 扫描被占位符缺陷污染的译文。只读，不做任何修改。
 *
 * 用法（在 WordPress 根目录执行）：
 *   php scan-polluted.php
 *   php scan-polluted.php --lang=zh_CN
 *
 * 判定依据（互斥，优先级从高到低）：
 *   A 残留 <protect-N>   —— restore() 因 tokens 被清空而短路，确定污染
 *   B 残留 nTPnT         —— TranslatePress 自身占位符被模型破坏
 *   C 标签多重集不匹配且多出的在尾部 —— 旧版「末尾拼接兜底」的痕迹
 *
 * C 类用标签多重集比对而非简单的尾部正则，
 * 避免把「原文有开标签、译文有闭标签」这类正常情况误判。
 */

$wp_load = '';
foreach ( array( getcwd() . '/wp-load.php', dirname( __DIR__ ) . '/wp-load.php' ) as $candidate ) {
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

global $wpdb;

$only_lang = '';
foreach ( $argv as $arg ) {
    if ( 0 === strpos( $arg, '--lang=' ) ) {
        $only_lang = substr( $arg, 7 );
    }
}

/**
 * 提取文本中所有 HTML 标签，返回「标签 => 出现次数」。
 */
function ot_tag_multiset( $text ) {
    $set = array();
    if ( preg_match_all( '/<[a-zA-Z\/!][^>]*>/', $text, $m ) ) {
        foreach ( $m[0] as $tag ) {
            $key         = $tag;
            $set[ $key ] = isset( $set[ $key ] ) ? $set[ $key ] + 1 : 1;
        }
    }
    return $set;
}

/**
 * 判定单条是否污染。
 *
 * @return string 污染类别，未污染返回空串
 */
function ot_detect_pollution( $original, $translated ) {
    if ( preg_match( '#</?protect-\d+>#', $translated ) ) {
        return 'A_残留protect占位符';
    }

    // 原文本身含 nTPnT 是 TP 正常行为，只有译文含而原文不含才是破坏
    if ( preg_match( '/\d+TP\d+T/', $translated ) && ! preg_match( '/\d+TP\d+T/', $original ) ) {
        return 'B_残留TP占位符';
    }

    // C：译文的标签多重集超出原文，且多出的标签位于尾部
    $orig_tags  = ot_tag_multiset( $original );
    $trans_tags = ot_tag_multiset( $translated );

    $excess = array();
    foreach ( $trans_tags as $tag => $count ) {
        $orig_count = isset( $orig_tags[ $tag ] ) ? $orig_tags[ $tag ] : 0;
        if ( $count > $orig_count ) {
            $excess[ $tag ] = $count - $orig_count;
        }
    }

    if ( empty( $excess ) ) {
        return '';
    }

    // 多出的标签必须出现在尾部才算拼接痕迹
    if ( ! preg_match( '/(?:<[a-zA-Z\/!][^>]*>\s*)+$/', $translated, $m ) ) {
        return 'D_标签数量不符';
    }

    $tail = $m[0];
    foreach ( array_keys( $excess ) as $tag ) {
        if ( false === strpos( $tail, $tag ) ) {
            return 'D_标签数量不符';
        }
    }

    return 'C_尾部拼接标签';
}

$languages = \OpenTranslation\TP_Storage_Adapter::get_target_languages();
$report    = array();
$total     = 0;
$grand     = array();

foreach ( $languages as $language ) {
    if ( '' !== $only_lang && $language !== $only_lang ) {
        continue;
    }

    $table = \OpenTranslation\TP_Storage_Adapter::get_dictionary_table( $language );

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $exists ) {
        printf( "\n=== %s ===\n表 %s 不存在，跳过\n", $language, $table );
        continue;
    }

    $rows = $wpdb->get_results(
        "SELECT id, original, translated, status FROM `{$table}` WHERE translated != '' AND translated IS NOT NULL",
        ARRAY_A
    );

    $by_reason  = array();
    $ids        = array();
    $proofread  = 0;

    foreach ( $rows as $row ) {
        $reason = ot_detect_pollution( (string) $row['original'], (string) $row['translated'] );
        if ( '' === $reason ) {
            continue;
        }

        $by_reason[ $reason ][] = $row;

        // status=2 是人工已校对，绝不纳入清理
        if ( 2 === (int) $row['status'] ) {
            $proofread++;
            continue;
        }

        $ids[] = (int) $row['id'];
    }

    printf( "\n%s\n=== %s（%s）===\n", str_repeat( '=', 62 ), $language, $table );
    printf( "已译总数 %d\n", count( $rows ) );

    ksort( $by_reason );
    foreach ( $by_reason as $reason => $items ) {
        printf( "\n  [%s] %d 条\n", $reason, count( $items ) );
        $grand[ $reason ] = ( isset( $grand[ $reason ] ) ? $grand[ $reason ] : 0 ) + count( $items );

        foreach ( array_slice( $items, 0, 5 ) as $item ) {
            printf(
                "    id=%d status=%d\n      原文: %s\n      译文: %s\n",
                $item['id'],
                (int) $item['status'],
                mb_substr( (string) $item['original'], 0, 90 ),
                mb_substr( (string) $item['translated'], 0, 90 )
            );
        }
    }

    $cache_table = $wpdb->prefix . 'opentranslation_cache';
    $cache_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$cache_table} WHERE target_lang = %s AND translated_text REGEXP %s",
            $language,
            '</?protect-[0-9]+>'
        )
    );

    printf( "\n  待重置条目: %d（已排除 status=2 人工校对 %d 条）\n", count( $ids ), $proofread );
    printf( "  关联缓存脏记录: %d 条\n", $cache_count );

    $report[ $language ] = array(
        'ids'       => $ids,
        'cache'     => $cache_count,
        'proofread' => $proofread,
        'table'     => $table,
    );
    $total += count( $ids );
}

// 全局缓存脏记录（不分语言）
$cache_table = $wpdb->prefix . 'opentranslation_cache';
$cache_total = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$cache_table} WHERE translated_text REGEXP %s", '</?protect-[0-9]+>' )
);

printf( "\n%s\n", str_repeat( '=', 62 ) );
printf( "按类别合计:\n" );
ksort( $grand );
foreach ( $grand as $reason => $count ) {
    printf( "  %-24s %d\n", $reason, $count );
}
printf( "\n待重置条目合计（已排除 status=2）: %d\n", $total );
printf( "缓存脏记录合计（全语言）: %d\n", $cache_total );
printf( "\n本脚本只读，未做任何修改。\n" );

$out = __DIR__ . '/polluted-ids.json';
file_put_contents( $out, wp_json_encode( $report ) );
printf( "ID 清单已写入: %s\n", $out );
