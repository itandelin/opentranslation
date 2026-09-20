<?php
namespace OpenTranslation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Deactivator {
    public static function deactivate() {
        // 当前无需停用时清理：插件已不再注册任何 cron / Action Scheduler 任务。
    }
}
