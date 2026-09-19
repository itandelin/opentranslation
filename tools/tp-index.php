<?php
/**
 * TranslatePress 索引诊断。只读，不修改任何 TP 表、插件表或 option。
 *
 * 用法（在 WordPress 根目录执行）：
 *   php wp-content/plugins/opentranslation/tools/tp-index.php
 *   php wp-content/plugins/opentranslation/tools/tp-index.php --lang=zh_CN
 *
 * 报告内容：
 *   1. TP 关键表是否存在与行数
 *   2. trp_original_meta 的 meta_key 分布
 *   3. 数据库版本与 innodb_online_alter_log_max_size（在线 DDL 前置条件）
 *   4. 逐语言：字典表规模、status 分布、未译计数
 *   5. 逐语言：original_id 到 trp_original_meta / wp_posts 的关联覆盖
 *   6. 逐语言：归属桶分布与当前范围配置下的待翻译数
 *   7. 逐语言：插件缓存表状态分布，以及被「失败后不再就绪」挡住的条数
 *   8. 逐语言：引擎、体积、现有索引，ot_translated_status 是否已存在，是否满足在线 DDL
 *   9. 逐语言：三条热查询的 EXPLAIN 与实测耗时
 *
 * 第 3、8、9 段对应 P2-6 Task 1 的索引诊断；其余为字典表与归属链的关联诊断。
 * 本脚本不创建也不删除索引，Task 2 的写操作需林少单独确认后另行实施。
 *
 * 归属链与 Scope 保持一致：d.original_id → trp_original_meta.post_parent_id → wp_posts.post_type，
 * 关联不到的归入 __unlinked__ 桶。字典表没有 context 列，这是可用的最近似归属信息。
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

/**
 * 表是否存在。
 */
function ot_tp_table_exists( $table ) {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/**
 * 取单个计数值。
 *
 * @param string $sql 已自行完成 prepare 的 SQL
 * @return int
 */
function ot_tp_count( $sql ) {
    global $wpdb;
    return (int) $wpdb->get_var( $sql );
}

/**
 * 占比文本，分母为 0 时返回破折号。
 */
function ot_tp_pct( $part, $whole ) {
    if ( $whole <= 0 ) {
        return '—';
    }
    return sprintf( '%.1f%%', $part / $whole * 100 );
}

function ot_tp_head( $title ) {
    printf( "\n%s\n%s\n", str_repeat( '=', 62 ), $title );
}

/**
 * 第 1 段：TP 与插件关键表的存在性与行数。
 */
function ot_tp_report_tables() {
    global $wpdb;

    ot_tp_head( '关键表' );

    $like   = $wpdb->esc_like( $wpdb->prefix . 'trp_' ) . '%';
    $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

    foreach ( array( 'opentranslation_cache', 'opentranslation_log', 'opentranslation_usage' ) as $own ) {
        $tables[] = $wpdb->prefix . $own;
    }

    foreach ( $tables as $table ) {
        if ( ! ot_tp_table_exists( $table ) ) {
            printf( "  %-46s 不存在\n", $table );
            continue;
        }
        printf( "  %-46s %8d 行\n", $table, ot_tp_count( "SELECT COUNT(*) FROM `{$table}`" ) );
    }
}

/**
 * 第 2 段：trp_original_meta 的 meta_key 分布。
 *
 * 归属只依赖 post_parent_id，这里确认它确实存在且覆盖多少 original_id。
 */
function ot_tp_report_meta_keys() {
    global $wpdb;
    $meta = $wpdb->prefix . 'trp_original_meta';

    ot_tp_head( 'trp_original_meta 的 meta_key 分布' );

    if ( ! ot_tp_table_exists( $meta ) ) {
        printf( "  表不存在，所有条目都会落入 __unlinked__ 桶\n" );
        return;
    }

    $rows = $wpdb->get_results(
        "SELECT meta_key, COUNT(*) AS c, COUNT(DISTINCT original_id) AS ids FROM `{$meta}` GROUP BY meta_key ORDER BY c DESC",
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        printf( "  表为空，所有条目都会落入 __unlinked__ 桶\n" );
        return;
    }

    foreach ( $rows as $row ) {
        printf( "  %-24s %6d 行，覆盖 %6d 个 original_id\n", $row['meta_key'], (int) $row['c'], (int) $row['ids'] );
    }
}

/**
 * 逐语言：字典表规模与 status 分布。
 */
function ot_tp_report_dictionary( $table ) {
    global $wpdb;

    $total        = ot_tp_count( "SELECT COUNT(*) FROM `{$table}`" );
    $untranslated = ot_tp_count(
        "SELECT COUNT(*) FROM `{$table}` WHERE ( translated = '' OR translated IS NULL ) AND status != 2"
    );
    $done = ot_tp_count( "SELECT COUNT(*) FROM `{$table}` WHERE translated != '' AND translated IS NOT NULL" );

    printf( "  总行数 %d，已有译文 %d（%s），未译且未校对 %d（%s）\n",
        $total, $done, ot_tp_pct( $done, $total ), $untranslated, ot_tp_pct( $untranslated, $total ) );

    $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM `{$table}` GROUP BY status ORDER BY status ASC", ARRAY_A );
    printf( "  status 分布:" );
    foreach ( $rows as $row ) {
        printf( " %d=%d", (int) $row['status'], (int) $row['c'] );
    }
    printf( "（2 = 人工已校对，队列不再触碰）\n" );
}

/**
 * 逐语言：original_id 关联覆盖。
 */
function ot_tp_report_linkage( $table ) {
    global $wpdb;
    $meta  = $wpdb->prefix . 'trp_original_meta';
    $posts = $wpdb->prefix . 'posts';

    $total   = ot_tp_count( "SELECT COUNT(*) FROM `{$table}`" );
    $no_id   = ot_tp_count( "SELECT COUNT(*) FROM `{$table}` WHERE original_id IS NULL OR original_id = 0" );
    $join    = "FROM `{$table}` d INNER JOIN `{$meta}` m ON m.original_id = d.original_id AND m.meta_key = 'post_parent_id'";
    $linked  = ot_tp_count( "SELECT COUNT(DISTINCT d.id) {$join}" );
    $alive   = ot_tp_count( "SELECT COUNT(DISTINCT d.id) {$join} INNER JOIN `{$posts}` p ON p.ID = m.meta_value" );

    printf( "  original_id 缺失 %d（%s）\n", $no_id, ot_tp_pct( $no_id, $total ) );
    printf( "  能关联到 post_parent_id 的 %d（%s）\n", $linked, ot_tp_pct( $linked, $total ) );
    printf( "  其中目标文章仍存在的 %d（%s）\n", $alive, ot_tp_pct( $alive, $total ) );
    printf( "  关联不上、落入 __unlinked__ 桶的 %d（%s）\n", $total - $linked, ot_tp_pct( $total - $linked, $total ) );
}

/**
 * 逐语言：归属桶分布与当前范围配置。
 */
function ot_tp_report_scope( $language ) {
    $config = \OpenTranslation\Scope::get( $language );

    printf( "  范围配置: mode=%s, buckets=%s, published_only=%s\n",
        $config['mode'],
        empty( $config['buckets'] ) ? '（空）' : implode( ',', $config['buckets'] ),
        $config['published_only'] ? 'yes' : 'no'
    );

    $dist = \OpenTranslation\Scope::distribution( $language );
    ksort( $dist );
    printf( "  归属桶分布（总数 / 未译）:\n" );
    foreach ( $dist as $bucket => $counts ) {
        printf( "    %-24s %6d / %6d\n", $bucket, $counts['total'], $counts['untranslated'] );
    }

    printf( "  应用范围后的待翻译数: %d\n", \OpenTranslation\Scope::ready_count( $language ) );
}

/**
 * 逐语言：缓存表状态分布，以及被「失败后不再就绪」挡住的条数。
 *
 * 就绪条件取自 TP_Storage_Adapter::get_ready_untranslated()，
 * 这里取其否定，用来解释「未译数 > 实际可翻译数」的差额。
 */
function ot_tp_report_cache( $language, $table ) {
    global $wpdb;
    $cache = $wpdb->prefix . 'opentranslation_cache';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT status, COUNT(*) AS c FROM {$cache} WHERE target_lang = %s GROUP BY status ORDER BY status ASC",
            $language
        ),
        ARRAY_A
    );

    printf( "  缓存表状态分布:" );
    if ( empty( $rows ) ) {
        printf( " 无记录" );
    }
    foreach ( $rows as $row ) {
        printf( " %s=%d", (string) $row['status'], (int) $row['c'] );
    }
    printf( "\n" );

    $blocked = ot_tp_count(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT d.id) FROM `{$table}` d
             INNER JOIN {$cache} c ON c.cache_key = MD5( CONCAT( d.original, '|', %s, '|' ) )
             WHERE ( d.translated = '' OR d.translated IS NULL )
               AND d.status != 2
               AND ( c.translated_text IS NULL OR c.translated_text = '' )
               AND NOT ( c.status != 'failed' AND ( c.next_retry_at IS NULL OR c.next_retry_at <= %s ) )",
            $language,
            gmdate( 'Y-m-d H:i:s' )
        )
    );

    printf( "  被失败缓存挡在就绪之外的 %d 条（既有逻辑：失败到期前不再取用）\n", $blocked );
}

const OT_TP_INDEX_NAME = 'ot_translated_status';

/**
 * 字节数转可读文本。
 */
function ot_tp_bytes( $bytes ) {
    if ( $bytes >= 1048576 ) {
        return sprintf( '%.1f MB', $bytes / 1048576 );
    }
    return sprintf( '%d KB', (int) round( $bytes / 1024 ) );
}

/**
 * 数据库服务器侧能力：版本与在线 DDL 参数。
 *
 * @return array{version:string,major:int,minor:int,online_alter_bytes:int}
 */
function ot_tp_server_info() {
    global $wpdb;

    $version = (string) $wpdb->get_var( 'SELECT VERSION()' );
    $parts   = explode( '.', preg_replace( '/[^0-9.].*$/', '', $version ) );
    $row     = $wpdb->get_row( "SHOW VARIABLES LIKE 'innodb_online_alter_log_max_size'", ARRAY_A );

    return array(
        'version'            => $version,
        'major'              => isset( $parts[0] ) ? (int) $parts[0] : 0,
        'minor'              => isset( $parts[1] ) ? (int) $parts[1] : 0,
        'online_alter_bytes' => isset( $row['Value'] ) ? (int) $row['Value'] : 0,
    );
}

/**
 * 版本是否满足在线 DDL（MySQL >= 5.6；MariaDB 10.x 的 major 为 10，同样满足）。
 */
function ot_tp_version_ok( array $server ) {
    if ( 0 === $server['major'] ) {
        return false;
    }
    return $server['major'] > 5 || ( 5 === $server['major'] && $server['minor'] >= 6 );
}

/**
 * 第 3 段：数据库服务器版本与在线 DDL 参数。
 */
function ot_tp_report_server( array $server ) {
    ot_tp_head( '数据库服务器（在线 DDL 前置条件）' );

    printf( "  版本: %s\n", '' === $server['version'] ? '（读不到）' : $server['version'] );
    printf( "  版本满足 >= 5.6: %s\n", ot_tp_version_ok( $server ) ? 'yes' : 'no' );
    printf( "  innodb_online_alter_log_max_size: %s\n",
        $server['online_alter_bytes'] > 0 ? ot_tp_bytes( $server['online_alter_bytes'] ) : '（读不到）'
    );
}

/**
 * 逐语言：字典表的引擎、体积、现有索引，以及目标索引是否已存在。
 */
function ot_tp_report_index( $table, array $server ) {
    global $wpdb;

    $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
    $engine = isset( $status['Engine'] ) ? (string) $status['Engine'] : '（未知）';

    printf( "  引擎 %s，估算 %d 行，数据 %s，索引 %s\n",
        $engine,
        isset( $status['Rows'] ) ? (int) $status['Rows'] : 0,
        ot_tp_bytes( isset( $status['Data_length'] ) ? (int) $status['Data_length'] : 0 ),
        ot_tp_bytes( isset( $status['Index_length'] ) ? (int) $status['Index_length'] : 0 )
    );

    $grouped = array();
    foreach ( $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) as $row ) {
        $column = (string) $row['Column_name'];
        if ( ! empty( $row['Sub_part'] ) ) {
            $column .= '(' . (int) $row['Sub_part'] . ')';
        }
        $grouped[ (string) $row['Key_name'] ][] = $column;
    }

    printf( "  现有索引:\n" );
    foreach ( $grouped as $name => $columns ) {
        printf( "    %-24s %s\n", $name, implode( ', ', $columns ) );
    }

    printf( "  %s: %s\n", OT_TP_INDEX_NAME,
        isset( $grouped[ OT_TP_INDEX_NAME ] ) ? '已存在' : '不存在（P2-6 Task 2 未执行，属预期）' );

    if ( 0 === strcasecmp( $engine, 'InnoDB' ) && ot_tp_version_ok( $server ) ) {
        printf( "  在线 DDL 条件: 满足\n" );
        return;
    }

    printf( "  不满足在线 DDL 条件（引擎 %s，版本 %s；需 InnoDB 且版本 >= 5.6）\n",
        $engine, '' === $server['version'] ? '未知' : $server['version'] );
}

/**
 * 就绪扫描 SQL，与 TP_Storage_Adapter::get_ready_untranslated() 逐字对应。
 *
 * 含 Scope 片段，否则 EXPLAIN 结果不能代表实际执行计划。
 */
function ot_tp_ready_sql( $language, $table, $limit ) {
    global $wpdb;
    $cache = $wpdb->prefix . 'opentranslation_cache';
    $scope = \OpenTranslation\Scope::sql_where( $language );

    return $wpdb->prepare(
        "SELECT d.id, d.original, d.translated, d.status, c.translated_text AS cached_translation
        FROM `{$table}` d
        LEFT JOIN `{$cache}` c
            ON c.cache_key = MD5( CONCAT( d.original, '|', %s, '|' ) )
        WHERE ( d.translated = '' OR d.translated IS NULL )
            AND d.status != 2
            {$scope}
            AND (
                c.id IS NULL
                OR ( c.translated_text IS NOT NULL AND c.translated_text != '' )
                OR ( c.status != 'failed' AND ( c.next_retry_at IS NULL OR c.next_retry_at <= %s ) )
            )
        ORDER BY d.id ASC
        LIMIT %d OFFSET %d",
        $language,
        gmdate( 'Y-m-d H:i:s' ),
        $limit,
        0
    );
}

/**
 * 实测一条查询的耗时（毫秒）。只读查询。
 */
function ot_tp_time_query( $sql ) {
    global $wpdb;
    $start = microtime( true );
    $wpdb->get_results( $sql, ARRAY_A );
    return ( microtime( true ) - $start ) * 1000;
}

/**
 * 跑一条 EXPLAIN 并打印 type / key / rows / Extra，附实测耗时。
 */
function ot_tp_explain( $label, $sql ) {
    global $wpdb;

    $rows = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
    printf( "    %s（实测 %.1f ms）\n", $label, ot_tp_time_query( $sql ) );

    if ( empty( $rows ) ) {
        printf( "      EXPLAIN 无结果\n" );
        return;
    }

    foreach ( $rows as $row ) {
        printf( "      table=%-14s type=%-8s key=%-22s rows=%-8s %s\n",
            isset( $row['table'] ) ? (string) $row['table'] : '?',
            isset( $row['type'] ) ? (string) $row['type'] : '?',
            isset( $row['key'] ) && null !== $row['key'] ? (string) $row['key'] : '无',
            isset( $row['rows'] ) ? (string) $row['rows'] : '?',
            isset( $row['Extra'] ) ? (string) $row['Extra'] : ''
        );
    }
}

/**
 * 逐语言：三条热查询的 EXPLAIN 与实测耗时。
 */
function ot_tp_report_explain( $language, $table ) {
    global $wpdb;

    $scope = \OpenTranslation\Scope::sql_where( $language );
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` d WHERE ( d.translated = '' OR d.translated IS NULL ) AND d.status != %d {$scope}",
        2
    );

    printf( "  三条热查询的执行计划:\n" );
    ot_tp_explain( 'get_untranslated_count()', $count_sql );
    ot_tp_explain( 'get_ready_untranslated( 120 )', ot_tp_ready_sql( $language, $table, 120 ) );
    ot_tp_explain( 'has_ready_untranslated()', ot_tp_ready_sql( $language, $table, 1 ) );
}

global $wpdb;

$only_lang = '';
foreach ( $argv as $arg ) {
    if ( 0 !== strpos( $arg, '--lang=' ) ) {
        continue;
    }
    $requested = substr( $arg, 7 );
    $only_lang = \OpenTranslation\TP_Storage_Adapter::sanitize_language( $requested );
    if ( '' === $only_lang ) {
        fwrite( STDERR, sprintf( "语言 %s 不在 TP 目标语言列表内\n", $requested ) );
        exit( 1 );
    }
}

printf( "TranslatePress 索引诊断（只读）\n" );
printf( "TP 插件: %s\n", \OpenTranslation\TP_Storage_Adapter::is_tp_active() ? '已激活' : '未激活' );

$settings = \OpenTranslation\TP_Storage_Adapter::get_settings();
printf( "默认语言: %s\n", isset( $settings['default-language'] ) ? $settings['default-language'] : '（未知）' );

$languages = \OpenTranslation\TP_Storage_Adapter::get_target_languages();
printf( "目标语言: %s\n", empty( $languages ) ? '（无）' : implode( ', ', $languages ) );

$server = ot_tp_server_info();

ot_tp_report_tables();
ot_tp_report_meta_keys();
ot_tp_report_server( $server );

foreach ( $languages as $language ) {
    if ( '' !== $only_lang && $language !== $only_lang ) {
        continue;
    }

    $table = \OpenTranslation\TP_Storage_Adapter::get_dictionary_table( $language );

    ot_tp_head( sprintf( '%s（%s）', $language, $table ) );

    if ( ! ot_tp_table_exists( $table ) ) {
        printf( "  字典表不存在，TP 尚未为该语言建表\n" );
        continue;
    }

    ot_tp_report_dictionary( $table );
    ot_tp_report_linkage( $table );
    ot_tp_report_scope( $language );
    ot_tp_report_cache( $language, $table );
    ot_tp_report_index( $table, $server );
    ot_tp_report_explain( $language, $table );
}

printf( "\n%s\n本脚本只读，未修改任何数据。\n", str_repeat( '=', 62 ) );
