<?php
/**
 * 测试入口：php tests/run.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/assert.php';

// 被测类
require_once __DIR__ . '/../includes/class-protector.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $test_file ) {
    require_once $test_file;
}

exit( ot_test_summary() );
