<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// priority 重复检测（U4）：重复时 usort 顺序不稳定，降级次序不可预期
$priorities = array();
foreach ( (array) $models as $m ) {
    $priorities[] = isset( $m['priority'] ) ? (int) $m['priority'] : 10;
}
$duplicated = array_keys( array_filter( array_count_values( $priorities ), function ( $count ) {
    return $count > 1;
} ) );
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <h2 id="ot-form-title"><?php esc_html_e( 'Add Model', 'opentranslation' ); ?></h2>
    <form method="post" id="ot-model-form">
        <?php wp_nonce_field( 'opentranslation_models_action', 'opentranslation_models_nonce' ); ?>
        <input type="hidden" name="model_index" id="ot-form-index" value="" />
        <table class="form-table">
            <tr><th><?php esc_html_e( 'Provider', 'opentranslation' ); ?></th><td>
                <select name="provider">
                    <option value="openai"><?php esc_html_e( 'OpenAI', 'opentranslation' ); ?></option>
                    <option value="claude"><?php esc_html_e( 'Claude', 'opentranslation' ); ?></option>
                </select>
            </td></tr>
            <tr>
                <th><?php esc_html_e( 'API Key', 'opentranslation' ); ?></th>
                <td>
                    <input type="password" name="api_key" id="ot-api-key" class="regular-text" autocomplete="new-password" />
                    <p class="description" id="ot-api-key-hint" style="display:none;"><?php esc_html_e( '留空表示保持现有 API Key 不变。', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Base URL (optional)', 'opentranslation' ); ?></th>
                <td>
                    <input type="url" name="base_url" class="regular-text" placeholder="https://api.openai.com/v1/" />
                    <p class="description"><?php esc_html_e( '留空则使用该 Provider 的官方地址。必须是 https，不接受内网地址。', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                <td>
                    <div id="ot-model-field-wrapper" style="display:flex;gap:8px;align-items:center;">
                        <input type="text" id="ot-model-input" name="model" class="regular-text" value="gpt-4o" />
                        <button type="button" id="ot-fetch-models-btn" class="button"><?php esc_html_e( '获取模型列表', 'opentranslation' ); ?></button>
                    </div>
                    <p id="ot-model-message" class="description" style="display:none;margin-top:6px;"></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Priority', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" name="priority" value="10" />
                    <p class="description"><?php esc_html_e( '数字越小优先级越高。主模型失败后，会按优先级顺序自动降级到备用模型。每个模型应设置不同的优先级。', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Temperature', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" step="0.1" min="0" max="2" name="temperature" value="0.3" />
                    <p class="description"><?php esc_html_e( '控制翻译结果的随机性，有效范围 0-2。0 最稳定、最保守；1 以上更具创造性，但可能导致翻译不一致。建议 0.1~0.5。', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Max Tokens (0 = auto)', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" min="0" name="max_tokens" value="0" />
                    <p class="description"><?php esc_html_e( '单次 API 请求最多返回的 Token 数。0 表示由模型自动决定；Claude 因 API 限制会在填 0 时自动使用 4096。', 'opentranslation' ); ?></p>
                </td>
            </tr>
        </table>
        <p>
            <button type="submit" name="add_model" value="1" class="button button-primary" id="ot-submit-add"><?php esc_html_e( 'Add Model', 'opentranslation' ); ?></button>
            <button type="submit" name="update_model" value="1" class="button button-primary" id="ot-submit-update" style="display:none;"><?php esc_html_e( 'Update Model', 'opentranslation' ); ?></button>
            <button type="button" class="button" id="ot-cancel-edit" style="display:none;"><?php esc_html_e( 'Cancel', 'opentranslation' ); ?></button>
        </p>
    </form>

    <h2><?php esc_html_e( 'Configured Models', 'opentranslation' ); ?></h2>

    <?php if ( ! empty( $duplicated ) ) : ?>
        <div class="notice notice-warning inline">
            <p><?php
                printf(
                    /* translators: %s is a comma-separated list of duplicated priority values. */
                    esc_html__( '优先级重复：%s。降级顺序将不可预期，建议为每个模型设置不同的优先级。', 'opentranslation' ),
                    esc_html( implode( ', ', $duplicated ) )
                );
            ?></p>
        </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:8%;"><?php esc_html_e( 'Provider', 'opentranslation' ); ?></th>
                <th style="width:16%;"><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                <th style="width:22%;"><?php esc_html_e( 'Base URL', 'opentranslation' ); ?></th>
                <th style="width:7%;"><?php esc_html_e( 'Priority', 'opentranslation' ); ?></th>
                <th style="width:8%;"><?php esc_html_e( 'Temp', 'opentranslation' ); ?></th>
                <th style="width:9%;"><?php esc_html_e( 'Max Tokens', 'opentranslation' ); ?></th>
                <th style="width:10%;"><?php esc_html_e( 'API Key', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $models ) ) : ?>
                <?php foreach ( $models as $index => $model ) : ?>
                    <?php
                    $provider    = isset( $model['provider'] ) ? $model['provider'] : 'openai';
                    $key_length  = isset( $model['api_key'] ) ? strlen( $model['api_key'] ) : 0;
                    $key_display = $key_length > 0
                        ? str_repeat( '•', 8 ) . ' (' . $key_length . ')'
                        : __( 'not set', 'opentranslation' );
                    $base_display = ! empty( $model['base_url'] )
                        ? $model['base_url']
                        : \OpenTranslation\Translator::default_base_url( $provider ) . ' (' . __( 'default', 'opentranslation' ) . ')';
                    $max_tokens_display = ( isset( $model['max_tokens'] ) && $model['max_tokens'] > 0 )
                        ? $model['max_tokens']
                        : __( 'auto', 'opentranslation' );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $provider ); ?></td>
                        <td><?php echo esc_html( $model['model'] ?? '' ); ?></td>
                        <td><small><?php echo esc_html( $base_display ); ?></small></td>
                        <td><?php echo esc_html( $model['priority'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $model['temperature'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $max_tokens_display ); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html( $key_display ); ?></code></td>
                        <td>
                            <button type="button" class="button button-small ot-edit-model-btn"
                                data-index="<?php echo esc_attr( $index ); ?>"
                                data-provider="<?php echo esc_attr( $provider ); ?>"
                                data-base-url="<?php echo esc_attr( $model['base_url'] ?? '' ); ?>"
                                data-model="<?php echo esc_attr( $model['model'] ?? '' ); ?>"
                                data-priority="<?php echo esc_attr( $model['priority'] ?? 10 ); ?>"
                                data-temperature="<?php echo esc_attr( $model['temperature'] ?? 0.3 ); ?>"
                                data-max-tokens="<?php echo esc_attr( $model['max_tokens'] ?? 0 ); ?>">
                                <?php esc_html_e( 'Edit', 'opentranslation' ); ?>
                            </button>
                            <button type="button" class="button button-small ot-test-model-btn" data-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( '测试', 'opentranslation' ); ?></button>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'opentranslation_models_action', 'opentranslation_models_nonce' ); ?>
                                <input type="hidden" name="model_index" value="<?php echo esc_attr( $index ); ?>" />
                                <input type="hidden" name="delete_model" value="1" />
                                <?php submit_button( __( 'Delete', 'opentranslation' ), 'small', 'submit', false ); ?>
                            </form>
                            <span class="ot-test-model-result" style="margin-left:6px;"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No models configured.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
