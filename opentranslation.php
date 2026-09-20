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
// 3：移除自建队列，删除 cache 与 rate_limit 两张表
define( 'OPENTRANSLATION_DB_VERSION', '3' );

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
    'class-request-budget',
    'class-log',
    'class-scope',
    'class-tp-storage-adapter',
    'class-model-identity',
    'class-model-health',
    'class-usage',
    'class-translator',
    'class-config-transfer',
    'class-admin-usage',
    'class-admin-glossary',
    'class-admin-transfer',
    'class-admin-ajax',
    'class-admin-actions',
    'class-admin',
    'class-plugin',
);

foreach ( $ot_files as $ot_file ) {
    require_once OPENTRANSLATION_PLUGIN_DIR . "includes/{$ot_file}.php";
}

/**
 * 把 OpenTranslation 注册为 TranslatePress 的机器翻译引擎。
 *
 * TP 在 plugins_loaded 优先级 2 实例化引擎（class-translate-press.php:531），
 * 所以注册必须早于该时机。
 *
 * 引擎类本身在 register_engine() 回调内懒加载：那一刻 TP 的基类必然已就位，
 * 不再依赖插件目录的字母序，也不需要事后强制重建 TP 的引擎实例。
 */
function opentranslation_register_tp_integration() {
    if ( ! class_exists( 'TRP_Translate_Press' ) ) {
        return;
    }

    require_once OPENTRANSLATION_PLUGIN_DIR . 'includes/class-tp-integration.php';
    new \OpenTranslation\TP_Integration();
}
add_action( 'plugins_loaded', 'opentranslation_register_tp_integration', 0 );

register_activation_hook( __FILE__, array( 'OpenTranslation\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OpenTranslation\Deactivator', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'OpenTranslation\Uninstaller', 'uninstall' ) );

add_action( 'plugins_loaded', array( 'OpenTranslation\Plugin', 'instance' ) );
