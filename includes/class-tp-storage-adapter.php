<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TranslatePress 设置的只读访问器。
 *
 * 本类曾经直接读写 TP 的 trp_dictionary_* 字典表，服务于一套自建翻译队列。
 * 那套队列已移除——译文的读写完全交回 TP，插件只在需要时读取 TP 的设置，
 * 不再触碰 TP 的任何数据表。
 */
class TP_Storage_Adapter {

    private static $trp_settings = null;

    public static function is_tp_active() {
        return class_exists( 'TRP_Translate_Press' );
    }

    /**
     * TP 的完整设置数组。
     */
    public static function get_settings() {
        if ( null === self::$trp_settings ) {
            if ( function_exists( 'trp_get_settings_options' ) ) {
                self::$trp_settings = trp_get_settings_options();
            } elseif ( class_exists( 'TRP_Settings' ) ) {
                $settings = new \TRP_Settings();
                self::$trp_settings = $settings->get_settings();
            } else {
                self::$trp_settings = get_option( 'trp_settings', array() );
            }
        }

        return self::$trp_settings;
    }

    /**
     * 已发布的目标语言（不含默认语言）。
     */
    public static function get_target_languages() {
        $settings = self::get_settings();
        if ( empty( $settings['publish-languages'] ) ) {
            return array();
        }

        $default_lang = isset( $settings['default-language'] ) ? $settings['default-language'] : 'en_US';

        return array_values( array_filter( $settings['publish-languages'], function ( $lang ) use ( $default_lang ) {
            return $lang !== $default_lang;
        } ) );
    }

    /**
     * 目标语言白名单化：不在发布语言列表内一律置空。
     *
     * 注意语言码含大写（zh_CN），不能用 sanitize_key 处理。
     *
     * @param string $language 待净化的语言码
     * @return string 白名单内的语言码，否则空串
     */
    public static function sanitize_language( $language ) {
        $language = trim( (string) $language );
        if ( '' === $language ) {
            return '';
        }

        return in_array( $language, self::get_target_languages(), true ) ? $language : '';
    }
}
