<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 翻译范围控制。
 *
 * 按「归属桶」过滤：桶 = 各 post_type + 特殊桶 __unlinked__（未关联文章）。
 * 归属来自 TP 在页面渲染时记录的 `trp_original_meta.post_parent_id → wp_posts.post_type`，
 * 字典表没有 context 列，这是可用的最近似归属信息。
 *
 * 过滤在 SQL 层完成（就绪扫描、就绪判定、未译计数共用 Scope::sql_where）。
 */
class Scope {

    const UNLINKED = '__unlinked__';

    /**
     * 规范化后的某语言范围配置。
     *
     * @param string $language
     * @return array{mode:string,buckets:array,published_only:bool}
     */
    public static function get( $language ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $all      = isset( $settings['scope'] ) && is_array( $settings['scope'] ) ? $settings['scope'] : array();
        $config   = isset( $all[ $language ] ) && is_array( $all[ $language ] ) ? $all[ $language ] : array();

        return self::normalize_config( $config );
    }

    /**
     * sanitize_settings 委托：按语言白名单规范化，告警写入 $warnings 引用。
     *
     * @param array $input     表单提交的 scope 数组
     * @param array $languages 目标语言白名单
     * @param array $warnings  引用，写入逐条告警文本
     * @return array 规范化后的 scope 配置
     */
    public static function sanitize( array $input, array $languages, array &$warnings ) {
        $output = array();

        foreach ( $languages as $language ) {
            $raw    = isset( $input[ $language ] ) && is_array( $input[ $language ] ) ? $input[ $language ] : array();
            $config = self::normalize_config( $raw );

            // 桶为空时 include/exclude 都没有意义，回落 all 并告警
            if ( 'all' !== $config['mode'] && empty( $config['buckets'] ) ) {
                $config['mode'] = 'all';
                $warnings[] = sprintf(
                    /* translators: %s is a language code. */
                    __( '%s: nothing selected; fell back to all content.', 'opentranslation' ),
                    $language
                );
            }

            $output[ $language ] = $config;
        }

        return $output;
    }

    /**
     * SQL WHERE 片段（'' 或以 ' AND ' 开头），表别名固定 d。
     *
     * 片段内不含用户输入的未转义值：post_type 已限定 [a-z0-9_-]，
     * 表名来自 $wpdb->prefix（插件常量），未关联桶为内部常量。
     *
     * @param string $language
     * @return string
     */
    public static function sql_where( $language ) {
        $config = self::get( $language );
        if ( 'all' === $config['mode'] ) {
            return '';
        }

        global $wpdb;
        $meta_table  = $wpdb->prefix . 'trp_original_meta';
        $posts_table = $wpdb->prefix . 'posts';

        $include_unlinked = in_array( self::UNLINKED, $config['buckets'], true );
        $post_types       = array_values( array_diff( $config['buckets'], array( self::UNLINKED ) ) );

        $linked_subquery = "SELECT m.original_id FROM `{$meta_table}` m"
            . " INNER JOIN `{$posts_table}` p ON p.ID = m.meta_value"
            . " WHERE m.meta_key = 'post_parent_id'";

        if ( ! empty( $post_types ) ) {
            $quoted = array();
            foreach ( $post_types as $post_type ) {
                $quoted[] = "'" . esc_sql( $post_type ) . "'";
            }
            $linked_subquery .= ' AND p.post_type IN (' . implode( ',', $quoted ) . ')';
        }

        if ( $config['published_only'] ) {
            $linked_subquery .= " AND p.post_status = 'publish'";
        }

        $has_any_link = "SELECT original_id FROM `{$meta_table}` WHERE meta_key = 'post_parent_id'";

        if ( 'include' === $config['mode'] ) {
            $parts = array();
            if ( ! empty( $post_types ) ) {
                $parts[] = "d.original_id IN ( {$linked_subquery} )";
            }
            if ( $include_unlinked ) {
                $parts[] = "d.original_id NOT IN ( {$has_any_link} )";
            }
            return empty( $parts ) ? '' : ' AND ( ' . implode( ' OR ', $parts ) . ' )';
        }

        // exclude：任一命中即排除；排除未关联 = 只保留已关联
        $parts = array();
        if ( ! empty( $post_types ) ) {
            $parts[] = "d.original_id NOT IN ( {$linked_subquery} )";
        }
        if ( $include_unlinked ) {
            $parts[] = "d.original_id IN ( {$has_any_link} )";
        }

        return empty( $parts ) ? '' : ' AND ( ' . implode( ' AND ', $parts ) . ' )';
    }

    /**
     * 各归属桶的分布：总数与未译数（COUNT(DISTINCT d.id)）。
     *
     * @param string $language
     * @return array bucket => ['total'=>int,'untranslated'=>int]
     */
    public static function distribution( $language ) {
        global $wpdb;
        $table = TP_Storage_Adapter::get_dictionary_table( $language );

        $sql = "SELECT COALESCE(p.post_type, '" . self::UNLINKED . "') AS bucket,"
            . ' COUNT(DISTINCT d.id) AS total,'
            . " COUNT(DISTINCT CASE WHEN (d.translated = '' OR d.translated IS NULL) AND d.status != 2 THEN d.id END) AS untranslated"
            . " FROM `{$table}` d"
            . " LEFT JOIN {$wpdb->prefix}trp_original_meta m"
            . "   ON m.original_id = d.original_id AND m.meta_key = 'post_parent_id'"
            . " LEFT JOIN {$wpdb->prefix}posts p ON p.ID = m.meta_value"
            . " GROUP BY COALESCE(p.post_type, '" . self::UNLINKED . "')";

        $rows   = $wpdb->get_results( $sql, ARRAY_A );
        $result = array();
        foreach ( $rows as $row ) {
            $bucket = isset( $row['bucket'] ) ? (string) $row['bucket'] : self::UNLINKED;
            $result[ $bucket ] = array(
                'total'        => (int) $row['total'],
                'untranslated' => (int) $row['untranslated'],
            );
        }

        return $result;
    }

    /**
     * 应用范围后的未译条目数，供保存时告警。
     *
     * @param string $language
     * @return int
     */
    public static function ready_count( $language ) {
        global $wpdb;
        $table = TP_Storage_Adapter::get_dictionary_table( $language );
        $where = self::sql_where( $language );

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` d"
            . " WHERE ( d.translated = '' OR d.translated IS NULL ) AND d.status != 2"
            . $where
        );
    }

    /**
     * sanitize_settings 委托：规范化 + 逐条告警（含范围内无条目）。
     *
     * @param array $input     表单提交的 scope 数组
     * @param array $languages 目标语言白名单
     * @return array 规范化后的 scope 配置
     */
    public static function sanitize_settings( array $input, array $languages ) {
        $warnings = array();
        $output   = self::sanitize( $input, $languages, $warnings );

        foreach ( $warnings as $warning_text ) {
            add_settings_error( 'opentranslation_settings', 'scope_warn', $warning_text, 'warning' );
        }

        foreach ( $languages as $language ) {
            $cfg = $output[ $language ];
            if ( 'all' !== $cfg['mode'] && 0 === self::ready_count( $language ) ) {
                add_settings_error(
                    'opentranslation_settings',
                    'scope_empty_' . $language,
                    sprintf(
                        /* translators: %s is a language code. */
                        __( '%s: no translatable entries under the current translation scope.', 'opentranslation' ),
                        $language
                    ),
                    'warning'
                );
            }
        }

        return $output;
    }

    /**
     * 规范化单语言配置。
     *
     * @param array $config
     * @return array{mode:string,buckets:array,published_only:bool}
     */
    private static function normalize_config( array $config ) {
        $mode = isset( $config['mode'] ) ? (string) $config['mode'] : 'all';
        if ( ! in_array( $mode, array( 'all', 'include', 'exclude' ), true ) ) {
            $mode = 'all';
        }

        $buckets = array();
        if ( isset( $config['buckets'] ) && is_array( $config['buckets'] ) ) {
            foreach ( $config['buckets'] as $bucket ) {
                $bucket = (string) $bucket;
                if ( self::UNLINKED === $bucket || 1 === preg_match( '/^[a-z0-9_-]{1,32}$/', $bucket ) ) {
                    $buckets[] = $bucket;
                }
            }
            $buckets = array_values( array_unique( $buckets ) );
        }

        // published_only 只在 include 模式有意义：排除「已发布的 product」语义混乱，不支持
        $published_only = ! empty( $config['published_only'] ) && 'exclude' !== $mode;

        return array(
            'mode'           => $mode,
            'buckets'        => $buckets,
            'published_only' => $published_only,
        );
    }
}