<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Model_Client {
    /**
     * @param array  $items          Array of strings to translate.
     * @param string $target_lang    Target language code.
     * @param string $system_prompt  System prompt.
     * @return array|\WP_Error
     */
    public function translate( $items, $target_lang, $system_prompt = '' );

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
