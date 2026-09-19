<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$fmt = function ( $n ) { return number_format_i18n( (int) $n ); };
$currency_of = function ( $key ) use ( $pricing ) { return isset( $pricing[ $key ]['currency'] ) ? $pricing[ $key ]['currency'] : 'USD'; };
$first_currency = ! empty( $pricing ) ? reset( $pricing )['currency'] : 'USD';
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Usage', 'opentranslation' ); ?></h1>
    <p class="description"><?php esc_html_e( 'How this is counted: each HTTP attempt counts as one request (including retries and split requests); token counts come from the usage field of the model response; dates are in UTC. Costs are estimates for reference only; the provider invoice is authoritative.', 'opentranslation' ); ?></p>

    <h2><?php esc_html_e( 'This Month', 'opentranslation' ); ?></h2>
    <ul>
        <li><strong><?php esc_html_e( 'Requests', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $fmt( $month['requests'] ) ); ?></li>
        <li><strong><?php esc_html_e( 'Prompt tokens', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $fmt( $month['prompt_tokens'] ) ); ?></li>
        <li><strong><?php esc_html_e( 'Completion tokens', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $fmt( $month['completion_tokens'] ) ); ?></li>
        <li><strong><?php esc_html_e( 'Total tokens', 'opentranslation' ); ?>:</strong> <?php echo esc_html( $fmt( $month['total_tokens'] ) ); ?></li>
        <li><strong><?php esc_html_e( 'Estimated cost', 'opentranslation' ); ?>:</strong>
            <?php if ( null === $month_cost ) : ?>
                <em><?php esc_html_e( 'No pricing configured', 'opentranslation' ); ?></em>
            <?php else : ?>
                <?php echo esc_html( number_format_i18n( $month_cost, 4 ) . ' ' . $first_currency ); ?>
                <small><?php esc_html_e( '(only models with pricing configured)', 'opentranslation' ); ?></small>
            <?php endif; ?>
        </li>
    </ul>

    <h2><?php printf( esc_html__( 'By Model (last %d days)', 'opentranslation' ), (int) $days ); ?></h2>
    <form method="post">
        <?php wp_nonce_field( 'opentranslation_pricing_save', 'opentranslation_usage_nonce' ); ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:24%;"><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                    <th style="width:8%;"><?php esc_html_e( 'Requests', 'opentranslation' ); ?></th>
                    <th style="width:9%;"><?php esc_html_e( 'Prompt', 'opentranslation' ); ?></th>
                    <th style="width:9%;"><?php esc_html_e( 'Completion', 'opentranslation' ); ?></th>
                    <th style="width:9%;"><?php esc_html_e( 'Total', 'opentranslation' ); ?></th>
                    <th style="width:10%;"><?php esc_html_e( 'Prompt / 1K', 'opentranslation' ); ?></th>
                    <th style="width:10%;"><?php esc_html_e( 'Completion / 1K', 'opentranslation' ); ?></th>
                    <th style="width:7%;"><?php esc_html_e( 'Currency', 'opentranslation' ); ?></th>
                    <th><?php esc_html_e( 'Estimated cost', 'opentranslation' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $by_model ) ) : ?>
                    <?php foreach ( $by_model as $key => $row ) : ?>
                        <?php
                        $cost   = \OpenTranslation\Admin_Usage::estimate( $row, $pricing );
                        $no_usage = $row['requests'] > 0 && 0 === $row['total_tokens'];
                        ?>
                        <tr>
                            <td><?php echo esc_html( $row['model_label'] ); ?>
                                <?php if ( $no_usage ) : ?><br /><small style="color:#d63638;"><?php esc_html_e( 'This model returned no usage data', 'opentranslation' ); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $fmt( $row['requests'] ) ); ?></td>
                            <td><?php echo esc_html( $fmt( $row['prompt_tokens'] ) ); ?></td>
                            <td><?php echo esc_html( $fmt( $row['completion_tokens'] ) ); ?></td>
                            <td><?php echo esc_html( $fmt( $row['total_tokens'] ) ); ?></td>
                            <td><input type="number" step="0.0001" min="0" style="width:90px;" name="pricing[<?php echo esc_attr( $key ); ?>][prompt_per_1k]" value="<?php echo esc_attr( $pricing[ $key ]['prompt_per_1k'] ?? '' ); ?>" /></td>
                            <td><input type="number" step="0.0001" min="0" style="width:90px;" name="pricing[<?php echo esc_attr( $key ); ?>][completion_per_1k]" value="<?php echo esc_attr( $pricing[ $key ]['completion_per_1k'] ?? '' ); ?>" /></td>
                            <td><input type="text" maxlength="3" style="width:50px;" name="pricing[<?php echo esc_attr( $key ); ?>][currency]" value="<?php echo esc_attr( $currency_of( $key ) ); ?>" /></td>
                            <td><?php echo null === $cost ? '—' : esc_html( number_format_i18n( $cost, 4 ) . ' ' . $currency_of( $key ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="9"><?php esc_html_e( 'No usage recorded yet.', 'opentranslation' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ( ! empty( $by_model ) ) : ?>
            <?php submit_button( __( 'Save Pricing', 'opentranslation' ) ); ?>
        <?php endif; ?>
    </form>

    <h2><?php printf( esc_html__( 'Daily (last %d days)', 'opentranslation' ), (int) $days ); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:12%;"><?php esc_html_e( 'Date (UTC)', 'opentranslation' ); ?></th>
                <th style="width:30%;"><?php esc_html_e( 'Model', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Requests', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Prompt', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Completion', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Total', 'opentranslation' ); ?></th>
                <th><?php esc_html_e( 'Estimated cost', 'opentranslation' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $daily ) ) : ?>
                <?php foreach ( $daily as $row ) : ?>
                    <?php $cost = \OpenTranslation\Admin_Usage::estimate( $row, $pricing ); ?>
                    <tr>
                        <td><?php echo esc_html( $row['usage_date'] ); ?></td>
                        <td><?php echo esc_html( $row['model_label'] ); ?></td>
                        <td><?php echo esc_html( $fmt( $row['requests'] ) ); ?></td>
                        <td><?php echo esc_html( $fmt( $row['prompt_tokens'] ) ); ?></td>
                        <td><?php echo esc_html( $fmt( $row['completion_tokens'] ) ); ?></td>
                        <td><?php echo esc_html( $fmt( $row['total_tokens'] ) ); ?></td>
                        <td><?php echo null === $cost ? '—' : esc_html( number_format_i18n( $cost, 4 ) . ' ' . $currency_of( $row['model_key'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No usage recorded yet.', 'opentranslation' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
