<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$batch_size = $settings['batch_size'] ?? 10;
$cron_interval = $settings['cron_interval'] ?? 5;
$rate_limit = $settings['rate_limit_per_minute'] ?? 20;
$system_prompt = $settings['system_prompt'] ?? \OpenTranslation\Admin::default_system_prompt();
$plugin_language = $settings['plugin_language'] ?? 'zh_CN';
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'opentranslation_settings' ); ?>
        <table class="form-table">
            <tr>
                <th><label for="ot_batch_size"><?php esc_html_e( 'Batch Size', 'opentranslation' ); ?></label></th>
                <td><input type="number" min="1" max="50" id="ot_batch_size" name="opentranslation_settings[batch_size]" value="<?php echo esc_attr( $batch_size ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ot_cron_interval"><?php esc_html_e( 'Cron Interval (minutes)', 'opentranslation' ); ?></label></th>
                <td><input type="number" min="1" id="ot_cron_interval" name="opentranslation_settings[cron_interval]" value="<?php echo esc_attr( $cron_interval ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ot_rate_limit"><?php esc_html_e( 'Rate Limit (requests/min)', 'opentranslation' ); ?></label></th>
                <td><input type="number" min="0" id="ot_rate_limit" name="opentranslation_settings[rate_limit_per_minute]" value="<?php echo esc_attr( $rate_limit ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ot_system_prompt"><?php esc_html_e( 'System Prompt', 'opentranslation' ); ?></label></th>
                <td><textarea id="ot_system_prompt" name="opentranslation_settings[system_prompt]" rows="6" class="large-text"><?php echo esc_textarea( $system_prompt ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="ot_plugin_language"><?php esc_html_e( 'Plugin Language', 'opentranslation' ); ?></label></th>
                <td>
                    <select id="ot_plugin_language" name="opentranslation_settings[plugin_language]">
                        <option value="zh_CN" <?php selected( $plugin_language, 'zh_CN' ); ?>><?php esc_html_e( 'Simplified Chinese', 'opentranslation' ); ?></option>
                        <option value="en_US" <?php selected( $plugin_language, 'en_US' ); ?>><?php esc_html_e( 'English', 'opentranslation' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
