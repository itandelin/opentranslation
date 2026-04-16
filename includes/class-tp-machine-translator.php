<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TranslatePress Machine Translation Engine for OpenTranslation AI
 * 
 * This class integrates OpenTranslation AI models as a Machine Translation engine
 * within TranslatePress. When TP renders pages, it will call our AI models for
 * automatic translation.
 */
class TP_Machine_Translator extends \TRP_Machine_Translator {
    
    private $translator;
    private $protector;
    
    public function __construct( $settings ) {
        parent::__construct( $settings );
        
        $this->translator = new Translator();
        $this->protector = new Protector();
    }
    
    /**
     * Returns the API key (we use our own model configs instead)
     */
    public function get_api_key() {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        return ! empty( $models ) ? 'configured' : '';
    }
    
    /**
     * Test request to verify the engine is working
     */
    public function test_request() {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) ) {
            return new \WP_Error( 'no_models', 'No AI models configured in OpenTranslation.' );
        }

        $settings = TP_Storage_Adapter::get_settings();
        $source_language = isset( $settings['default-language'] ) ? $settings['default-language'] : 'en_US';
        $target_languages = TP_Storage_Adapter::get_target_languages();
        $target_language = ! empty( $target_languages ) ? reset( $target_languages ) : 'zh_CN';

        $diagnostic = $this->translator->test_connection( $target_language, $source_language );
        if ( is_wp_error( $diagnostic ) ) {
            return new \WP_Error(
                $diagnostic->get_error_code(),
                $diagnostic->get_error_message(),
                $this->format_test_diagnostic( $diagnostic->get_error_data() )
            );
        }

        return array(
            'response' => array(
                'code'    => 200,
                'message' => 'OpenTranslation diagnostic completed.',
            ),
            'body'     => $this->format_test_diagnostic( $diagnostic ),
        );
    }
    
    /**
     * Translate an array of strings
     * 
     * This is the main method called by TranslatePress during page rendering.
     * 
     * @param array  $new_strings          Strings to translate (with DOM node keys)
     * @param string $target_language_code Target language code (e.g., 'zh_CN')
     * @param string $source_language_code Source language code (e.g., 'en_US')
     * @return array                       Translated strings with same keys
     */
    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( empty( $new_strings ) || ! is_array( $new_strings ) ) {
            return array();
        }

        if ( ! $this->should_allow_live_translation() ) {
            return array();
        }
        
        // Call parent's verify_request_parameters
        if ( ! method_exists( $this, 'verify_request_parameters' ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
            return array();
        }
        
        // Check if models are configured
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) ) {
            return array();
        }
        
        // Convert strings to format expected by our translator
        $items = array();
        $index = 0;
        foreach ( $new_strings as $key => $string ) {
            $items[] = array(
                'id'       => $key, // Preserve original key for response mapping
                'original' => $string,
                'context'  => '',
            );
            $index++;
        }
        
        // Use our existing translator to handle the translation
        $result = $this->translator->translate_batch( $items, $target_language_code );
        
        if ( ! empty( $result['error'] ) ) {
            Log::add( '', 'tp_engine_error', $result['error'] );
        }
        
        // Build response array preserving original keys
        $translated_strings = array();
        if ( ! empty( $result['translations'] ) ) {
            foreach ( $result['translations'] as $translation ) {
                $original_key = $translation['id'];
                $translated_strings[ $original_key ] = $translation['translated'];
            }
        }
        
        return $translated_strings;
    }
    
    /**
     * Get supported languages
     * 
     * Our AI models support virtually all languages, so we return true for all.
     */
    public function get_supported_languages() {
        // Return all common language codes
        return array(
            'en', 'zh', 'zh-hans', 'zh-hant', 'es', 'fr', 'de', 'ja', 'ko',
            'pt', 'pt-br', 'pt-pt', 'ru', 'ar', 'hi', 'it', 'nl', 'tr',
            'pl', 'uk', 'vi', 'th', 'id', 'ms', 'fil', 'sv', 'no', 'da',
            'fi', 'cs', 'ro', 'hu', 'el', 'he', 'bn', 'ta', 'te', 'mr',
        );
    }
    
    /**
     * Check language availability
     * 
     * AI models support most languages, so we're permissive here.
     */
    public function check_languages_availability( $languages, $force_recheck = false ) {
        return true;
    }
    
    /**
     * Get engine-specific language codes
     */
    public function get_engine_specific_language_codes( $languages ) {
        $trp = \TRP_Translate_Press::get_trp_instance();
        $trp_languages = $trp->get_component( 'languages' );
        $iso_translation_codes = $trp_languages->get_iso_codes( $languages );
        $engine_codes = array();
        
        foreach ( $languages as $language ) {
            $iso_code = isset( $iso_translation_codes[ $language ] ) ? $iso_translation_codes[ $language ] : $language;
            // Convert to AI model format (simplified)
            $engine_codes[] = str_replace( '_', '-', strtolower( $iso_code ) );
        }
        
        return $engine_codes;
    }
    
    /**
     * Extra request validations
     */
    public function extra_request_validations( $to_language ) {
        // Check if models are configured
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        return ! empty( $models );
    }
    
    /**
     * Check if automatic translation is available
     */
    public function is_available( $languages = array() ) {
        $settings = $this->settings['trp_machine_translation_settings'] ?? array();
        $enabled = ( $settings['machine-translation'] ?? '' ) === 'yes';
        
        if ( ! $enabled ) {
            return false;
        }
        
        // Check if models are configured
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        if ( empty( $models ) ) {
            return false;
        }

        if ( ! $this->should_allow_live_translation() ) {
            return false;
        }
        
        return true;
    }

    private function format_test_diagnostic( $diagnostic ) {
        $json = wp_json_encode(
            $diagnostic,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ( false !== $json ) {
            return $json;
        }

        return print_r( $diagnostic, true );
    }

    private function should_allow_live_translation() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return true;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }

        return (bool) apply_filters( 'opentranslation_allow_frontend_live_translation', false );
    }
}
