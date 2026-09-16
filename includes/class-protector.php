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
            // TranslatePress 自身的占位符（TRP_Machine_Translator::translate() 生成）
            // 形如 1TP1T、1TP2T。必须放在 HTML 模式之后，
            // 否则本模式产出的 <protect-N> 会被 HTML 模式二次吞掉。
            '/\d+TP\d+T/',
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
     * 旧版的「末尾拼接兜底」被触发 2746 次、造成 276 条尾部裸标签的原因。
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

        // 容许斜杠：模型可能输出 </protect-N>（把占位符当标签闭合）。
        // 这种形式不在 tokens 里，会被下面的 isset 判定为无主占位符 → 判失败。
        if ( preg_match_all( '#</?protect-\d+>#', $text, $matches ) ) {
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
     * 正则必须容许斜杠：模型会把占位符当成 HTML 标签「闭合」，
     * 产出 </protect-N>。线上实测 4 条译文因此漏过校验落库。
     *
     * @param string $text 已 restore 的文本
     * @return bool
     */
    public static function has_residual_placeholder( $text ) {
        return 1 === preg_match( '#</?protect-\d+>#', (string) $text );
    }

    public function get_tokens() {
        return $this->tokens;
    }
}
