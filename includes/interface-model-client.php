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
}
