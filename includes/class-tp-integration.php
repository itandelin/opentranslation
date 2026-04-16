<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integrates OpenTranslation AI as a Machine Translation engine in TranslatePress
 */
class TP_Integration {
    
    public function __construct() {
        if ( TP_Storage_Adapter::is_tp_active() ) {
            add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'register_engine' ) );
            add_filter( 'trp_machine_translation_engines', array( $this, 'add_engine_option' ) );
        }
    }
    
    /**
     * Register our engine class with TranslatePress
     */
    public function register_engine( $engines ) {
        $engines['opentranslation_ai'] = 'OpenTranslation\TP_Machine_Translator';
        return $engines;
    }
    
    /**
     * Add our engine to the dropdown in TP settings
     */
    public function add_engine_option( $engines ) {
        $engines[] = array(
            'value' => 'opentranslation_ai',
            'label' => 'OpenTranslation AI',
        );
        return $engines;
    }
}
