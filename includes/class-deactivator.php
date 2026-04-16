<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Deactivator {
    public static function deactivate() {
        if ( class_exists( '\OpenTranslation\Scheduler' ) ) {
            Scheduler::unschedule_all();
            return;
        }

        $timestamp = wp_next_scheduled( 'opentranslation_process_queue' );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'opentranslation_process_queue' );
            $timestamp = wp_next_scheduled( 'opentranslation_process_queue' );
        }

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'opentranslation_as_process' );
        }
    }
}
