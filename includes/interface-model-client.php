<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Model_Client {
    /**
     * @param array  $items          待翻译文本，按顺序排列
     * @param string $target_lang    目标语言的引擎码
     * @param string $system_prompt  系统提示词
     * @param string $source_lang    源语言的引擎码，空串表示不声明
     * @return array|\WP_Error 与 $items 等长且同序的译文数组
     */
    public function translate( $items, $target_lang, $system_prompt = '', $source_lang = '' );

    /**
     * Run a live connectivity test and return structured diagnostics.
     *
     * @param array  $items          Array of strings to translate.
     * @param string $target_lang    Target language code.
     * @param string $system_prompt  System prompt.
     * @return array
     */
    public function test_connection( $items, $target_lang, $system_prompt = '' );

    /**
     * 上一次 translate() 期间的 HTTP 尝试次数（含重试与切块）。
     *
     * @return int
     */
    public function get_last_request_units();

    /**
     * 上一次 translate() 期间累计的 token 用量，已归一化。
     *
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     */
    public function get_last_usage();
}
