<?php
/**
 * 测试入口：php tests/run.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/assert.php';

// 被测类
require_once __DIR__ . '/../includes/class-glossary.php';
require_once __DIR__ . '/../includes/class-protector.php';
require_once __DIR__ . '/../includes/class-translator.php';
require_once __DIR__ . '/../includes/class-model-identity.php';
require_once __DIR__ . '/../includes/class-model-health.php';
require_once __DIR__ . '/../includes/class-scope.php';
require_once __DIR__ . '/../includes/class-admin.php';
require_once __DIR__ . '/../includes/class-config-transfer.php';
require_once __DIR__ . '/../includes/class-admin-transfer.php';
require_once __DIR__ . '/../includes/class-url-guard.php';
require_once __DIR__ . '/../includes/class-log.php';
require_once __DIR__ . '/../includes/interface-model-client.php';
require_once __DIR__ . '/../includes/class-claude-client.php';
require_once __DIR__ . '/../includes/class-openai-client.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $test_file ) {
    require_once $test_file;
}

exit( ot_test_summary() );
