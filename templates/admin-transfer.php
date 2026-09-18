<?php
/**
 * 配置导出导入页。
 *
 * 由 Admin_Transfer::render_page() 提供：$step、$result、$notice。
 * 模板无 namespace，引用插件类必须写全 \OpenTranslation\Xxx。
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$key_labels = array(
    'reused'    => __( '沿用本站已有密钥', 'opentranslation' ),
    'from_file' => __( '文件提供', 'opentranslation' ),
    'missing'   => __( '缺失，需手动填写', 'opentranslation' ),
);
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Import / Export', 'opentranslation' ); ?></h1>

    <?php if ( $notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
            <p><?php echo esc_html( $notice['text'] ); ?></p>
        </div>
    <?php endif; ?>

<?php if ( 'preview' === $step ) : ?>

    <?php if ( empty( $result['ok'] ) ) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html( $result['reason'] ); ?></p>
        </div>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \OpenTranslation\Admin_Transfer::PAGE ) ); ?>" class="button">
            <?php esc_html_e( '返回', 'opentranslation' ); ?>
        </a></p>
    <?php else : ?>
        <?php $summary = $result['summary']; ?>

        <h2><?php esc_html_e( '导入预览', 'opentranslation' ); ?></h2>

        <div class="notice notice-warning inline">
            <p><strong><?php esc_html_e( '导入将覆盖现有模型与术语表，建议先导出当前配置作为备份。', 'opentranslation' ); ?></strong></p>
        </div>

        <ul>
            <li><strong><?php esc_html_e( '来源站点', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $summary['site_url'] ); ?></li>
            <li><strong><?php esc_html_e( '导出时间', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $summary['exported_at'] ); ?></li>
            <li><strong><?php esc_html_e( '来源插件版本', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $summary['plugin_version'] ); ?></li>
            <li><strong><?php esc_html_e( '设置项', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['settings'] ) ); ?></li>
            <li><strong><?php esc_html_e( '模型', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['models'] ) ); ?></li>
            <li><strong><?php esc_html_e( '术语', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['glossary'] ) ); ?></li>
            <li><strong><?php esc_html_e( '含范围配置的语言', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['scope_languages'] ) ); ?></li>
            <li><strong><?php esc_html_e( '文件是否含密钥', 'opentranslation' ); ?>:</strong>
                <?php echo $summary['includes_api_keys'] ? esc_html__( '是', 'opentranslation' ) : esc_html__( '否', 'opentranslation' ); ?>
            </li>
        </ul>

        <h3><?php esc_html_e( '将导入的模型', 'opentranslation' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:12%;"><?php esc_html_e( 'Provider', 'opentranslation' ); ?></th>
                    <th style="width:22%;"><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                    <th style="width:30%;"><?php esc_html_e( 'Base URL', 'opentranslation' ); ?></th>
                    <th style="width:8%;"><?php esc_html_e( 'Priority', 'opentranslation' ); ?></th>
                    <th><?php esc_html_e( 'API Key', 'opentranslation' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $result['normalized']['models'] ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( '文件中没有可导入的模型。', 'opentranslation' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $result['normalized']['models'] as $index => $model ) : ?>
                        <?php $status = \OpenTranslation\Admin_Transfer::key_status( $model, $result['key_sources'][ $index ] ?? null ); ?>
                        <tr>
                            <td><?php echo esc_html( 'claude' === $model['provider'] ? 'Anthropic' : 'OpenAI' ); ?></td>
                            <td><?php echo esc_html( $model['model'] ); ?></td>
                            <td><?php echo esc_html( $model['base_url'] ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $model['priority'] ) ); ?></td>
                            <td<?php echo 'missing' === $status ? ' style="color:#d63638;"' : ''; ?>>
                                <?php echo esc_html( $key_labels[ $status ] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $result['skipped'] ) ) : ?>
            <h3><?php esc_html_e( '将被跳过', 'opentranslation' ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:12%;"><?php esc_html_e( '类型', 'opentranslation' ); ?></th>
                        <th style="width:28%;"><?php esc_html_e( '条目', 'opentranslation' ); ?></th>
                        <th><?php esc_html_e( '原因', 'opentranslation' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $result['skipped'] as $item ) : ?>
                        <tr>
                            <td><?php echo esc_html( 'model' === $item['type'] ? __( '模型', 'opentranslation' ) : __( '术语', 'opentranslation' ) ); ?></td>
                            <td><?php echo esc_html( $item['label'] ); ?></td>
                            <td><?php echo esc_html( $item['reason'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( \OpenTranslation\Admin_Transfer::NONCE_ACTION, \OpenTranslation\Admin_Transfer::NONCE_FIELD ); ?>
            <p class="submit">
                <button type="submit" name="confirm_import" value="1" class="button button-primary">
                    <?php esc_html_e( '确认导入', 'opentranslation' ); ?>
                </button>
                <button type="submit" name="cancel_import" value="1" class="button">
                    <?php esc_html_e( '取消', 'opentranslation' ); ?>
                </button>
            </p>
        </form>
    <?php endif; ?>

<?php else : ?>

    <h2><?php esc_html_e( 'Export', 'opentranslation' ); ?></h2>
    <p class="description">
        <?php esc_html_e( '导出设置、模型、术语表与翻译范围。不导出暂停语言、模型健康、用量、日志与缓存——这些是本站的运行时状态。', 'opentranslation' ); ?>
    </p>
    <form method="post">
        <?php wp_nonce_field( \OpenTranslation\Admin_Transfer::NONCE_ACTION, \OpenTranslation\Admin_Transfer::NONCE_FIELD ); ?>
        <p>
            <label>
                <input type="checkbox" name="include_api_keys" value="1" />
                <?php esc_html_e( '包含 API Key', 'opentranslation' ); ?>
            </label>
            <br />
            <span style="color:#d63638;">
                <?php esc_html_e( '勾选后导出文件将包含明文密钥，请妥善保管，用后删除。', 'opentranslation' ); ?>
            </span>
        </p>
        <p class="submit">
            <button type="submit" name="export_config" value="1" class="button button-primary">
                <?php esc_html_e( '导出配置', 'opentranslation' ); ?>
            </button>
        </p>
    </form>

    <hr />

    <h2><?php esc_html_e( 'Import', 'opentranslation' ); ?></h2>
    <p class="description">
        <?php esc_html_e( '上传后先展示预览，确认后才写入。不含密钥的文件会按模型标识沿用本站已有密钥。', 'opentranslation' ); ?>
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( \OpenTranslation\Admin_Transfer::NONCE_ACTION, \OpenTranslation\Admin_Transfer::NONCE_FIELD ); ?>
        <p>
            <input type="file" name="config" accept="application/json,.json" required />
        </p>
        <p class="submit">
            <button type="submit" name="upload_config" value="1" class="button button-primary">
                <?php esc_html_e( '上传并预览', 'opentranslation' ); ?>
            </button>
        </p>
    </form>

<?php endif; ?>
</div>
