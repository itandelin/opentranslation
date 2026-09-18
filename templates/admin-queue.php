<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <?php
    $next_as = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( \OpenTranslation\Scheduler::ACTION_HOOK ) : false;
    $next_wp = wp_next_scheduled( \OpenTranslation\Scheduler::CRON_HOOK );
    $next_run = $next_as ? $next_as : $next_wp;
    $runner = $next_as ? __( 'Action Scheduler', 'opentranslation' ) : __( 'WP-Cron', 'opentranslation' );
    ?>

    <?php if ( 'queued' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( '队列已触发。任务在后台异步执行，稍后刷新本页可在「Last Run」看到执行统计。', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'retried' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Failed items reset and queue triggered.', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'toggled' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Language status updated.', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'circuit_reset' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( '模型熔断状态已重置。', 'opentranslation' ); ?></p></div>
    <?php endif; ?>

    <?php
    // 模型健康告警：单个熔断提示，全部熔断红色告警 + 重置入口
    $circuit_models = \OpenTranslation\Encrypted_Options::get( 'opentranslation_models', array() );
    $circuit_health = new \OpenTranslation\Model_Health();
    $circuit_open_list = array();
    $circuit_total  = 0;
    foreach ( $circuit_models as $cm ) {
        $cm_key = \OpenTranslation\Model_Identity::key( $cm );
        $cm_state = $circuit_health->state( $cm_key );
        if ( (int) $cm_state['open_until'] > 0 ) {
            $remaining = max( 0, (int) $cm_state['open_until'] - time() );
            $circuit_open_list[] = array(
                'label'     => \OpenTranslation\Model_Identity::label( $cm ),
                'remaining' => $remaining,
            );
            $circuit_total++;
        }
    }
    $circuit_all = ! empty( $circuit_models ) && $circuit_total === count( $circuit_models );
    if ( $circuit_total > 0 ) : ?>
        <?php if ( $circuit_all ) : ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( '所有模型均已熔断，队列已暂停。', 'opentranslation' ); ?></strong>
                    <?php esc_html_e( '请检查模型配置或手动重置。', 'opentranslation' ); ?>
                    <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_reset_circuit&key=all&from=opentranslation-queue' ), 'opentranslation_reset_circuit' ) ); ?>"><?php esc_html_e( '重置全部', 'opentranslation' ); ?></a>
                </p>
            </div>
        <?php else : ?>
            <div class="notice notice-warning">
                <p><?php
                    printf(
                        /* translators: %s is the comma-separated list of circuit-open model labels with remaining seconds. */
                        esc_html__( '以下模型处于熔断中：%s', 'opentranslation' ),
                        esc_html( implode( '; ', array_map( function ( $mo ) {
                            return $mo['label'] . '（剩余 ' . (int) $mo['remaining'] . ' 秒）';
                        }, $circuit_open_list ) ) )
                    );
                ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Cache Overview', 'opentranslation' ); ?></h2>
    <p class="description"><?php esc_html_e( '以下为 OpenTranslation 缓存表的全局统计（所有语言合计），不等于 TranslatePress 字典表的未翻译量。按语言明细见下方表格。', 'opentranslation' ); ?></p>
    <ul>
        <li><strong><?php esc_html_e( 'Pending', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $counts['pending'] ?? 0 ); ?></li>
        <li><strong><?php esc_html_e( 'Translated', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $counts['translated'] ?? 0 ); ?></li>
        <li><strong><?php esc_html_e( 'Failed', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $counts['failed'] ?? 0 ); ?></li>
        <li><strong><?php esc_html_e( 'Queue Runner', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $next_run ? $runner : __( 'Not scheduled', 'opentranslation' ) ); ?></li>
        <li><strong><?php esc_html_e( 'Next Run', 'opentranslation' ); ?>:</strong>
            <?php
            if ( $next_run ) {
                printf(
                    /* translators: %s is a human readable time difference. */
                    esc_html__( '%s from now', 'opentranslation' ),
                    esc_html( human_time_diff( time(), $next_run ) )
                );
            } else {
                esc_html_e( 'Not scheduled', 'opentranslation' );
            }
            ?>
        </li>
        <?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
            <li><strong style="color:#d63638;"><?php esc_html_e( 'WP-Cron disabled in wp-config.php!', 'opentranslation' ); ?></strong></li>
        <?php endif; ?>
    </ul>

    <?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e( 'WP-Cron is disabled in wp-config.php. The queue will not run automatically. Please use the "Run Queue Now" button or set up a real system cron job.', 'opentranslation' ); ?></p>
        </div>
    <?php endif; ?>

    <?php $last_run = \OpenTranslation\Scheduler::get_last_run_stats(); ?>
    <?php if ( ! empty( $last_run ) ) : ?>
        <h2><?php esc_html_e( 'Last Run', 'opentranslation' ); ?></h2>
        <?php if ( ! empty( $last_run['circuit_open'] ) ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( '本轮因所有模型熔断而跳过。', 'opentranslation' ); ?></p></div>
        <?php endif; ?>
        <ul>
            <li><strong><?php esc_html_e( 'Finished', 'opentranslation' ); ?>:</strong>
                <?php
                if ( ! empty( $last_run['finished_at'] ) ) {
                    printf(
                        /* translators: %s is a human readable time difference. */
                        esc_html__( '%s ago', 'opentranslation' ),
                        esc_html( human_time_diff( (int) $last_run['finished_at'], time() ) )
                    );
                }
                if ( isset( $last_run['duration'] ) ) {
                    echo ' (' . esc_html( $last_run['duration'] ) . 's)';
                }
                ?>
            </li>
            <li><strong><?php esc_html_e( 'Written back', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $last_run['processed'] ?? 0 ); ?></li>
            <li><strong><?php esc_html_e( 'From model', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $last_run['model'] ?? 0 ); ?></li>
            <li><strong><?php esc_html_e( 'From cache', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $last_run['cached'] ?? 0 ); ?></li>
            <li><strong><?php esc_html_e( 'Passthrough', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $last_run['passthrough'] ?? 0 ); ?></li>
            <li><strong><?php esc_html_e( 'Failed', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $last_run['failed'] ?? 0 ); ?></li>
            <li><strong><?php esc_html_e( 'API request units', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $last_run['request_units'] ?? 0 ); ?></li>
            <?php if ( ! empty( $last_run['languages'] ) ) : ?>
                <li><strong><?php esc_html_e( 'By language', 'opentranslation' ); ?>:</strong>
                    <?php
                    $pairs = array();
                    foreach ( $last_run['languages'] as $lang_code => $count ) {
                        $pairs[] = $lang_code . ': ' . (int) $count;
                    }
                    echo esc_html( implode( ' / ', $pairs ) );
                    ?>
                </li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>

    <p>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_run_queue' ), 'opentranslation_run_queue' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Run Queue Now', 'opentranslation' ); ?></a>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_retry_failed' ), 'opentranslation_retry_failed' ) ); ?>" class="button"><?php esc_html_e( 'Retry Failed', 'opentranslation' ); ?></a>
    </p>

    <h2><?php esc_html_e( 'Languages', 'opentranslation' ); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Language', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Status', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'TP Untranslated', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Cache Translated', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Cache Pending', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Cache Failed', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Action', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $languages as $lang ) : ?>
                <?php
                $is_disabled = in_array( $lang, $disabled, true );
                $lang_counts = isset( $counts_by_lang[ $lang ] )
                    ? $counts_by_lang[ $lang ]
                    : array( 'pending' => 0, 'translated' => 0, 'failed' => 0 );
                ?>
                <tr>
                    <td><?php echo esc_html( $lang ); ?></td>
                    <td><?php echo $is_disabled ? esc_html__( 'Paused', 'opentranslation' ) : esc_html__( 'Active', 'opentranslation' ); ?></td>
                    <td><?php echo esc_html( \OpenTranslation\TP_Storage_Adapter::get_untranslated_count( $lang ) ); ?></td>
                    <td><?php echo esc_html( $lang_counts['translated'] ); ?></td>
                    <td><?php echo esc_html( $lang_counts['pending'] ); ?></td>
                    <td>
                        <?php if ( $lang_counts['failed'] > 0 ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=opentranslation-failures&lang=' . urlencode( $lang ) ) ); ?>">
                                <strong style="color:#d63638;"><?php echo esc_html( $lang_counts['failed'] ); ?></strong>
                            </a>
                        <?php else : ?>
                            0
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_toggle_language&lang=' . urlencode( $lang ) ), 'opentranslation_toggle_language' ) ); ?>" class="button button-small">
                            <?php echo $is_disabled ? esc_html__( 'Resume', 'opentranslation' ) : esc_html__( 'Pause', 'opentranslation' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Logs', 'opentranslation' ); ?></h2>

    <?php
    $log_total_pages = (int) ceil( $log_total / $log_per_page );
    $level_colors    = array(
        'error' => '#d63638',
        'warn'  => '#dba617',
        'info'  => '#2271b1',
        'debug' => '#8c8f94',
    );
    ?>

    <form method="get" style="margin-bottom:12px;">
        <input type="hidden" name="page" value="opentranslation-queue" />
        <select name="log_action">
            <option value=""><?php esc_html_e( 'All actions', 'opentranslation' ); ?></option>
            <?php foreach ( (array) $log_actions as $action_name ) : ?>
                <option value="<?php echo esc_attr( $action_name ); ?>" <?php selected( $log_action, $action_name ); ?>>
                    <?php echo esc_html( $action_name ); ?>
                    (<?php echo esc_html( \OpenTranslation\Log::level_for( $action_name ) ); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php submit_button( __( 'Filter', 'opentranslation' ), 'secondary', 'submit', false ); ?>
        <span class="description" style="margin-left:8px;">
            <?php
            printf(
                /* translators: %d is the total number of log rows. */
                esc_html__( '共 %d 条', 'opentranslation' ),
                (int) $log_total
            );
            ?>
            <?php if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) : ?>
                — <?php esc_html_e( 'debug 级日志（如调度心跳）当前不记录，开启 WP_DEBUG 后才会入库。', 'opentranslation' ); ?>
            <?php endif; ?>
            — <?php esc_html_e( '日志默认保留 30 天。', 'opentranslation' ); ?>
        </span>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:14%;"><?php esc_html_e( 'Time', 'opentranslation' ); ?></th>
                <th style="width:6%;"><?php esc_html_e( 'Level', 'opentranslation' ); ?></th>
                <th style="width:14%;"><?php esc_html_e( 'Action', 'opentranslation' ); ?></th>
                <th style="width:20%;"><?php esc_html_e( 'Cache Key', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Message', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $logs ) ) : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <?php
                    $level = \OpenTranslation\Log::level_for( $log['action'] );
                    $color = isset( $level_colors[ $level ] ) ? $level_colors[ $level ] : '#8c8f94';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $log['created_at'] ); ?></td>
                        <td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $level ); ?></span></td>
                        <td><?php echo esc_html( $log['action'] ); ?></td>
                        <td>
                            <?php if ( '' !== (string) $log['cache_key'] ) : ?>
                                <code style="font-size:11px;"><?php echo esc_html( $log['cache_key'] ); ?></code>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $log['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No logs found.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $log_total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post( paginate_links( array(
                    'base'      => add_query_arg( 'log_page', '%#%' ),
                    'format'    => '',
                    'current'   => $log_page,
                    'total'     => $log_total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) ) );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
