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
                <td>
                    <input type="number" min="1" max="50" id="ot_batch_size" name="opentranslation_settings[batch_size]" value="<?php echo esc_attr( $batch_size ); ?>" />
                    <p class="description"><?php esc_html_e( 'Maximum items sent to the model per round (1-50). Use 10-20 when the gateway is unstable: a larger batch widens the blast radius of a single failed request.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ot_cron_interval"><?php esc_html_e( 'Cron Interval (minutes)', 'opentranslation' ); ?></label></th>
                <td>
                    <input type="number" min="1" id="ot_cron_interval" name="opentranslation_settings[cron_interval]" value="<?php echo esc_attr( $cron_interval ); ?>" />
                    <p class="description"><?php esc_html_e( 'Only applies when WP-Cron drives the queue. If Action Scheduler is available (for example bundled with WooCommerce), the queue runs on a fixed 1-minute interval and this setting is ignored. See Queue Runner on the Queue & Logs page for the actual driver in use.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ot_rate_limit"><?php esc_html_e( 'Rate Limit (requests/min)', 'opentranslation' ); ?></label></th>
                <td>
                    <input type="number" min="0" id="ot_rate_limit" name="opentranslation_settings[rate_limit_per_minute]" value="<?php echo esc_attr( $rate_limit ); ?>" />
                    <p class="description"><?php esc_html_e( 'Maximum model request units allowed per minute. Note: 0 means no rate limiting at all, which is not recommended because API costs can run away. The limit is shared globally across all models.', 'opentranslation' ); ?></p>
                </td>
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

        <h2><?php esc_html_e( 'Translation Scope', 'opentranslation' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Choose the translation scope by content ownership. Ownership comes from the post association TranslatePress records while rendering pages. "Not linked to a post" means TP has not recorded an owner for the string yet (menus, footers, theme copy, and content on pages nobody has visited). "Published only" applies to entries linked to a post.', 'opentranslation' ); ?></p>

        <?php if ( empty( $languages ) ) : ?>
            <p><?php esc_html_e( 'No target languages configured in TranslatePress.', 'opentranslation' ); ?></p>
        <?php else : ?>
            <?php foreach ( $languages as $ot_lang ) : ?>
                <?php
                $ot_cfg   = isset( $scope[ $ot_lang ] ) ? $scope[ $ot_lang ] : array( 'mode' => 'all', 'buckets' => array(), 'published_only' => false );
                $ot_dist  = \OpenTranslation\Scope::distribution( $ot_lang );
                $ot_field = 'opentranslation_settings[scope][' . $ot_lang . ']';
                ?>
                <h3><?php echo esc_html( $ot_lang ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Mode', 'opentranslation' ); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr( $ot_field ); ?>[mode]" value="all" <?php checked( $ot_cfg['mode'], 'all' ); ?> /> <?php esc_html_e( 'All', 'opentranslation' ); ?></label>
                            <label style="margin-left:12px;"><input type="radio" name="<?php echo esc_attr( $ot_field ); ?>[mode]" value="include" <?php checked( $ot_cfg['mode'], 'include' ); ?> /> <?php esc_html_e( 'Include only', 'opentranslation' ); ?></label>
                            <label style="margin-left:12px;"><input type="radio" name="<?php echo esc_attr( $ot_field ); ?>[mode]" value="exclude" <?php checked( $ot_cfg['mode'], 'exclude' ); ?> /> <?php esc_html_e( 'Exclude', 'opentranslation' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Buckets', 'opentranslation' ); ?></th>
                        <td>
                            <?php
                            if ( empty( $ot_dist ) ) :
                                echo '<p class="description">' . esc_html__( 'No ownership data yet.', 'opentranslation' ) . '</p>';
                            else :
                                foreach ( $ot_dist as $ot_bucket => $ot_stats ) :
                                    $ot_label = \OpenTranslation\Scope::UNLINKED === $ot_bucket
                                        ? __( 'Not linked to a post', 'opentranslation' )
                                        : $ot_bucket;
                                    ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( $ot_field ); ?>[buckets][]"
                                            value="<?php echo esc_attr( $ot_bucket ); ?>"
                                            <?php checked( in_array( $ot_bucket, $ot_cfg['buckets'], true ) ); ?> />
                                        <?php echo esc_html( $ot_label ); ?>
                                        <span class="description">
                                            (<?php
                                            printf(
                                                /* translators: 1: total count, 2: untranslated count. */
                                                esc_html__( '%1$d total / %2$d untranslated', 'opentranslation' ),
                                                (int) $ot_stats['total'],
                                                (int) $ot_stats['untranslated']
                                            );
                                            ?>)
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Published only', 'opentranslation' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr( $ot_field ); ?>[published_only]"
                                    value="1"
                                    <?php checked( ! empty( $ot_cfg['published_only'] ) ); ?>
                                    <?php disabled( 'exclude' === $ot_cfg['mode'] ); ?> />
                                <?php esc_html_e( 'Only translate content linked to published posts (not supported in exclude mode)', 'opentranslation' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php submit_button(); ?>
    </form>
</div>
