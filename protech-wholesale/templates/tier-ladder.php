<?php
/**
 * Wholesale quantity-tier price table on the single product page — see
 * class-tier-ladder.php.
 *
 * Every row carries its tier in data-tier and its own (CSS-hidden) "Your
 * cart" badge, so assets/js/global-tier-bar.js can move the highlight to
 * the right row the moment the cart crosses a tier — no reload needed.
 *
 * Override by copying to yourtheme/woocommerce/tier-ladder.php.
 *
 * @package ProtechWholesale
 *
 * @var array<int, array{tier: string, label: string, threshold: string, note: string, price_html: string, min: float, max: float, msrp: ?float, msrp_html: string}> $rows
 * @var string $active_tier
 * @var string $footnote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="protech-tier-ladder" aria-label="<?php esc_attr_e( 'Wholesale quantity pricing', 'protech-wholesale' ); ?>">
	<p class="protech-tier-ladder-title"><?php esc_html_e( 'Wholesale pricing', 'protech-wholesale' ); ?></p>
	<table class="protech-tier-ladder-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Tier', 'protech-wholesale' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Cart quantity', 'protech-wholesale' ); ?></th>
				<th scope="col"><?php esc_html_e( 'MSRP', 'protech-wholesale' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Price per pack', 'protech-wholesale' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$is_active       = $row['tier'] === $active_tier;
				$protech_row_min = isset( $row['min'] ) ? (float) $row['min'] : 0.0;
				$protech_msrp    = isset( $row['msrp'] ) ? (float) $row['msrp'] : 0.0;
				// "Save N%" is measured against MSRP now, not the Standard
				// row — real even at Standard, since wholesale already
				// undercuts retail before any quantity tier is reached.
				$protech_saving  = ( $protech_msrp > 0 && $protech_row_min > 0 && $protech_row_min < $protech_msrp )
					? (int) round( ( 1 - $protech_row_min / $protech_msrp ) * 100 )
					: 0;
				?>
				<tr class="protech-tier-ladder-row<?php echo $is_active ? ' is-active' : ''; ?>" data-tier="<?php echo esc_attr( $row['tier'] ); ?>">
					<td>
						<?php echo esc_html( $row['label'] ); ?>
						<span class="protech-tier-ladder-badge"><?php esc_html_e( 'Your cart', 'protech-wholesale' ); ?></span>
					</td>
					<td>
						<?php echo esc_html( $row['threshold'] ); ?>
						<?php if ( '' !== $row['note'] ) : ?>
							<span class="protech-tier-ladder-note"><?php echo esc_html( $row['note'] ); ?></span>
						<?php endif; ?>
					</td>
					<td class="protech-tier-ladder-msrp">
						<?php if ( '' !== $row['msrp_html'] ) : ?>
							<del><?php echo wp_kses_post( $row['msrp_html'] ); ?></del>
						<?php else : ?>
							&#8212;
						<?php endif; ?>
					</td>
					<td class="protech-tier-ladder-price">
						<?php echo wp_kses_post( $row['price_html'] ); ?>
						<?php if ( $protech_saving > 0 ) : ?>
							<span class="protech-tier-ladder-saving"><?php echo esc_html( sprintf( /* translators: %d: percentage saved against MSRP. */ __( 'Save %d%% off MSRP', 'protech-wholesale' ), $protech_saving ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="protech-tier-ladder-footnote"><?php echo esc_html( $footnote ); ?></p>
</div>
