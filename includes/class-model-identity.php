<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 模型唯一标识。
 *
 * 健康检查、用量统计、配置导入都需要在不加密的存储里指代一个模型。
 * 用 md5 而非明文，避免 base_url 暴露在 option 与自建表中。
 */
class Model_Identity {

    /**
     * @param array $config 单个模型配置
     * @return string 32 位 md5
     */
    public static function key( array $config ) {
        $provider = isset( $config['provider'] ) ? (string) $config['provider'] : 'openai';
        $model    = isset( $config['model'] ) ? (string) $config['model'] : '';
        return md5( $provider . '|' . $model . '|' . self::normalized_base_url( $config ) );
    }

    /**
     * 供后台展示的可读名。非官方地址附主机名以区分网关。
     */
    public static function label( array $config ) {
        $provider = isset( $config['provider'] ) ? (string) $config['provider'] : 'openai';
        $model    = isset( $config['model'] ) ? (string) $config['model'] : '';
        $base_url = self::normalized_base_url( $config );
        $label    = $provider . ' / ' . $model;

        if ( $base_url !== Translator::default_base_url( $provider ) ) {
            $host = wp_parse_url( $base_url, PHP_URL_HOST );
            if ( ! empty( $host ) ) {
                $label .= ' @ ' . $host;
            }
        }

        return $label;
    }

    private static function normalized_base_url( array $config ) {
        $provider = isset( $config['provider'] ) ? (string) $config['provider'] : 'openai';
        $base_url = isset( $config['base_url'] ) ? trim( (string) $config['base_url'] ) : '';
        if ( '' === $base_url ) {
            $base_url = Translator::default_base_url( $provider );
        }
        return trailingslashit( $base_url );
    }
}
