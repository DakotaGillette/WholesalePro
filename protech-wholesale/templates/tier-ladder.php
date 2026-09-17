<?php
/**
 * Wholesale quantity-tier price table on the single product page — see
 * class-tier-ladder.php.
 *
 * Override by copying to yourtheme/woocommerce/tier-ladder.php.
 *
 * @package ProtechWholesale
 *
 * @var array<int, array{tier: string, label: string, threshold: string, note: string, price_html: string}> $rows
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
				<th scope="col"><?php esc_html_e( 'Price per pack', 'protech-wholesale' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $is_active = $row['tier'] === $active_tier; ?>
				<tr class="protech-tier-ladder-row<?php echo $is_active ? ' is-active' : ''; ?>">
					<td>
						<?php echo esc_html( $row['label'] ); ?>
						<?php if ( $is_active ) : ?>
							<span class="protech-tier-ladder-badge"><?php esc_html_e( 'Your cart', 'protech-wholesale' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $row['threshold'] ); ?>
						<?php if ( '' !== $row['note'] ) : ?>
							<span class="protech-tier-ladder-note"><?php echo esc_html( $row['note'] ); ?></span>
						<?php endif; ?>
					</td>
					<td class="protech-tier-ladder-price"><?php echo wp_kses_post( $row['price_html'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="protech-tier-ladder-footnote"><?php echo esc_html( $footnote ); ?></p>
</div>
