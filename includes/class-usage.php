<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 模型用量统计：按 UTC 日期 + 模型聚合到 opentranslation_usage 表。
 */
class Usage {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'opentranslation_usage';
    }

    /**
     * 累加一次模型调用。失败调用也记 requests，token 为 0。
     *
     * @param string $model_key   Model_Identity::key()
     * @param string $model_label Model_Identity::label()
     * @param array  $usage       归一化用量（prompt_tokens / completion_tokens / total_tokens）
     * @param int    $requests    HTTP 尝试次数
     * @return bool
     */
    public static function record( $model_key, $model_label, array $usage, $requests ) {
        global $wpdb;
        $table = self::table();

        $p = isset( $usage['prompt_tokens'] ) ? max( 0, (int) $usage['prompt_tokens'] ) : 0;
        $c = isset( $usage['completion_tokens'] ) ? max( 0, (int) $usage['completion_tokens'] ) : 0;
        $t = isset( $usage['total_tokens'] ) ? max( 0, (int) $usage['total_tokens'] ) : $p + $c;

        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (usage_date, model_key, model_label, requests, prompt_tokens, completion_tokens, total_tokens)
             VALUES (%s, %s, %s, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
                model_label = VALUES(model_label),
                requests = requests + VALUES(requests),
                prompt_tokens = prompt_tokens + VALUES(prompt_tokens),
                completion_tokens = completion_tokens + VALUES(completion_tokens),
                total_tokens = total_tokens + VALUES(total_tokens)",
            gmdate( 'Y-m-d' ),
            substr( (string) $model_key, 0, 32 ),
            substr( (string) $model_label, 0, 191 ),
            max( 0, (int) $requests ),
            $p,
            $c,
            $t
        ) );

        return false !== $result;
    }

    /**
     * 最近 N 天的逐日明细，日期倒序。
     *
     * @return array 每行含 usage_date、model_key、model_label 与四个计数
     */
    public static function daily( $days = 30 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT usage_date, model_key, model_label, requests, prompt_tokens, completion_tokens, total_tokens
             FROM " . self::table() . " WHERE usage_date >= %s ORDER BY usage_date DESC, model_label ASC",
            self::since( $days )
        ), ARRAY_A );
    }

    /**
     * 最近 N 天按模型汇总。
     *
     * @return array model_key => 汇总行
     */
    public static function by_model( $days = 30 ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT model_key, MAX(model_label) AS model_label, SUM(requests) AS requests,
                    SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens, SUM(total_tokens) AS total_tokens
             FROM " . self::table() . " WHERE usage_date >= %s GROUP BY model_key ORDER BY total_tokens DESC",
            self::since( $days )
        ), ARRAY_A );

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[ $row['model_key'] ] = self::cast( $row );
        }
        return $out;
    }

    /**
     * 本月（UTC）汇总。
     */
    public static function month_totals() {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(requests),0) AS requests, COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,
                    COALESCE(SUM(completion_tokens),0) AS completion_tokens, COALESCE(SUM(total_tokens),0) AS total_tokens
             FROM " . self::table() . " WHERE usage_date >= %s",
            gmdate( 'Y-m-01' )
        ), ARRAY_A );
        return self::cast( (array) $row );
    }

    private static function since( $days ) {
        $days = max( 1, min( 365, (int) $days ) );
        return gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
    }

    private static function cast( array $row ) {
        foreach ( array( 'requests', 'prompt_tokens', 'completion_tokens', 'total_tokens' ) as $k ) {
            $row[ $k ] = isset( $row[ $k ] ) ? (int) $row[ $k ] : 0;
        }
        return $row;
    }
}
