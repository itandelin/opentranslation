<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 把 OpenTranslation AI 接入 TranslatePress 的机器翻译引擎机制。
 *
 * 插件对 TP 的介入面只有这两个官方 filter，别无其它。
 */
class TP_Integration {

    const ENGINE_SLUG = 'opentranslation_ai';

    public function __construct() {
        if ( TP_Storage_Adapter::is_tp_active() ) {
            add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'register_engine' ) );
            add_filter( 'trp_machine_translation_engines', array( $this, 'add_engine_option' ) );
        }
    }

    /**
     * 注册引擎类。
     *
     * TP 在 TRP_Machine_Translation_Tab::get_active_engine() 内触发本 filter
     * （class-machine-translation-tab.php:189），随后立刻 class_exists() 校验并实例化。
     * 因此在回调内部 require 是最可靠的时机——此刻 TRP_Machine_Translator 基类
     * 必然已加载，不存在早于基类的竞态。
     *
     * @param array $engines slug => 类名
     * @return array
     */
    public function register_engine( $engines ) {
        if ( class_exists( '\TRP_Machine_Translator' ) ) {
            require_once OPENTRANSLATION_PLUGIN_DIR . 'includes/class-tp-machine-translator.php';
            $engines[ self::ENGINE_SLUG ] = 'OpenTranslation\TP_Machine_Translator';
        }

        return $engines;
    }

    /**
     * 往 TP 设置页的引擎下拉框追加选项。
     *
     * @param array $engines 每项形如 array( 'value' => slug, 'label' => 显示名 )
     * @return array
     */
    public function add_engine_option( $engines ) {
        $engines[] = array(
            'value' => self::ENGINE_SLUG,
            'label' => 'OpenTranslation AI',
        );

        return $engines;
    }
}
