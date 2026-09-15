<?php
/**
 * 极简断言。约定 expected 在前、actual 在后（遵循 AGENTS.md）。
 */

$GLOBALS['ot_test_stats'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );

function ot_assert_same( $expected, $actual, $label ) {
    if ( $expected === $actual ) {
        $GLOBALS['ot_test_stats']['pass']++;
        echo "  \033[32m✓\033[0m {$label}\n";
        return;
    }
    $GLOBALS['ot_test_stats']['fail']++;
    $msg = sprintf(
        "%s\n      expected: %s\n      actual:   %s",
        $label,
        var_export( $expected, true ),
        var_export( $actual, true )
    );
    $GLOBALS['ot_test_stats']['failures'][] = $msg;
    echo "  \033[31m✗\033[0m {$msg}\n";
}

function ot_assert_true( $actual, $label ) {
    ot_assert_same( true, $actual, $label );
}

function ot_test_group( $name ) {
    echo "\n\033[1m{$name}\033[0m\n";
}

function ot_test_summary() {
    $s = $GLOBALS['ot_test_stats'];
    echo "\n" . str_repeat( '─', 60 ) . "\n";
    if ( 0 === $s['fail'] ) {
        echo "\033[32m全部通过\033[0m：{$s['pass']} 个断言\n";
        return 0;
    }
    echo "\033[31m失败 {$s['fail']} 个\033[0m，通过 {$s['pass']} 个\n";
    return 1;
}
