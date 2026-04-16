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

    public function validate( $text ) {
        $missing = array();
        foreach ( array_keys( $this->tokens ) as $token ) {
            if ( strpos( $text, $token ) === false ) {
                $missing[] = $token;
            }
        }
        return empty( $missing ) ? true : $missing;
    }

    public function restore_missing( $text, $missing ) {
        $restored = $text;
        foreach ( $missing as $token ) {
            if ( isset( $this->tokens[ $token ] ) ) {
                // Insert the original value at the end if token is missing.
                $restored .= ' ' . $this->tokens[ $token ];
            }
        }
        return $restored;
    }

    public function get_tokens() {
        return $this->tokens;
    }
}
