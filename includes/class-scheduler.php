<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Scheduler {
    const ACTION_HOOK   = 'opentranslation_as_process';
    const CONTINUE_HOOK = 'opentranslation_as_continue';
    const CRON_HOOK     = 'opentranslation_process_queue';
    const CRON_INTERVAL = 'opentranslation_interval';
    const LOCK_OPTION   = 'opentranslation_queue_lock_until';
    const CURSOR_OPTION = 'opentranslation_queue_cursor';
    const LAST_RUN_OPTION = 'opentranslation_last_run_stats';
    const STAT_KEYS = array( 'processed', 'cached', 'passthrough', 'model', 'failed', 'request_units' );
    public function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
        add_action( self::ACTION_HOOK, array( $this, 'run_batch' ) );
        add_action( self::CONTINUE_HOOK, array( $this, 'run_batch' ) );
        add_action( 'init', array( __CLASS__, 'schedule_next' ), 20 );
        add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_next' ) );
        add_action( 'action_scheduler_ensure_recurring_actions', array( __CLASS__, 'schedule_next' ) );
    }
    public static function has_action_scheduler() {
        return function_exists( 'as_enqueue_async_action' )
            && function_exists( 'as_next_scheduled_action' )
            && function_exists( 'as_schedule_recurring_action' );
    }

    public static function schedule_next() {
        if ( self::has_action_scheduler() ) {
            if ( did_action( 'action_scheduler_init' ) <= 0 ) {
                return;
            }

            self::clear_wp_cron_schedule();

            $next = as_next_scheduled_action( self::ACTION_HOOK );
            if ( false === $next ) {
                as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::ACTION_HOOK );
            }
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }
    public function run_batch() {
        if ( ! $this->acquire_lock() ) {
            Log::add( '', 'scheduler_run', 'run_batch skipped: queue locked' );
            return;
        }
        $totals = array_fill_keys( self::STAT_KEYS, 0 );
        $totals['languages']  = array();
        $totals['started_at'] = time();
        $should_continue = false;
        $started_at = microtime( true );
        try {
            Log::add( '', 'scheduler_run', 'run_batch started' );
            if ( ! TP_Storage_Adapter::is_tp_active() ) {
                Log::add( '', 'scheduler_run', 'TP not active' );
                return;
            }
            $languages = TP_Storage_Adapter::get_target_languages();
            Log::add( '', 'scheduler_run', 'languages: ' . wp_json_encode( $this->get_language_run_order( $languages ) ) );
            if ( empty( $languages ) ) {
                return;
            }
            $this->cleanup_rate_limit();
            $should_continue = $this->run_rounds( $languages, $totals, $started_at );
        } finally {
            $this->release_lock();
            $this->save_last_run( $totals, $started_at );
        }
        // 日志清理移出持锁区间，避免锁内做删除操作
        Log::maybe_cleanup();
        if ( $should_continue ) {
            self::maybe_enqueue_follow_up();
        }
    }

    /**
     * 按语言轮转执行若干轮，返回是否仍有待处理积压。
     *
     * @param array $languages  目标语言列表
     * @param array $totals     统计累加目标（引用）
     * @param float $started_at microtime 起点，用于时间预算
     * @return bool
     */
    private function run_rounds( $languages, &$totals, $started_at ) {
        // 所有模型都在熔断中：直接跳过本轮，不查字典表、不标记任何条目失败。
        if ( $this->should_skip_for_circuit( $totals ) ) {
            return false;
        }

        $settings = get_option( 'opentranslation_settings', array() );
        $batch_size = isset( $settings['batch_size'] ) ? absint( $settings['batch_size'] ) : 10;
        $batch_size = max( 1, min( 50, $batch_size ) );
        $ordered_languages = $this->get_language_run_order( $languages );
        $should_continue = false;
        $round_limit = $this->get_round_limit();
        for ( $round = 0; $round < $round_limit; $round++ ) {
            $processed = false;
            foreach ( $ordered_languages as $language ) {
                if ( ! $this->is_language_enabled( $language ) ) {
                    Log::add( '', 'scheduler_run', "language {$language} disabled" );
                    continue;
                }
                $stats = $this->process_language( $language, $batch_size );
                // 无论成败都累加：整批失败时 failed / request_units 也必须出现在统计里
                $this->accumulate_totals( $totals, $stats, $language );
                if ( $stats['processed'] > 0 ) {
                    $this->advance_language_cursor( $languages, $language );
                    $ordered_languages = $this->get_language_run_order( $languages );
                    $should_continue = $this->has_ready_backlog( $languages );
                    $processed = true;
                    break;
                }
            }
            if ( ! $processed || ! $should_continue || ! $this->can_continue_run( $started_at ) || ! $this->is_rate_allowed() ) {
                break;
            }
        }
        return $should_continue;
    }

    /**
     * 所有模型都在熔断中时跳过本轮。
     *
     * @param array $totals 统计累加目标（引用），命中时置 circuit_open
     * @return bool 是否应跳过
     */
    private function should_skip_for_circuit( &$totals ) {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) || ! ( new Model_Health() )->all_open( $models ) ) {
            return false;
        }

        Log::add( '', 'model_circuit_open', __( 'All models circuit-open; round skipped.', 'opentranslation' ) );
        $totals['circuit_open'] = true;
        return true;
    }

    /**
     * 累加单语言统计到总计。
     */
    private function accumulate_totals( &$totals, $stats, $language ) {
        foreach ( self::STAT_KEYS as $key ) {
            $totals[ $key ] += $stats[ $key ];
        }
        if ( $stats['processed'] <= 0 ) {
            return;
        }
        if ( ! isset( $totals['languages'][ $language ] ) ) {
            $totals['languages'][ $language ] = 0;
        }
        $totals['languages'][ $language ] += $stats['processed'];
    }

    /**
     * 持久化本次执行统计，供后台「Last Run」展示。
     *
     * 放在 finally 里：前置检查提前 return 时也留下记录，
     * 否则页面永远显示上上次的结果，用户无法分辨「没跑」和「跑了但没条目」。
     */
    private function save_last_run( $totals, $started_at ) {
        $totals['finished_at'] = time();
        $totals['duration']    = round( microtime( true ) - $started_at, 2 );
        update_option( self::LAST_RUN_OPTION, $totals, false );
    }

    /**
     * 上一次队列执行的统计，供后台展示。
     */
    public static function get_last_run_stats() {
        $stats = get_option( self::LAST_RUN_OPTION, array() );
        return is_array( $stats ) ? $stats : array();
    }

    /**
     * 处理单个语言的一批条目。
     *
     * @param string $language   目标语言
     * @param int    $batch_size 送模型的条数上限
     * @return array{processed:int,cached:int,passthrough:int,model:int,failed:int,request_units:int}
     */
    private function process_language( $language, $batch_size ) {
        $stats = array_fill_keys( self::STAT_KEYS, 0 );
        if ( ! $this->is_rate_allowed() ) {
            Log::add( '', 'scheduler_run', 'rate limit reached' );
            return $stats;
        }
        $items = TP_Storage_Adapter::get_ready_untranslated( $language, $this->get_prefetch_size( $batch_size ) );
        Log::add( '', 'scheduler_run', "language {$language} ready items: " . count( $items ) );
        if ( empty( $items ) ) {
            return $stats;
        }
        $buckets = $this->split_items_into_buckets( $items, $batch_size );
        $translations = array();
        foreach ( $buckets['passthrough'] as $item ) {
            $translations[] = array( 'id' => $item['id'], 'translated' => $item['original'] );
        }
        foreach ( $buckets['cached'] as $item ) {
            $translations[] = array( 'id' => $item['id'], 'translated' => $item['cached_translation'] );
        }
        $stats['passthrough'] = count( $buckets['passthrough'] );
        $stats['cached']      = count( $buckets['cached'] );
        if ( ! empty( $buckets['model'] ) ) {
            $translator = new Translator();
            $results    = $translator->translate_batch( $buckets['model'], $language );
            $stats['request_units'] = isset( $results['request_units'] ) ? (int) $results['request_units'] : 0;
            $model_results   = ! empty( $results['translations'] ) ? $results['translations'] : array();
            $stats['model']  = count( $model_results );
            $stats['failed'] = max( 0, count( $buckets['model'] ) - $stats['model'] );
            $translations    = array_merge( $translations, $model_results );
        }
        $translations       = $this->dedupe_translations( $translations );
        $stats['processed'] = count( $translations );
        Log::add( '', 'scheduler_run', "language {$language} translations: {$stats['processed']}" );
        if ( ! empty( $translations ) ) {
            TP_Storage_Adapter::bulk_update_translations( $language, $translations );
        }
        $this->record_request_units( $stats['request_units'] );
        return $stats;
    }

    /**
     * 把条目分流为缓存命中、直通、送模型三组。
     *
     * @return array{cached:array,passthrough:array,model:array}
     */
    private function split_items_into_buckets( $items, $batch_size ) {
        $passthrough_limit = $this->get_passthrough_batch_limit( $batch_size );
        $buckets = array( 'cached' => array(), 'passthrough' => array(), 'model' => array() );
        foreach ( $items as $item ) {
            $quick_count = count( $buckets['cached'] ) + count( $buckets['passthrough'] );
            if ( ! empty( $item['cached_translation'] ) ) {
                if ( $quick_count < $passthrough_limit ) {
                    $buckets['cached'][] = $item;
                }
                continue;
            }
            if ( null !== Translator::get_passthrough_translation( $item['original'] ) ) {
                if ( $quick_count < $passthrough_limit ) {
                    $buckets['passthrough'][] = $item;
                }
                continue;
            }
            if ( count( $buckets['model'] ) < $batch_size ) {
                $buckets['model'][] = $item;
            }
        }
        return $buckets;
    }

    /**
     * 同一 id 只保留最后一次结果。
     */
    private function dedupe_translations( $translations ) {
        $by_id = array();
        foreach ( $translations as $item ) {
            $by_id[ (string) $item['id'] ] = $item;
        }
        return array_values( $by_id );
    }
    private function is_language_enabled( $language ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $disabled = isset( $settings['disabled_languages'] ) ? $settings['disabled_languages'] : array();
        return ! in_array( $language, $disabled, true );
    }
    private function is_rate_allowed() {
        $settings = get_option( 'opentranslation_settings', array() );
        $limit = isset( $settings['rate_limit_per_minute'] ) ? absint( $settings['rate_limit_per_minute'] ) : 20;
        if ( $limit <= 0 ) {
            return true;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_rate_limit';
        $one_minute_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(request_count), 0) FROM {$table} WHERE window_start >= %s", $one_minute_ago ) );
        return $count < $limit;
    }
    private function cleanup_rate_limit() {
        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_rate_limit';
        $one_hour_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_start < %s", $one_hour_ago ) );
    }
    public static function trigger_manual() {
        self::schedule_next();
        if ( self::maybe_enqueue_follow_up( true ) ) {
            return;
        }
        $scheduler = new self();
        $scheduler->run_batch();
    }
    public static function clear_wp_cron_schedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }
    }
    public static function unschedule_all() {
        self::clear_wp_cron_schedule();
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::ACTION_HOOK );
            as_unschedule_all_actions( self::CONTINUE_HOOK );
        }
    }
    private function acquire_lock() {
        $ttl = (int) apply_filters( 'opentranslation_queue_lock_ttl', 10 * MINUTE_IN_SECONDS );
        $ttl = max( MINUTE_IN_SECONDS, min( HOUR_IN_SECONDS, $ttl ) );
        $now = time();
        $lock_until = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $lock_until > $now ) {
            return false;
        }
        delete_option( self::LOCK_OPTION );
        return add_option( self::LOCK_OPTION, $now + $ttl, '', false );
    }
    private function release_lock() {
        delete_option( self::LOCK_OPTION );
    }
    private function get_prefetch_size( $batch_size ) {
        return max( $batch_size, $this->get_passthrough_batch_limit( $batch_size ) + $batch_size );
    }
    private function get_passthrough_batch_limit( $batch_size ) {
        $multiplier = (int) apply_filters( 'opentranslation_passthrough_batch_multiplier', 5 );
        $multiplier = max( 1, min( 20, $multiplier ) );
        return max( $batch_size, min( 500, $batch_size * $multiplier ) );
    }
    private function record_request_units( $count ) {
        if ( $count <= 0 ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'opentranslation_rate_limit';
        $window_start = gmdate( 'Y-m-d H:i:00' );
        $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE window_start = %s LIMIT 1", $window_start ) );
        if ( $existing_id ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET request_count = request_count + %d WHERE id = %d", $count, $existing_id ) );
            return;
        }
        $wpdb->insert(
            $table,
            array(
                'window_start'  => $window_start,
                'request_count' => $count,
            ),
            array( '%s', '%d' )
        );
    }
    private function get_language_run_order( $languages ) {
        $cursor = get_option( self::CURSOR_OPTION, '' );
        if ( empty( $cursor ) || ! in_array( $cursor, $languages, true ) ) {
            return $languages;
        }

        $position = array_search( $cursor, $languages, true );
        return array_merge( array_slice( $languages, $position ), array_slice( $languages, 0, $position ) );
    }
    private function advance_language_cursor( $languages, $processed_language ) {
        if ( empty( $languages ) ) {
            delete_option( self::CURSOR_OPTION );
            return;
        }

        $position = array_search( $processed_language, $languages, true );
        if ( false === $position ) {
            delete_option( self::CURSOR_OPTION );
            return;
        }

        $next = $languages[ ( $position + 1 ) % count( $languages ) ];
        update_option( self::CURSOR_OPTION, $next, false );
    }
    private function has_ready_backlog( $languages ) {
        foreach ( $languages as $language ) {
            if ( $this->is_language_enabled( $language ) && TP_Storage_Adapter::has_ready_untranslated( $language ) ) {
                return true;
            }
        }
        return false;
    }
    private static function maybe_enqueue_follow_up( $force = false ) {
        if ( ! self::has_action_scheduler() || did_action( 'action_scheduler_init' ) <= 0 ) {
            return false;
        }
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $pending = as_get_scheduled_actions( array( 'hook' => self::CONTINUE_HOOK, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
            $running = as_get_scheduled_actions( array( 'hook' => self::CONTINUE_HOOK, 'status' => 'running', 'per_page' => 1 ), 'ids' );
            if ( ! $force && ( ! empty( $pending ) || ! empty( $running ) ) ) {
                return false;
            }
        }
        as_enqueue_async_action( self::CONTINUE_HOOK );
        return true;
    }
    private function get_round_limit() {
        if ( ! $this->is_async_runner_context() ) {
            return 1;
        }
        $limit = (int) apply_filters( 'opentranslation_scheduler_round_limit', 3 );
        return max( 1, min( 5, $limit ) );
    }
    private function can_continue_run( $started_at ) {
        if ( ! $this->is_async_runner_context() ) {
            return false;
        }
        $budget = (int) apply_filters( 'opentranslation_scheduler_time_budget', 55 );
        $budget = max( 15, min( 90, $budget ) );
        return ( microtime( true ) - $started_at ) < $budget;
    }
    private function is_async_runner_context() {
        return in_array( current_filter(), array( self::ACTION_HOOK, self::CONTINUE_HOOK ), true );
    }
}
