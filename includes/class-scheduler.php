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
        $should_continue = false;
        $started_at = microtime( true );
        try {
            Log::add( '', 'scheduler_run', 'run_batch started' );
            if ( ! TP_Storage_Adapter::is_tp_active() ) {
                Log::add( '', 'scheduler_run', 'TP not active' );
                return;
            }
            $settings = get_option( 'opentranslation_settings', array() );
            $batch_size = isset( $settings['batch_size'] ) ? absint( $settings['batch_size'] ) : 10;
            $batch_size = max( 1, min( 50, $batch_size ) );
            $languages = TP_Storage_Adapter::get_target_languages();
            $ordered_languages = $this->get_language_run_order( $languages );
            Log::add( '', 'scheduler_run', 'languages: ' . wp_json_encode( $ordered_languages ) );
            if ( empty( $ordered_languages ) ) {
                return;
            }
            $this->cleanup_rate_limit();
            $round_limit = $this->get_round_limit();
            for ( $round = 0; $round < $round_limit; $round++ ) {
                $processed = false;
                foreach ( $ordered_languages as $language ) {
                    if ( ! $this->is_language_enabled( $language ) ) {
                        Log::add( '', 'scheduler_run', "language {$language} disabled" );
                        continue;
                    }
                    if ( $this->process_language( $language, $batch_size ) ) {
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
        } finally {
            $this->release_lock();
        }
        if ( $should_continue ) {
            self::maybe_enqueue_follow_up();
        }
    }
    private function process_language( $language, $batch_size ) {
        $translator = new Translator();
        if ( ! $this->is_rate_allowed() ) {
            Log::add( '', 'scheduler_run', 'rate limit reached' );
            return false;
        }
        $items = TP_Storage_Adapter::get_ready_untranslated( $language, $this->get_prefetch_size( $batch_size ) );
        Log::add( '', 'scheduler_run', "language {$language} batch 0 ready items: " . count( $items ) );
        if ( empty( $items ) ) {
            return false;
        }
        $translations = array();
        $request_units = 0;
        $passthrough_limit = $this->get_passthrough_batch_limit( $batch_size );
        $cached_items = array();
        $passthrough_items = array();
        $model_items = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['cached_translation'] ) ) {
                if ( count( $cached_items ) + count( $passthrough_items ) < $passthrough_limit ) {
                    $cached_items[] = $item;
                }
                continue;
            }
            if ( null !== Translator::get_passthrough_translation( $item['original'] ) ) {
                if ( count( $cached_items ) + count( $passthrough_items ) < $passthrough_limit ) {
                    $passthrough_items[] = $item;
                }
                continue;
            }
            if ( count( $model_items ) < $batch_size ) {
                $model_items[] = $item;
            }
        }
        foreach ( $passthrough_items as $item ) {
            $translations[] = array(
                'id'         => $item['id'],
                'translated' => $item['original'],
            );
        }
        foreach ( $cached_items as $item ) {
            $translations[] = array(
                'id'         => $item['id'],
                'translated' => $item['cached_translation'],
            );
        }
        if ( ! empty( $cached_items ) ) {
            Log::add( '', 'scheduler_run', "language {$language} batch 0 cached: " . count( $cached_items ) );
        }
        if ( ! empty( $passthrough_items ) ) {
            Log::add( '', 'scheduler_run', "language {$language} batch 0 passthrough: " . count( $passthrough_items ) );
        }
        if ( ! empty( $model_items ) ) {
            $results = $translator->translate_batch( $model_items, $language );
            $request_units = isset( $results['request_units'] ) ? (int) $results['request_units'] : 0;
            if ( ! empty( $results['translations'] ) ) {
                $translations = array_merge( $translations, $results['translations'] );
            }
        }
        $translated_count = count( $translations );
        Log::add( '', 'scheduler_run', "language {$language} batch 0 translations: {$translated_count}" );
        if ( ! empty( $translations ) ) {
            TP_Storage_Adapter::bulk_update_translations( $language, $translations );
        }
        $this->record_request_units( $request_units );
        return $translated_count > 0;
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
