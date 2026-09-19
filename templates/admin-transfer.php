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
    'reused'    => __( 'Reuse existing key on this site', 'opentranslation' ),
    'from_file' => __( 'From file', 'opentranslation' ),
    'missing'   => __( 'Missing, needs manual entry', 'opentranslation' ),
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
            <?php esc_html_e( 'Back', 'opentranslation' ); ?>
        </a></p>
    <?php else : ?>
        <?php $summary = $result['summary']; ?>

        <h2><?php esc_html_e( 'Import Preview', 'opentranslation' ); ?></h2>

        <div class="notice notice-warning inline">
            <p><strong><?php esc_html_e( 'Importing overwrites the existing models and glossary. Exporting the current configuration as a backup first is recommended.', 'opentranslation' ); ?></strong></p>
        </div>

        <ul>
            <li><strong><?php esc_html_e( 'Source site', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $summary['site_url'] ); ?></li>
            <li><strong><?php esc_html_e( 'Exported at', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $summary['exported_at'] ); ?></li>
            <li><strong><?php esc_html_e( 'Source plugin version', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $summary['plugin_version'] ); ?></li>
            <li><strong><?php esc_html_e( 'Settings', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['settings'] ) ); ?></li>
            <li><strong><?php esc_html_e( 'Models', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['models'] ) ); ?></li>
            <li><strong><?php esc_html_e( 'Terms', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['glossary'] ) ); ?></li>
            <li><strong><?php esc_html_e( 'Languages with scope config', 'opentranslation' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $summary['scope_languages'] ) ); ?></li>
            <li><strong><?php esc_html_e( 'File contains keys', 'opentranslation' ); ?>:</strong>
                <?php echo $summary['includes_api_keys'] ? esc_html__( 'Yes', 'opentranslation' ) : esc_html__( 'No', 'opentranslation' ); ?>
            </li>
        </ul>

        <h3><?php esc_html_e( 'Models to import', 'opentranslation' ); ?></h3>
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
                    <tr><td colspan="5"><?php esc_html_e( 'The file contains no importable models.', 'opentranslation' ); ?></td></tr>
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
            <h3><?php esc_html_e( 'Will be skipped', 'opentranslation' ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:12%;"><?php esc_html_e( 'Type', 'opentranslation' ); ?></th>
                        <th style="width:28%;"><?php esc_html_e( 'Entry', 'opentranslation' ); ?></th>
                        <th><?php esc_html_e( 'Reason', 'opentranslation' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $result['skipped'] as $item ) : ?>
                        <tr>
                            <td><?php echo esc_html( 'model' === $item['type'] ? __( 'Models', 'opentranslation' ) : __( 'Terms', 'opentranslation' ) ); ?></td>
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
                    <?php esc_html_e( 'Confirm import', 'opentranslation' ); ?>
                </button>
                <button type="submit" name="cancel_import" value="1" class="button">
                    <?php esc_html_e( 'Cancel', 'opentranslation' ); ?>
                </button>
            </p>
        </form>
    <?php endif; ?>

<?php else : ?>

    <h2><?php esc_html_e( 'Export', 'opentranslation' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Exports settings, models, glossary, and translation scope. Paused languages, model health, usage, logs, and cache are not exported because they are runtime state of this site.', 'opentranslation' ); ?>
    </p>
    <form method="post">
        <?php wp_nonce_field( \OpenTranslation\Admin_Transfer::NONCE_ACTION, \OpenTranslation\Admin_Transfer::NONCE_FIELD ); ?>
        <p>
            <label>
                <input type="checkbox" name="include_api_keys" value="1" />
                <?php esc_html_e( 'Include API Keys', 'opentranslation' ); ?>
            </label>
            <br />
            <span style="color:#d63638;">
                <?php esc_html_e( 'When checked, the exported file contains plain-text keys. Store it safely and delete it after use.', 'opentranslation' ); ?>
            </span>
        </p>
        <p class="submit">
            <button type="submit" name="export_config" value="1" class="button button-primary">
                <?php esc_html_e( 'Export configuration', 'opentranslation' ); ?>
            </button>
        </p>
    </form>

    <hr />

    <h2><?php esc_html_e( 'Import', 'opentranslation' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'A preview is shown after upload and nothing is written until you confirm. For files without keys, existing keys on this site are reused by model identity.', 'opentranslation' ); ?>
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( \OpenTranslation\Admin_Transfer::NONCE_ACTION, \OpenTranslation\Admin_Transfer::NONCE_FIELD ); ?>
        <p>
            <input type="file" name="config" accept="application/json,.json" required />
        </p>
        <p class="submit">
            <button type="submit" name="upload_config" value="1" class="button button-primary">
                <?php esc_html_e( 'Upload and preview', 'opentranslation' ); ?>
            </button>
        </p>
    </form>

<?php endif; ?>
</div>
