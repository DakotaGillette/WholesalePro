<?php
/**
 * Quick Order form grid — one row per wholesale-eligible product/variation.
 * Quantities are entered in cases; JS (assets/js/order-form.js) computes
 * line totals client-side and posts the whole grid via AJAX.
 *
 * Override by copying to yourtheme/woocommerce/order-form.php.
 *
 * @package ProtechWholesale
 *
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, int>                  $prefill
 * @var string                           $prefill_note
 * @var \WC_Order|null                   $last_order
 * @var float                            $minimum
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="protech-order-form-wrap">

	<?php if ( $prefill_note ) : ?>
		<div class="woocommerce-info protech-reorder-note"><?php echo esc_html( $prefill_note ); ?></div>
	<?php endif; ?>

	<?php if ( $last_order && empty( $prefill ) ) : ?>
		<div class="protech-reorder-card">
			<p>
				<?php
				printf(
					/* translators: 1: order number, 2: order date. */
					esc_html__( 'Your last order (#%1$s, %2$s) is ready to reorder.', 'protech-wholesale' ),
					esc_html( $last_order->get_order_number() ),
					esc_html( wc_format_datetime( $last_order->get_date_created() ) )
				);
				?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'reorder', $last_order->get_id() ) ); ?>">
					<?php esc_html_e( 'Reorder last order', 'protech-wholesale' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<div id="protech-order-form-notice" class="protech-order-form-notice" role="status" aria-live="polite"></div>

	<form id="protech-order-form">
		<div class="protech-order-table-scroll">
			<table class="protech-order-table">
				<thead>
					<tr>
						<th class="protech-col-image"></th>
						<th><?php esc_html_e( 'Product', 'protech-wholesale' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'protech-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Stock', 'protech-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Price / case', 'protech-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Cases', 'protech-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Line total', 'protech-wholesale' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr class="protech-order-row" data-item-id="<?php echo esc_attr( (string) $row['item_id'] ); ?>" data-case-size="<?php echo esc_attr( (string) $row['case_size'] ); ?>" data-price-per-case="<?php echo esc_attr( (string) $row['price_per_case'] ); ?>">
							<td class="protech-col-image"><?php echo wp_kses_post( $row['image'] ); ?></td>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><?php echo esc_html( $row['sku'] ); ?></td>
							<td><?php echo esc_html( $row['stock_cases'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['price_per_case'] ) ); ?></td>
							<td>
								<input
									type="number"
									class="protech-case-qty"
									min="0"
									step="1"
									inputmode="numeric"
									name="cases[<?php echo esc_attr( (string) $row['item_id'] ); ?>]"
									value="<?php echo esc_attr( (string) ( $prefill[ $row['item_id'] ] ?? 0 ) ); ?>"
									<?php disabled( ! $row['in_stock'] ); ?>
								/>
							</td>
							<td class="protech-line-total" data-item-id="<?php echo esc_attr( (string) $row['item_id'] ); ?>">
								<?php echo wp_kses_post( wc_price( 0 ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<button type="button" id="protech-add-all-to-cart" class="button alt">
			<?php esc_html_e( 'Add all to cart', 'protech-wholesale' ); ?>
		</button>
	</form>

	<div id="protech-order-summary" class="protech-order-summary" data-minimum="<?php echo esc_attr( (string) $minimum ); ?>">
		<div class="protech-summary-row">
			<span><?php esc_html_e( 'Cases', 'protech-wholesale' ); ?></span>
			<strong id="protech-summary-cases">0</strong>
		</div>
		<div class="protech-summary-row">
			<span><?php esc_html_e( 'Subtotal', 'protech-wholesale' ); ?></span>
			<strong id="protech-summary-subtotal"><?php echo wp_kses_post( wc_price( 0 ) ); ?></strong>
		</div>
		<div class="protech-summary-progress">
			<div class="protech-summary-progress-bar" id="protech-summary-progress-bar"></div>
		</div>
		<p id="protech-summary-remaining" class="protech-summary-remaining"></p>
		<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" id="protech-go-to-checkout" class="button">
			<?php esc_html_e( 'Go to checkout', 'protech-wholesale' ); ?>
		</a>
	</div>
</div>
