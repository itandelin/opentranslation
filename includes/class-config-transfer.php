<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 配置导出与导入。
 *
 * 导出格式冻结为 format_version=1，字段见 docs/plan/2026-09-17-P2-5-config-transfer.md。
 * 加字段必须递增 format_version 并写迁移说明——validate() 对版本做严格等值判断。
 *
 * 不导出运行时状态：disabled_languages（语言集合因站点而异）、
 * 模型健康、用量、日志、缓存。
 *
 * validate() 刻意不读任何 option：目标站现状由参数传入，
 * 使校验逻辑成为纯函数，可在零依赖脚手架里单测。
 */
class Config_Transfer {

    const FORMAT_VERSION = 1;

    /**
     * 允许跨站迁移的 settings 键。其余键（如 disabled_languages）保留目标站原值。
     *
     * batch_size / cron_interval / rate_limit_per_minute 随自建队列一并移除：
     * 翻译节奏现在由 Request_Budget 按请求决定，不再有可迁移的调度配置。
     */
    const TRANSFER_SETTING_KEYS = array(
        'system_prompt',
        'plugin_language',
        'scope',
        'model_pricing',
    );

    const MODEL_FIELDS = array( 'provider', 'base_url', 'model', 'priority', 'temperature', 'max_tokens' );

    /**
     * 组装导出数组。
     *
     * @param bool $include_keys 是否包含明文 API Key
     * @return array
     */
    public static function export( $include_keys = false ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();

        $transfer_settings = array();
        foreach ( self::TRANSFER_SETTING_KEYS as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $transfer_settings[ $key ] = $settings[ $key ];
            }
        }

        return array(
            'format_version'    => self::FORMAT_VERSION,
            'plugin_version'    => defined( 'OPENTRANSLATION_VERSION' ) ? OPENTRANSLATION_VERSION : '',
            'exported_at'       => gmdate( 'c' ),
            'site_url'          => home_url(),
            'includes_api_keys' => (bool) $include_keys,
            'settings'          => $transfer_settings,
            'models'            => self::export_models( (bool) $include_keys ),
            'glossary'          => Glossary::get_all(),
        );
    }

    /**
     * 模型列表：默认抹掉 api_key。
     */
    private static function export_models( $include_keys ) {
        $models = Encrypted_Options::get( 'opentranslation_models', array() );
        $models = is_array( $models ) ? $models : array();

        $out = array();
        foreach ( $models as $config ) {
            if ( ! is_array( $config ) ) {
                continue;
            }
            $row = array();
            foreach ( self::MODEL_FIELDS as $field ) {
                $row[ $field ] = isset( $config[ $field ] ) ? $config[ $field ] : '';
            }
            $row['api_key'] = $include_keys && isset( $config['api_key'] ) ? (string) $config['api_key'] : '';
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 校验并规范化导入数据。纯函数：不读 option、不写任何状态。
     *
     * @param array $data            json_decode 后的顶层数组
     * @param array $existing_models 目标站现有模型（含明文 api_key），用于按 identity 沿用密钥
     * @param array $languages       目标站的 TP 目标语言列表，用于规范化 scope
     * @return array{ok:bool,reason:string,normalized:array,summary:array,skipped:array,key_sources:array}
     */
    public static function validate( $data, array $existing_models = array(), array $languages = array() ) {
        $rejection = self::check_format( $data );
        if ( null !== $rejection ) {
            return $rejection;
        }

        $skipped     = array();
        $key_sources = array();
        $has_keys    = ! empty( $data['includes_api_keys'] );

        $models = self::normalize_models(
            isset( $data['models'] ) && is_array( $data['models'] ) ? $data['models'] : array(),
            $existing_models,
            $has_keys,
            $skipped,
            $key_sources
        );

        $glossary = self::normalize_glossary(
            isset( $data['glossary'] ) && is_array( $data['glossary'] ) ? $data['glossary'] : array(),
            $skipped
        );

        $settings = self::normalize_settings(
            isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
            $languages
        );

        return array(
            'ok'          => true,
            'reason'      => '',
            'normalized'  => array(
                'settings' => $settings,
                'models'   => $models,
                'glossary' => $glossary,
            ),
            'summary'     => self::build_summary( $data, $models, $glossary, $settings, $skipped, $has_keys ),
            'skipped'     => $skipped,
            // 与 normalized['models'] 同序。刻意放在 normalized 之外：
            // apply() 只写 normalized，这个纯展示字段不该落库。
            'key_sources' => $key_sources,
        );
    }

    /**
     * 格式守卫：不是本插件的配置文件、或版本不等值时返回 rejection，否则返回 null。
     */
    private static function check_format( $data ) {
        if ( ! is_array( $data ) || ! isset( $data['format_version'] ) ) {
            return self::rejection( __( 'The file has no format_version and is not a valid OpenTranslation config file.', 'opentranslation' ) );
        }

        if ( self::FORMAT_VERSION !== (int) $data['format_version'] ) {
            return self::rejection( sprintf(
                /* translators: 1: file format version, 2: supported format version. */
                __( 'The config file format_version is %1$s, but this plugin only supports %2$d.', 'opentranslation' ),
                (string) $data['format_version'],
                self::FORMAT_VERSION
            ) );
        }

        return null;
    }

    /**
     * 预览页要展示的计数与来源信息。
     */
    private static function build_summary( $data, array $models, array $glossary, array $settings, array $skipped, $has_keys ) {
        $missing_keys = 0;
        foreach ( $models as $model ) {
            if ( '' === $model['api_key'] ) {
                $missing_keys++;
            }
        }

        return array(
            'models'            => count( $models ),
            'glossary'          => count( $glossary ),
            'settings'          => count( $settings ),
            'scope_languages'   => isset( $settings['scope'] ) ? count( $settings['scope'] ) : 0,
            'missing_keys'      => $missing_keys,
            'includes_api_keys' => $has_keys,
            'skipped'           => count( $skipped ),
            'site_url'          => isset( $data['site_url'] ) ? (string) $data['site_url'] : '',
            'exported_at'       => isset( $data['exported_at'] ) ? (string) $data['exported_at'] : '',
            'plugin_version'    => isset( $data['plugin_version'] ) ? (string) $data['plugin_version'] : '',
        );
    }

    /**
     * 写入配置。settings 只覆盖 TRANSFER_SETTING_KEYS，其余键保留目标站原值。
     *
     * @param array $normalized validate() 返回的 normalized
     * @return bool
     */
    public static function apply( array $normalized ) {
        $existing = get_option( 'opentranslation_settings', array() );
        $existing = is_array( $existing ) ? $existing : array();

        $incoming = isset( $normalized['settings'] ) && is_array( $normalized['settings'] )
            ? $normalized['settings']
            : array();

        update_option( 'opentranslation_settings', array_merge( $existing, $incoming ) );

        if ( isset( $normalized['models'] ) && is_array( $normalized['models'] ) ) {
            Encrypted_Options::set( 'opentranslation_models', $normalized['models'] );
        }

        if ( isset( $normalized['glossary'] ) && is_array( $normalized['glossary'] ) ) {
            Glossary::save( $normalized['glossary'] );
        }
        return true;
    }

    /**
     * 模型规范化：base_url 被 URL_Guard 拒绝的整条跳过；
     * 不含密钥时按 Model_Identity::key() 匹配目标站已有模型沿用密钥。
     */
    private static function normalize_models( array $rows, array $existing_models, $has_keys, array &$skipped, array &$key_sources ) {
        $existing_by_key = array();
        foreach ( $existing_models as $config ) {
            if ( is_array( $config ) ) {
                $existing_by_key[ Model_Identity::key( $config ) ] = $config;
            }
        }

        $out = array();
        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $base_url = isset( $row['base_url'] ) ? trim( (string) $row['base_url'] ) : '';
            if ( '' !== $base_url ) {
                $reason = URL_Guard::get_rejection_reason( $base_url );
                if ( '' !== $reason ) {
                    $skipped[] = array(
                        'type'   => 'model',
                        'label'  => isset( $row['model'] ) ? (string) $row['model'] : (string) $index,
                        'reason' => $reason,
                    );
                    continue;
                }
            }

            $config = array(
                'provider'    => 'claude' === ( isset( $row['provider'] ) ? $row['provider'] : '' ) ? 'claude' : 'openai',
                'base_url'    => $base_url,
                'model'       => isset( $row['model'] ) ? sanitize_text_field( (string) $row['model'] ) : '',
                'priority'    => isset( $row['priority'] ) ? absint( $row['priority'] ) : 10,
                'temperature' => Admin::clamp_temperature( isset( $row['temperature'] ) ? $row['temperature'] : 0.3 ),
                'max_tokens'  => isset( $row['max_tokens'] ) ? absint( $row['max_tokens'] ) : 0,
            );

            $resolved          = self::resolve_api_key( $row, $config, $existing_by_key, $has_keys );
            $config['api_key'] = $resolved[0];
            $key_sources[]     = $resolved[1];
            $out[]             = $config;
        }

        return $out;
    }

    /**
     * 密钥来源：文件带密钥则用文件的；否则按 identity 沿用目标站现有密钥；都没有则留空。
     */
    /**
     * 密钥来源：文件带密钥则用文件的；否则按 identity 沿用目标站现有密钥；都没有则留空。
     *
     * 连来源一起返回而不只返回值：预览页要如实区分「文件提供」与「沿用本站」，
     * 只靠 includes_api_keys 反推会把「文件声明含密钥、但该条为空、实际沿用了本站」
     * 误标成「文件提供」。
     *
     * @return array array( api_key, from_file|reused|missing )
     */
    private static function resolve_api_key( array $row, array $config, array $existing_by_key, $has_keys ) {
        if ( $has_keys && isset( $row['api_key'] ) && '' !== trim( (string) $row['api_key'] ) ) {
            return array( trim( (string) $row['api_key'] ), 'from_file' );
        }

        $key = Model_Identity::key( $config );
        if ( isset( $existing_by_key[ $key ]['api_key'] ) && '' !== (string) $existing_by_key[ $key ]['api_key'] ) {
            return array( (string) $existing_by_key[ $key ]['api_key'], 'reused' );
        }

        return array( '', 'missing' );
    }

    /**
     * 术语规范化：走 Glossary::sanitize_term()，被拒的跳过并记原因。
     */
    private static function normalize_glossary( array $rows, array &$skipped ) {
        $out = array();
        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $term = Glossary::sanitize_term( $row );
            if ( is_wp_error( $term ) ) {
                $skipped[] = array(
                    'type'   => 'term',
                    'label'  => isset( $row['source'] ) ? (string) $row['source'] : (string) $index,
                    'reason' => $term->get_error_message(),
                );
                continue;
            }
            $out[] = $term;
        }
        return $out;
    }

    /**
     * settings 规范化：只保留 TRANSFER_SETTING_KEYS，数值走与表单保存相同的规则。
     */
    private static function normalize_settings( array $raw, array $languages ) {
        $out = Admin::sanitize_numeric_settings( $raw );

        $allowed_languages = array( 'zh_CN', 'en_US' );
        $plugin_language   = isset( $raw['plugin_language'] ) ? sanitize_text_field( (string) $raw['plugin_language'] ) : 'zh_CN';
        $out['plugin_language'] = in_array( $plugin_language, $allowed_languages, true ) ? $plugin_language : 'zh_CN';

        // 用纯规范化的 sanitize()，不用 sanitize_settings()：
        // 后者会 add_settings_error，导入校验阶段不该产生设置页告警。
        $raw_scope      = isset( $raw['scope'] ) && is_array( $raw['scope'] ) ? $raw['scope'] : array();
        $scope_warnings = array();
        $out['scope']   = Scope::sanitize( $raw_scope, $languages, $scope_warnings );

        $out['model_pricing'] = self::normalize_pricing(
            isset( $raw['model_pricing'] ) && is_array( $raw['model_pricing'] ) ? $raw['model_pricing'] : array()
        );

        return $out;
    }

    /**
     * 单价表：key 必须是 32 位 md5（Model_Identity::key），值取非负浮点。
     */
    private static function normalize_pricing( array $raw ) {
        $out = array();
        foreach ( $raw as $model_key => $prices ) {
            if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', (string) $model_key ) || ! is_array( $prices ) ) {
                continue;
            }
            $row = array();
            foreach ( array( 'prompt', 'completion' ) as $field ) {
                if ( isset( $prices[ $field ] ) ) {
                    $row[ $field ] = max( 0.0, (float) $prices[ $field ] );
                }
            }
            if ( ! empty( $row ) ) {
                $out[ (string) $model_key ] = $row;
            }
        }
        return $out;
    }

    private static function rejection( $reason ) {
        return array(
            'ok'         => false,
            'reason'     => $reason,
            'normalized' => array(),
            'summary'    => array(),
            'skipped'    => array(),
        );
    }
}
