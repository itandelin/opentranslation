<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$ot_all_count = count( $terms );

// 语言 tab 计数：生效于该语言的术语数（通用 + 专属）
$ot_count_by_lang = array();
foreach ( $languages as $ot_lang ) {
    $ot_count_by_lang[ $ot_lang ] = 0;
}
foreach ( $terms as $ot_term ) {
    $ot_term_lang = isset( $ot_term['language'] ) ? $ot_term['language'] : '';
    foreach ( $languages as $ot_lang ) {
        if ( '' === $ot_term_lang || $ot_term_lang === $ot_lang ) {
            $ot_count_by_lang[ $ot_lang ]++;
        }
    }
}
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <div class="notice notice-info inline">
        <p><?php esc_html_e( 'Terms are replaced with placeholders before the request is sent, so the model never sees the term and the translation always uses the wording you specify. This suits brand names, product models, and proper nouns; it does not suit ordinary words, especially in inflected languages such as Russian. Terms only affect future translations; existing translations are not retranslated automatically.', 'opentranslation' ); ?></p>
    </div>

    <h2 id="ot-term-form-title"><?php esc_html_e( 'Add Term', 'opentranslation' ); ?></h2>
    <form method="post" id="ot-term-form">
        <?php wp_nonce_field( \OpenTranslation\Admin_Glossary::NONCE_ACTION, \OpenTranslation\Admin_Glossary::NONCE_FIELD ); ?>
        <input type="hidden" name="term_index" id="ot-term-index" value="" />
        <table class="form-table">
            <tr>
                <th><label for="ot-term-source"><?php esc_html_e( 'Source', 'opentranslation' ); ?></label></th>
                <td>
                    <input type="text" name="source" id="ot-term-source" class="regular-text" maxlength="100" />
                    <p class="description"><?php esc_html_e( 'The source word to handle verbatim, such as a brand name or product model. It must not contain angle brackets and must not consist of digits only.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ot-term-target"><?php esc_html_e( 'Target', 'opentranslation' ); ?></label></th>
                <td>
                    <input type="text" name="target" id="ot-term-target" class="regular-text" maxlength="200" />
                    <p class="description"><?php esc_html_e( 'Leave empty to keep the source text as is. If filled in, the translation always uses this wording.', 'opentranslation' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ot-term-language"><?php esc_html_e( 'Language', 'opentranslation' ); ?></label></th>
                <td>
                    <select name="language" id="ot-term-language">
                        <option value=""><?php esc_html_e( 'All languages', 'opentranslation' ); ?></option>
                        <?php foreach ( $languages as $ot_lang ) : ?>
                            <option value="<?php echo esc_attr( $ot_lang ); ?>"><?php echo esc_html( $ot_lang ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Matching', 'opentranslation' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="case_sensitive" id="ot-term-case" value="1" checked="checked" />
                        <?php esc_html_e( 'Case sensitive', 'opentranslation' ); ?>
                    </label>
                    <br />
                    <label>
                        <input type="checkbox" name="whole_word" id="ot-term-whole" value="1" checked="checked" />
                        <?php esc_html_e( 'Whole word only (does not match "sensor" inside "sensors")', 'opentranslation' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="ot-term-note"><?php esc_html_e( 'Note', 'opentranslation' ); ?></label></th>
                <td>
                    <input type="text" name="note" id="ot-term-note" class="regular-text" maxlength="200" />
                    <p class="description"><?php esc_html_e( 'Internal note, visible in the admin only.', 'opentranslation' ); ?></p>
                </td>
            </tr>
        </table>
        <p>
            <button type="submit" name="add_term" value="1" class="button button-primary" id="ot-submit-term-add"><?php esc_html_e( 'Add Term', 'opentranslation' ); ?></button>
            <button type="submit" name="update_term" value="1" class="button button-primary" id="ot-submit-term-update" style="display:none;"><?php esc_html_e( 'Update Term', 'opentranslation' ); ?></button>
            <button type="button" class="button" id="ot-cancel-term" style="display:none;"><?php esc_html_e( 'Cancel', 'opentranslation' ); ?></button>
        </p>
    </form>

    <h2><?php esc_html_e( 'Configured Terms', 'opentranslation' ); ?></h2>

    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \OpenTranslation\Admin_Glossary::PAGE ) ); ?>" class="<?php echo '' === $filter_lang ? 'current' : ''; ?>">
                <?php esc_html_e( 'All', 'opentranslation' ); ?>
                <span class="count">(<?php echo esc_html( $ot_all_count ); ?>)</span>
            </a>
        </li>
        <?php foreach ( $languages as $ot_lang ) : ?>
            <li>
                 | <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \OpenTranslation\Admin_Glossary::PAGE . '&lang=' . urlencode( $ot_lang ) ) ); ?>" class="<?php echo $filter_lang === $ot_lang ? 'current' : ''; ?>">
                    <?php echo esc_html( $ot_lang ); ?>
                    <span class="count">(<?php echo esc_html( $ot_count_by_lang[ $ot_lang ] ); ?>)</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
        <thead>
            <tr>
                <th style="width:20%;"><?php esc_html_e( 'Source', 'opentranslation' ); ?></th>
                <th style="width:20%;"><?php esc_html_e( 'Target', 'opentranslation' ); ?></th>
                <th style="width:10%;"><?php esc_html_e( 'Language', 'opentranslation' ); ?></th>
                <th style="width:7%;"><?php esc_html_e( 'Case', 'opentranslation' ); ?></th>
                <th style="width:7%;"><?php esc_html_e( 'Whole word', 'opentranslation' ); ?></th>
                <th style="width:16%;"><?php esc_html_e( 'Note', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $ot_rendered = 0;
            foreach ( $terms as $ot_index => $ot_term ) :
                $ot_lang       = isset( $ot_term['language'] ) ? $ot_term['language'] : '';
                $ot_source     = isset( $ot_term['source'] ) ? $ot_term['source'] : '';
                $ot_target     = isset( $ot_term['target'] ) ? $ot_term['target'] : '';
                $ot_case       = ! empty( $ot_term['case_sensitive'] );
                $ot_whole      = ! empty( $ot_term['whole_word'] );
                $ot_note       = isset( $ot_term['note'] ) ? $ot_term['note'] : '';

                // 语言 tab 展示「生效于该语言」的全部术语（通用 + 专属）
                if ( '' !== $filter_lang && '' !== $ot_lang && $ot_lang !== $filter_lang ) {
                    continue;
                }
                $ot_rendered++;
                ?>
                <tr>
                    <td><code><?php echo esc_html( $ot_source ); ?></code></td>
                    <td><?php echo esc_html( $ot_target === $ot_source ? __( 'Keep untranslated', 'opentranslation' ) : $ot_target ); ?></td>
                    <td><?php echo esc_html( '' === $ot_lang ? __( 'All', 'opentranslation' ) : $ot_lang ); ?></td>
                    <td><?php echo esc_html( $ot_case ? __( 'Yes', 'opentranslation' ) : __( 'No', 'opentranslation' ) ); ?></td>
                    <td><?php echo esc_html( $ot_whole ? __( 'Yes', 'opentranslation' ) : __( 'No', 'opentranslation' ) ); ?></td>
                    <td><?php echo esc_html( $ot_note ); ?></td>
                    <td>
                        <button type="button" class="button button-small ot-edit-term-btn"
                            data-index="<?php echo esc_attr( $ot_index ); ?>"
                            data-source="<?php echo esc_attr( $ot_source ); ?>"
                            data-target="<?php echo esc_attr( $ot_target ); ?>"
                            data-language="<?php echo esc_attr( $ot_lang ); ?>"
                            data-case-sensitive="<?php echo $ot_case ? '1' : '0'; ?>"
                            data-whole-word="<?php echo $ot_whole ? '1' : '0'; ?>"
                            data-note="<?php echo esc_attr( $ot_note ); ?>">
                            <?php esc_html_e( 'Edit', 'opentranslation' ); ?>
                        </button>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( \OpenTranslation\Admin_Glossary::NONCE_ACTION, \OpenTranslation\Admin_Glossary::NONCE_FIELD ); ?>
                            <input type="hidden" name="term_index" value="<?php echo esc_attr( $ot_index ); ?>" />
                            <input type="hidden" name="delete_term" value="1" />
                            <?php submit_button( __( 'Delete', 'opentranslation' ), 'small', 'submit', false ); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( 0 === $ot_rendered ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No terms configured.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>