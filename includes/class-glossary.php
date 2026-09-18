<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Glossary {
    const OPTION = 'opentranslation_glossary';

    /**
     * 获取全部术语。
     *
     * @return array
     */
    public static function get_all() {
        $terms = get_option( self::OPTION );
        return is_array( $terms ) ? $terms : array();
    }

    /**
     * 保存全部术语（不 autoload）。
     *
     * @param array $terms
     * @return bool
     */
    public static function save( array $terms ) {
        return update_option( self::OPTION, $terms, false );
    }

    /**
     * 校验并规范化单条术语。
     *
     * @param array $raw 原始术语数据
     * @return array|\WP_Error  规范化后的术语数组，或 WP_Error
     */
    public static function sanitize_term( array $raw ) {
        $source = isset( $raw['source'] ) ? trim( $raw['source'] ) : '';
        $target = isset( $raw['target'] ) ? trim( $raw['target'] ) : '';

        if ( '' === $source ) {
            return new \WP_Error( 'empty_source', __( 'Source must not be empty.', 'opentranslation' ) );
        }

        if ( mb_strlen( $source ) > 100 ) {
            return new \WP_Error( 'source_too_long', __( 'Source too long (max 100).', 'opentranslation' ) );
        }

        // 必须至少含一个字母
        if ( ! preg_match( '/\p{L}/u', $source ) ) {
            return new \WP_Error( 'source_no_letter', __( 'Source must contain at least one letter.', 'opentranslation' ) );
        }

        // 不得含尖括号
        if ( false !== strpos( $source, '<' ) || false !== strpos( $source, '>' ) ) {
            return new \WP_Error( 'source_angle_brackets', __( 'Source must not contain &lt; or &gt;.', 'opentranslation' ) );
        }

        // 不得匹配 protect
        if ( 1 === preg_match( '/protect/i', $source ) ) {
            return new \WP_Error( 'source_protect', __( 'Source must not contain "protect".', 'opentranslation' ) );
        }

        // source 与 target 都不得匹配 TP 占位符模式
        $tp_pattern = '/\d+TP\d+T/';
        if ( 1 === preg_match( $tp_pattern, $source ) ) {
            return new \WP_Error( 'source_tp_pattern', __( 'Source matches TP placeholder pattern.', 'opentranslation' ) );
        }

        if ( '' === $target ) {
            $target = $source;
        }

        if ( mb_strlen( $target ) > 200 ) {
            return new \WP_Error( 'target_too_long', __( 'Target too long (max 200).', 'opentranslation' ) );
        }

        if ( 1 === preg_match( $tp_pattern, $target ) ) {
            return new \WP_Error( 'target_tp_pattern', __( 'Target matches TP placeholder pattern.', 'opentranslation' ) );
        }

        $language = isset( $raw['language'] ) ? trim( $raw['language'] ) : '';
        if ( '' !== $language && ! preg_match( '/^[a-zA-Z_]{2,10}$/', $language ) ) {
            return new \WP_Error( 'invalid_language', __( 'Invalid language code.', 'opentranslation' ) );
        }

        $note = isset( $raw['note'] ) ? sanitize_text_field( $raw['note'] ) : '';
        if ( mb_strlen( $note ) > 200 ) {
            $note = mb_substr( $note, 0, 200 );
        }

        return array(
            'source'         => $source,
            'target'         => $target,
            'language'       => $language,
            'case_sensitive' => ! empty( $raw['case_sensitive'] ),
            'whole_word'     => ! empty( $raw['whole_word'] ),
            'note'           => $note,
        );
    }

    /**
     * 按语言筛选术语并排序（通用术语 + 语言专属术语，按长度降序）。
     *
     * @param string      $language 目标语言码（如 'zh_CN'）
     * @param array|null  $terms    术语列表；null 时从 option 读取
     * @return array
     */
    public static function for_language( $language, array $terms = null ) {
        if ( null === $terms ) {
            $terms = self::get_all();
        }

        $filtered = array();
        foreach ( $terms as $term ) {
            if ( ! is_array( $term ) || ! isset( $term['source'] ) ) {
                continue;
            }
            $lang = isset( $term['language'] ) ? $term['language'] : '';
            if ( '' === $lang || $lang === $language ) {
                $filtered[] = $term;
            }
        }

        // 按 mb_strlen(source) 降序
        usort( $filtered, function ( $a, $b ) {
            $len_a = mb_strlen( isset( $a['source'] ) ? $a['source'] : '' );
            $len_b = mb_strlen( isset( $b['source'] ) ? $b['source'] : '' );
            return $len_b - $len_a;
        } );

        return $filtered;
    }

    /**
     * 构建单个交替正则。
     *
     * 术语应已按长度降序排列（由 for_language 保证），确保长优先。
     * 返回空串表示无术语。
     *
     * @param array $terms 已排序的术语列表
     * @return string      正则表达式，无术语时返回 ''
     */
    public static function build_pattern( array $terms ) {
        if ( empty( $terms ) ) {
            return '';
        }

        $parts = array();
        foreach ( $terms as $term ) {
            $quoted = preg_quote( $term['source'], '/' );
            if ( $term['whole_word'] ) {
                $quoted = '(?<![\p{L}\p{N}_])' . $quoted . '(?![\p{L}\p{N}_])';
            }
            $parts[] = $term['case_sensitive']
                ? '(?:' . $quoted . ')'
                : '(?i:' . $quoted . ')';
        }

        return '/' . implode( '|', $parts ) . '/u';
    }
}