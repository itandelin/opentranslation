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
        <div class="notice notice-success"><p><?php esc_html_e( '该条目已重置为待翻译，将在下次队列执行时重试。', 'opentranslation' ); ?></p></div>
    <?php elseif ( 'invalid' === $message ) : ?>
        <div class="notice notice-error"><p><?php esc_html_e( '无效的条目标识，未做任何改动。', 'opentranslation' ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( '这些条目已达到最大重试次数（默认 3 次）。原文会保持不翻译状态，直到你手动重试，或在 TranslatePress 编辑器中人工填写译文。常见原因：模型持续返回格式错误、占位符校验未通过、上游网关长时间不可用。', 'opentranslation' ); ?>
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
