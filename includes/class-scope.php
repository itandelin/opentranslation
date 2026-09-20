<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 翻译范围控制。
 *
 * 按「归属桶」过滤：桶 = 各 post_type + 特殊桶 __unlinked__（首页/归档/搜索/404 等非单篇页面）。
 *
 * 过滤挂在 TranslatePress 的 `trp_allow_machine_translation_for_string` 上，
 * 由 TP 在前台渲染每个字符串时询问；返回 false 即该字符串不送机器翻译。
 */
class Scope {

    const UNLINKED = '__unlinked__';

    /**
     * 注册到 TranslatePress 的逐条翻译开关。
     *
     * 由 Plugin::init() 在前后台都调用一次。
     */
    public static function register() {
        add_filter( 'trp_allow_machine_translation_for_string', array( __CLASS__, 'allow_for_current_page' ), 10, 2 );
    }

    /**
     * 按当前渲染页面的类型决定该字符串是否送机器翻译。
     *
     * 语义变更说明：旧实现按字符串在 trp_original_meta 中登记的来源归属判定，
     * 新实现按当前正在渲染的页面类型判定。同一字符串出现在多种页面类型时，
     * 两者结论可能不同。新语义对「按页面类型控成本」这一实际诉求更贴切。
     *
     * @param bool   $allow  TP 与其它插件的既有判定
     * @param string $string 待翻译的原文
     * @return bool
     */
    public static function allow_for_current_page( $allow, $string = '' ) {
        // 别人已经否决过就不再翻案
        if ( ! $allow ) {
            return $allow;
        }

        // 当前目标语言由 TP 在渲染期间写入全局变量；拿不到就不干预
        $language = isset( $GLOBALS['TRP_LANGUAGE'] ) ? (string) $GLOBALS['TRP_LANGUAGE'] : '';
        if ( '' === $language ) {
            return $allow;
        }

        $config = self::get( $language );
        if ( 'all' === $config['mode'] ) {
            return $allow;
        }

        // 仅发布：草稿/私密等状态的单篇内容一律不翻（normalize_config 已保证只有 include 模式能开）
        if ( $config['published_only'] && self::query_ready() && is_singular() && 'publish' !== get_post_status() ) {
            return false;
        }

        $in_bucket = in_array( self::current_page_bucket(), $config['buckets'], true );

        return 'include' === $config['mode'] ? $in_bucket : ! $in_bucket;
    }

    /**
     * 规范化后的某语言范围配置。
     *
     * @param string $language
     * @return array{mode:string,buckets:array,published_only:bool}
     */
    public static function get( $language ) {
        $settings = get_option( 'opentranslation_settings', array() );
        $all      = isset( $settings['scope'] ) && is_array( $settings['scope'] ) ? $settings['scope'] : array();
        $config   = isset( $all[ $language ] ) && is_array( $all[ $language ] ) ? $all[ $language ] : array();

        return self::normalize_config( $config );
    }

    /**
     * sanitize_settings 委托：按语言白名单规范化，告警写入 $warnings 引用。
     *
     * @param array $input     表单提交的 scope 数组
     * @param array $languages 目标语言白名单
     * @param array $warnings  引用，写入逐条告警文本
     * @return array 规范化后的 scope 配置
     */
    public static function sanitize( array $input, array $languages, array &$warnings ) {
        $output = array();

        foreach ( $languages as $language ) {
            $raw    = isset( $input[ $language ] ) && is_array( $input[ $language ] ) ? $input[ $language ] : array();
            $config = self::normalize_config( $raw );

            // 桶为空时 include/exclude 都没有意义，回落 all 并告警
            if ( 'all' !== $config['mode'] && empty( $config['buckets'] ) ) {
                $config['mode'] = 'all';
                $warnings[] = sprintf(
                    /* translators: %s is a language code. */
                    __( '%s: nothing selected; fell back to all content.', 'opentranslation' ),
                    $language
                );
            }

            $output[ $language ] = $config;
        }

        return $output;
    }

    /**
     * sanitize_settings 委托：规范化 + 逐条告警。
     *
     * @param array $input     表单提交的 scope 数组
     * @param array $languages 目标语言白名单
     * @return array 规范化后的 scope 配置
     */
    public static function sanitize_settings( array $input, array $languages ) {
        $warnings = array();
        $output   = self::sanitize( $input, $languages, $warnings );

        foreach ( $warnings as $warning_text ) {
            add_settings_error( 'opentranslation_settings', 'scope_warn', $warning_text, 'warning' );
        }

        return $output;
    }

    /**
     * 当前渲染页面归属哪个桶。
     *
     * 单篇内容归自身 post_type；首页/归档/搜索/404 以及主查询之外的场景
     * （后台、cron、REST）统一归到未关联桶。
     *
     * @return string
     */
    private static function current_page_bucket() {
        if ( ! self::query_ready() ) {
            return self::UNLINKED;
        }

        if ( is_singular() ) {
            $post_type = get_post_type();
            return $post_type ? (string) $post_type : self::UNLINKED;
        }

        return self::UNLINKED;
    }

    /**
     * 主查询是否已就绪。
     *
     * 未走到 `wp` 动作时条件标签既会报 notice，结果也不可信。
     *
     * @return bool
     */
    private static function query_ready() {
        return function_exists( 'did_action' )
            && did_action( 'wp' )
            && function_exists( 'is_singular' );
    }

    /**
     * 规范化单语言配置。
     *
     * @param array $config
     * @return array{mode:string,buckets:array,published_only:bool}
     */
    private static function normalize_config( array $config ) {
        $mode = isset( $config['mode'] ) ? (string) $config['mode'] : 'all';
        if ( ! in_array( $mode, array( 'all', 'include', 'exclude' ), true ) ) {
            $mode = 'all';
        }

        $buckets = array();
        if ( isset( $config['buckets'] ) && is_array( $config['buckets'] ) ) {
            foreach ( $config['buckets'] as $bucket ) {
                $bucket = (string) $bucket;
                if ( self::UNLINKED === $bucket || 1 === preg_match( '/^[a-z0-9_-]{1,32}$/', $bucket ) ) {
                    $buckets[] = $bucket;
                }
            }
            $buckets = array_values( array_unique( $buckets ) );
        }

        // published_only 只在 include 模式有意义：排除「已发布的 product」语义混乱，不支持
        $published_only = ! empty( $config['published_only'] ) && 'exclude' !== $mode;

        return array(
            'mode'           => $mode,
            'buckets'        => $buckets,
            'published_only' => $published_only,
        );
    }
}
