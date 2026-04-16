<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <h2><?php esc_html_e( 'Add Model', 'opentranslation' ); ?></h2>
    <form method="post">
        <?php wp_nonce_field( 'opentranslation_models_action', 'opentranslation_models_nonce' ); ?>
        <table class="form-table">
            <tr><th><?php esc_html_e( 'Provider', 'opentranslation' ); ?></th><td>
                <select name="provider">
                    <option value="openai"><?php esc_html_e( 'OpenAI', 'opentranslation' ); ?></option>
                    <option value="claude"><?php esc_html_e( 'Claude', 'opentranslation' ); ?></option>
                </select>
            </td></tr>
            <tr><th><?php esc_html_e( 'API Key', 'opentranslation' ); ?></th><td><input type="password" name="api_key" class="regular-text" /></td></tr>
            <tr><th><?php esc_html_e( 'Base URL (optional)', 'opentranslation' ); ?></th><td><input type="url" name="base_url" class="regular-text" placeholder="https://api.openai.com/v1/" /></td></tr>
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
                    <p class="description"><?php esc_html_e( '数字越小优先级越高。主模型失败后，会按优先级顺序自动降级到备用模型。', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Temperature', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" step="0.1" min="0" max="2" name="temperature" value="0.3" />
                    <p class="description"><?php esc_html_e( '控制翻译结果的随机性。0 最稳定、最保守；1 以上更具创造性，但可能导致翻译不一致。建议 0.1~0.5。', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Max Tokens (0 = auto)', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" min="0" name="max_tokens" value="0" />
                    <p class="description"><?php esc_html_e( '单次 API 请求最多返回的 Token 数。0 表示由模型自动决定。如果批量翻译内容较长，可适当调高。', 'opentranslation' ); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button( __( 'Add Model', 'opentranslation' ) ); ?>
        <input type="hidden" name="add_model" value="1" />
    </form>

    <h2><?php esc_html_e( 'Configured Models', 'opentranslation' ); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Provider', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Base URL', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Priority', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $models ) ) : ?>
                <?php foreach ( $models as $index => $model ) : ?>
                    <tr>
                        <td><?php echo esc_html( $model['provider'] ); ?></td>
                        <td><?php echo esc_html( $model['model'] ); ?></td>
                        <td><?php echo esc_html( $model['base_url'] ); ?></td>
                        <td><?php echo esc_html( $model['priority'] ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'opentranslation_models_action', 'opentranslation_models_nonce' ); ?>
                                <input type="hidden" name="model_index" value="<?php echo esc_attr( $index ); ?>" />
                                <input type="hidden" name="delete_model" value="1" />
                                <?php submit_button( __( 'Delete', 'opentranslation' ), 'small', 'submit', false ); ?>
                            </form>
                            <button type="button" class="button button-small ot-test-model-btn" data-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( '测试', 'opentranslation' ); ?></button>
                            <span class="ot-test-model-result" style="margin-left:6px;"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No models configured.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
