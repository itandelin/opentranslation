<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$system_prompt = $settings['system_prompt'] ?? \OpenTranslation\Admin::default_system_prompt();
$plugin_language = $settings['plugin_language'] ?? 'zh_CN';

// 桶 = 各公开 post_type + 未关联桶；不再依赖字典表统计
$ot_buckets = array( \OpenTranslation\Scope::UNLINKED => __( 'Not linked to a post', 'opentranslation' ) );
foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $ot_type ) {
    $ot_buckets[ $ot_type->name ] = $ot_type->labels->singular_name ?: $ot_type->name;
}
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'opentranslation_settings' ); ?>
        <table class="form-table">
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
        <p class="description"><?php esc_html_e( 'Choose the translation scope by the type of page being rendered. "Not linked to a post" covers the front page, archives, search, 404, and anything outside a single post.', 'opentranslation' ); ?></p>

        <?php if ( empty( $languages ) ) : ?>
            <p><?php esc_html_e( 'No target languages configured in TranslatePress.', 'opentranslation' ); ?></p>
        <?php else : ?>
            <?php foreach ( $languages as $ot_lang ) : ?>
                <?php
                $ot_cfg   = isset( $scope[ $ot_lang ] ) ? $scope[ $ot_lang ] : array( 'mode' => 'all', 'buckets' => array(), 'published_only' => false );
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
                            <?php foreach ( $ot_buckets as $ot_bucket => $ot_label ) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox"
                                        name="<?php echo esc_attr( $ot_field ); ?>[buckets][]"
                                        value="<?php echo esc_attr( $ot_bucket ); ?>"
                                        <?php checked( in_array( $ot_bucket, $ot_cfg['buckets'], true ) ); ?> />
                                    <?php echo esc_html( $ot_label ); ?>
                                </label>
                            <?php endforeach; ?>

                            <label style="display:block;margin-top:8px;padding-top:8px;border-top:1px solid #dcdcde;">
                                <input type="checkbox"
                                    name="<?php echo esc_attr( $ot_field ); ?>[published_only]"
                                    value="1"
                                    <?php checked( ! empty( $ot_cfg['published_only'] ) ); ?>
                                    <?php disabled( 'exclude' === $ot_cfg['mode'] ); ?> />
                                <?php esc_html_e( 'Linked entries: only translate those attached to published posts', 'opentranslation' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Applies to include mode only. The "Not linked to a post" bucket is never affected by this option.', 'opentranslation' ); ?></p>
                        </td>
                    </tr>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php submit_button(); ?>
    </form>
</div>
