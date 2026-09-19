<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$message     = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
$total_pages = (int) ceil( $total / $per_page );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Failed Translations', 'opentranslation' ); ?></h1>

    <?php if ( 'retried' === $message ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'The entry has been reset to pending and will be retried on the next queue run.', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'invalid' === $message ) : ?>
        <div class="notice notice-error"><p><?php esc_html_e( 'Invalid entry identifier; nothing was changed.', 'opentranslation' ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( 'These entries have reached the maximum retry count (3 by default). The source text stays untranslated until you retry manually or fill in the translation in the TranslatePress editor. Common causes: the model keeps returning malformed output, placeholder validation fails, or the upstream gateway has been unavailable for a long time.', 'opentranslation' ); ?>
    </p>

    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=opentranslation-failures' ) ); ?>" class="<?php echo '' === $language ? 'current' : ''; ?>">
                <?php esc_html_e( 'All', 'opentranslation' ); ?>
                <span class="count">(<?php echo esc_html( \OpenTranslation\Cache::count_failed_items() ); ?>)</span>
            </a>
        </li>
        <?php foreach ( $languages as $lang ) : ?>
            <li>
                 | <a href="<?php echo esc_url( admin_url( 'admin.php?page=opentranslation-failures&lang=' . urlencode( $lang ) ) ); ?>" class="<?php echo $language === $lang ? 'current' : ''; ?>">
                    <?php echo esc_html( $lang ); ?>
                    <span class="count">(<?php echo esc_html( \OpenTranslation\Cache::count_failed_items( $lang ) ); ?>)</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
        <thead>
            <tr>
                <th style="width:26%;"><?php esc_html_e( 'Source Text', 'opentranslation' ); ?></th>
                <th style="width:8%;"><?php esc_html_e( 'Language', 'opentranslation' ); ?></th>
                <th style="width:6%;"><?php esc_html_e( 'Retries', 'opentranslation' ); ?></th>
                <th style="width:34%;"><?php esc_html_e( 'Last Message', 'opentranslation' ); ?></th>
                <th style="width:14%;"><?php esc_html_e( 'Updated', 'opentranslation' ); ?></th>
                <th style="width:12%;"><?php esc_html_e( 'Action', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $items ) ) : ?>
                <?php foreach ( $items as $item ) : ?>
                    <?php
                    $source = (string) $item['source_text'];
                    $short  = mb_strlen( $source ) > 120 ? mb_substr( $source, 0, 120 ) . '…' : $source;
                    ?>
                    <tr>
                        <td>
                            <?php echo esc_html( $short ); ?>
                            <?php if ( ! empty( $item['context'] ) ) : ?>
                                <br /><small style="color:#646970;"><?php echo esc_html( $item['context'] ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $item['target_lang'] ); ?></td>
                        <td><?php echo esc_html( $item['retry_count'] ); ?></td>
                        <td><small><?php echo esc_html( (string) $item['last_message'] ); ?></small></td>
                        <td><?php echo esc_html( $item['updated_at'] ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php
                                echo esc_url( wp_nonce_url(
                                    admin_url( 'admin-post.php?action=opentranslation_retry_item&cache_key=' . urlencode( $item['cache_key'] ) ),
                                    'opentranslation_retry_item'
                                ) );
                            ?>"><?php esc_html_e( 'Retry', 'opentranslation' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No failed items.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post( paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) ) );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
