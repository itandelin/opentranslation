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
                    <option value="claude"><?php esc_html_e( 'Anthropic', 'opentranslation' ); ?></option>
                </select>
            </td></tr>
            <tr>
                <th><?php esc_html_e( 'API Key', 'opentranslation' ); ?></th>
                <td>
                    <input type="password" name="api_key" id="ot-api-key" class="regular-text" autocomplete="new-password" />
                    <p class="description" id="ot-api-key-hint" style="display:none;"><?php esc_html_e( 'Leave empty to keep the current API Key.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Base URL (optional)', 'opentranslation' ); ?></th>
                <td>
                    <input type="url" name="base_url" class="regular-text" placeholder="https://api.openai.com/v1/" />
                    <p class="description"><?php esc_html_e( 'Leave empty to use the official endpoint of this provider. Must be https; internal addresses are rejected.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                <td>
                    <div id="ot-model-field-wrapper" style="display:flex;gap:8px;align-items:center;">
                        <input type="text" id="ot-model-input" name="model" class="regular-text" value="gpt-4o" />
                        <button type="button" id="ot-fetch-models-btn" class="button"><?php esc_html_e( 'Fetch Models', 'opentranslation' ); ?></button>
                    </div>
                    <p id="ot-model-message" class="description" style="display:none;margin-top:6px;"></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Priority', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" name="priority" value="10" />
                    <p class="description"><?php esc_html_e( 'Lower numbers mean higher priority. When the primary model fails, the plugin falls back to the next model by priority. Each model should have a distinct priority.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Temperature', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" step="0.1" min="0" max="2" name="temperature" value="0.3" />
                    <p class="description"><?php esc_html_e( 'Controls randomness of the output, valid range 0-2. 0 is the most stable and conservative; above 1 is more creative but may cause inconsistent translations. 0.1 to 0.5 is recommended.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Max Tokens (0 = auto)', 'opentranslation' ); ?></th>
                <td>
                    <input type="number" min="0" name="max_tokens" value="0" />
                    <p class="description"><?php esc_html_e( 'Maximum tokens returned per API request. 0 lets the model decide; for Anthropic, 0 falls back to 4096 because of an API requirement.', 'opentranslation' ); ?></p>
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
                    esc_html__( 'Duplicate priority: %s. The fallback order becomes unpredictable; give each model a distinct priority.', 'opentranslation' ),
                    esc_html( implode( ', ', $duplicated ) )
                );
            ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['message'] ) && 'circuit_reset' === sanitize_text_field( wp_unslash( $_GET['message'] ) ) ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Model circuit state has been reset.', 'opentranslation' ); ?></p></div>
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
                <th style="width:14%;"><?php esc_html_e( 'Health', 'opentranslation' ); ?></th>
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

                    // 健康状态
                    $model_key   = \OpenTranslation\Model_Identity::key( $model );
                    $hstate      = $health->state( $model_key );
                    $h_open      = (int) $hstate['open_until'];
                    $h_remaining = $h_open > 0 ? max( 0, $h_open - time() ) : 0;
                    $h_consec    = (int) $hstate['consecutive_failures'];
                    $h_last_err  = isset( $hstate['last_error'] ) ? $hstate['last_error'] : '';
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
                            <?php
                            if ( $h_open > 0 && $h_remaining > 0 ) :
                                /* translators: %d is the remaining seconds. */
                                printf(
                                    '<span style="color:#d63638;"><strong>&#9888; %s</strong>（剩余 %d 秒）</span>',
                                    esc_html__( 'Circuit open', 'opentranslation' ),
                                    (int) $h_remaining
                                );
                            elseif ( $h_open > 0 && ! empty( $hstate['half_open'] ) ) :
                                echo '<span>&#9684; ' . esc_html__( 'Half-open probe', 'opentranslation' ) . '</span>';
                            elseif ( $h_consec > 0 ) :
                                printf(
                                    '<span style="color:#dba617;">&#9888; %s</span>',
                                    sprintf(
                                        /* translators: %d is the consecutive failure count. */
                                        esc_html__( '%d consecutive failures', 'opentranslation' ),
                                        (int) $h_consec
                                    )
                                );
                            else :
                                echo '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Healthy', 'opentranslation' ) . '</span>';
                            endif;
                            ?>
                            <br />
                            <small style="color:#646970;">
                                <?php
                                printf(
                                    /* translators: 1: total successes, 2: total failures. */
                                    esc_html__( '%1$d succeeded / %2$d failed', 'opentranslation' ),
                                    (int) $hstate['total_success'],
                                    (int) $hstate['total_failure']
                                );
                                ?>
                                <?php if ( '' !== $h_last_err ) : ?>
                                    <br /><?php echo esc_html( mb_substr( $h_last_err, 0, 60 ) ); ?><?php echo mb_strlen( $h_last_err ) > 60 ? '…' : ''; ?>
                                <?php endif; ?>
                            </small>
                            <?php if ( $h_open > 0 ) : ?>
                                <br />
                                <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=opentranslation_reset_circuit&key=' . $model_key . '&from=opentranslation-models' ), 'opentranslation_reset_circuit' ) ); ?>">
                                    <?php esc_html_e( 'Reset', 'opentranslation' ); ?>
                                </a>
                            <?php endif; ?>
                        </td>
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
                            <button type="button" class="button button-small ot-test-model-btn" data-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Test', 'opentranslation' ); ?></button>
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
                <tr><td colspan="9"><?php esc_html_e( 'No models configured.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
