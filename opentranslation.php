<?php
/**
 * Plugin Name: OpenTranslation
 * Description: AI translation agent for TranslatePress. Supports OpenAI and Claude models with caching, retry, and async queue.
 * Version: 1.0.0
 * Author: Mr.T
 * Text Domain: opentranslation
 * Domain Path: /languages
 * Language: zh_CN
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Plugin URI: https://github.com/itandelin/opentranslation
 * Author URI: https://www.74110.net
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OPENTRANSLATION_VERSION', '1.0.0' );
define( 'OPENTRANSLATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPENTRANSLATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OPENTRANSLATION_DB_VERSION', '2' );

$ot_files = array(
    'class-activator',
    'class-deactivator',
    'class-uninstaller',
    'class-encrypted-options',
    'class-url-guard',
    'interface-model-client',
    'class-openai-client',
    'class-claude-client',
    'class-glossary',
    'class-protector',
    'class-cache',
    'class-log',
    'class-tp-storage-adapter',
    'class-model-identity',
    'class-usage',
    'class-translator',
    'class-scheduler',
    'class-admin-usage',
    'class-admin-glossary',
    'class-admin-ajax',
    'class-admin-actions',
    'class-admin',
    'class-plugin',
);

foreach ( $ot_files as $ot_file ) {
    require_once OPENTRANSLATION_PLUGIN_DIR . "includes/{$ot_file}.php";
}

/**
 * Register OpenTranslation as a TranslatePress engine as early as possible.
 *
 * TranslatePress initializes the active machine translator on `plugins_loaded`
 * priority 2, so we need the engine mapping in place before that.
 */
function opentranslation_register_tp_integration() {
    if ( ! class_exists( 'TRP_Translate_Press' ) ) {
        return;
    }

    require_once OPENTRANSLATION_PLUGIN_DIR . 'includes/class-tp-integration.php';
    new \OpenTranslation\TP_Integration();
}
add_action( 'plugins_loaded', 'opentranslation_register_tp_integration', 0 );

/**
 * Load the TranslatePress engine class after TP has loaded its base classes,
 * but before we force a final engine refresh.
 */
function opentranslation_load_tp_machine_translator() {
    if ( class_exists( 'TRP_Machine_Translator' ) ) {
        require_once OPENTRANSLATION_PLUGIN_DIR . 'includes/class-tp-machine-translator.php';
    }
}
add_action( 'plugins_loaded', 'opentranslation_load_tp_machine_translator', 2 );

/**
 * Refresh TranslatePress' cached machine translator instance so both the
 * credential test and runtime translations use the OpenTranslation engine.
 */
function opentranslation_refresh_tp_machine_translator() {
    if ( ! class_exists( 'TRP_Translate_Press' ) || ! class_exists( 'OpenTranslation\\TP_Machine_Translator' ) ) {
        return;
    }

    $settings = get_option( 'trp_machine_translation_settings', array() );
    if ( ( $settings['translation-engine'] ?? '' ) !== 'opentranslation_ai' ) {
        return;
    }

    $trp = \TRP_Translate_Press::get_trp_instance();
    if ( ! $trp || ! method_exists( $trp, 'init_machine_translation' ) ) {
        return;
    }

    $machine_translator = $trp->get_component( 'machine_translator' );
    if ( $machine_translator instanceof \OpenTranslation\TP_Machine_Translator ) {
        return;
    }

    if ( method_exists( $trp, 'init_machine_translation' ) ) {
        $trp->init_machine_translation();
    }
}
add_action( 'plugins_loaded', 'opentranslation_refresh_tp_machine_translator', 3 );

register_activation_hook( __FILE__, array( 'OpenTranslation\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OpenTranslation\Deactivator', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'OpenTranslation\Uninstaller', 'uninstall' ) );

add_action( 'plugins_loaded', array( 'OpenTranslation\Plugin', 'instance' ) );
