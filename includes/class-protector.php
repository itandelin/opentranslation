<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Protector {
    private $tokens = array();
    private $counter = 0;
    private $patterns = array();

    public function __construct() {
        $this->patterns = array(
            '/<[^>]+>/',
            '/\[(\/?\w+[^\]]*)\]/',
            '/%[0-9]*\$?[sdifFeEgGxXocbn]/',
            '/\{\{[^}]+\}\}/',
            '/\$\{[^}]+\}/',
            '/&[\w#]+;/',
            '/https?:\/\/[^\s]+/i',
            '/[\w.-]+@[\w.-]+\.\w+/',
        );
    }

    public function protect( $text ) {
        $this->tokens = array();
        $this->counter = 0;
        $protected = $text;
        foreach ( $this->patterns as $pattern ) {
            $protected = preg_replace_callback( $pattern, function ( $matches ) {
                $this->counter++;
                $token = '<protect-' . $this->counter . '>';
                $this->tokens[ $token ] = $matches[0];
                return $token;
            }, $protected );
        }
        return $protected;
    }

    public function restore( $text ) {
        if ( empty( $this->tokens ) ) {
            return $text;
        }
        $restored = $text;
        foreach ( $this->tokens as $token => $original ) {
            $restored = str_replace( $token, $original, $restored );
        }
        return $restored;
    }

    /**
     * 校验模型返回的原始文本。
     *
     * 必须在 restore() 之前调用。restore() 会把占位符换回真实内容，
     * 之后再校验必然把所有 token 都报成缺失——这正是线上
     * restore_missing() 被触发 2746 次、造成 276 条尾部裸标签的原因。
     *
     * 两类问题都判失败：
     * 1. 本条目的占位符没有全部出现（模型吞掉了）
     * 2. 出现了不属于本条目的占位符（模型把别条目的串了过来）
     *
     * @param string $text 模型返回的原始文本，尚未 restore
     * @return true|array 通过返回 true，否则返回问题占位符列表
     */
    public function validate( $text ) {
        $problems = array();

        foreach ( array_keys( $this->tokens ) as $token ) {
            if ( strpos( $text, $token ) === false ) {
                $problems[] = $token;
            }
        }

        if ( preg_match_all( '/<protect-\d+>/', $text, $matches ) ) {
            foreach ( $matches[0] as $found ) {
                if ( ! isset( $this->tokens[ $found ] ) && ! in_array( $found, $problems, true ) ) {
                    $problems[] = $found;
                }
            }
        }

        return empty( $problems ) ? true : $problems;
    }

    /**
     * restore() 之后是否仍残留占位符。
     *
     * validate() 已拦下已知的缺失与串号情况，这里是兜底：
     * 任何漏过前一道的占位符都不应写入译文。
     *
     * 已知理论误报：原文本身字面包含 <protect-N> 时会误判失败。
     * 该条目会被跳过而非写入破损内容，符合「宁可不翻译」的设计取向。
     *
     * @param string $text 已 restore 的文本
     * @return bool
     */
    public static function has_residual_placeholder( $text ) {
        return 1 === preg_match( '/<protect-\d+>/', (string) $text );
    }

    public function get_tokens() {
        return $this->tokens;
    }
}
