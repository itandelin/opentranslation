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
        <div class="notice notice-success"><p><?php esc_html_e( 'Queue triggered.', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'retried' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Failed items reset and queue triggered.', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'toggled' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Language status updated.', 'opentranslation' ); ?></p></div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Queue Status', 'opentranslation' ); ?></h2>
    <ul>
        <li><strong><?php esc_html_e( 'Pending', 'opentranslation' ); ?>:</strong> <?php echo isset( $counts['pending'] ) ? esc_html( $counts['pending'] ) : '0'; ?></li>
        <li><strong><?php esc_html_e( 'Translated', 'opentranslation' ); ?>:</strong> <?php echo isset( $counts['translated'] ) ? esc_html( $counts['translated'] ) : '0'; ?></li>
        <li><strong><?php esc_html_e( 'Failed', 'opentranslation' ); ?>:</strong> <?php echo isset( $counts['failed'] ) ? esc_html( $counts['failed'] ) : '0'; ?></li>
        <li><strong><?php esc_html_e( 'Queue Runner', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $next_run ? $runner : __( 'Not scheduled', 'opentranslation' ) ); ?></li>
        <li><strong><?php esc_html_e( 'Next Run', 'opentranslation' ); ?>:</strong>
            <?php
            if ( $next_run ) {
                echo esc_html( human_time_diff( time(), $next_run ) ) . ' ' . esc_html__( 'from now', 'opentranslation' );
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

    <p>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_run_queue' ), 'opentranslation_run_queue' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Run Queue Now', 'opentranslation' ); ?></a>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_retry_failed' ), 'opentranslation_retry_failed' ) ); ?>" class="button"><?php esc_html_e( 'Retry Failed', 'opentranslation' ); ?></a>
    </p>

    <h2><?php esc_html_e( 'Languages', 'opentranslation' ); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr><th><?php esc_html_e( 'Language', 'opentranslation' ); ?></th><th><?php esc_html_e( 'Status', 'opentranslation' ); ?></th><th><?php esc_html_e( 'TP Untranslated', 'opentranslation' ); ?></th><th><?php esc_html_e( 'Action', 'opentranslation' ); ?></th></tr>
        </thead>
        <tbody>
            <?php foreach ( $languages as $lang ) : ?>
                <?php $is_disabled = in_array( $lang, $disabled, true ); ?>
                <tr>
                    <td><?php echo esc_html( $lang ); ?></td>
                    <td><?php echo $is_disabled ? esc_html__( 'Paused', 'opentranslation' ) : esc_html__( 'Active', 'opentranslation' ); ?></td>
                    <td><?php echo esc_html( \OpenTranslation\TP_Storage_Adapter::get_untranslated_count( $lang ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_toggle_language&lang=' . $lang ), 'opentranslation_toggle_language' ) ); ?>" class="button button-small">
                            <?php echo $is_disabled ? esc_html__( 'Resume', 'opentranslation' ) : esc_html__( 'Pause', 'opentranslation' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Recent Logs', 'opentranslation' ); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr><th><?php esc_html_e( 'Time', 'opentranslation' ); ?></th><th><?php esc_html_e( 'Action', 'opentranslation' ); ?></th><th><?php esc_html_e( 'Cache Key', 'opentranslation' ); ?></th><th><?php esc_html_e( 'Message', 'opentranslation' ); ?></th></tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $logs ) ) : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log['created_at'] ); ?></td>
                        <td><?php echo esc_html( $log['action'] ); ?></td>
                        <td><code><?php echo esc_html( $log['cache_key'] ); ?></code></td>
                        <td><?php echo esc_html( $log['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="4"><?php esc_html_e( 'No logs found.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
