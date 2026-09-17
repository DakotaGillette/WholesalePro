<?php
/**
 * "Your wholesale account" panel on the My Account dashboard — see
 * MyAccount::render_wholesale_dashboard_panel().
 *
 * Override by copying to yourtheme/woocommerce/account-wholesale-panel.php.
 *
 * @package ProtechWholesale
 *
 * @var array{tier: string, displays: float, cases: float, message: string, stats: string, subtotal_html: string, volume_threshold_displays: int, bulk_threshold_cases: int} $state
 * @var string $tier_label
 * @var string $shop_url
 * @var string $cart_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="protech-account-panel">
	<h3 class="protech-account-panel-title"><?php esc_html_e( 'Your wholesale account', 'protech-wholesale' ); ?></h3>
	<p class="protech-account-panel-status">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: current tier label, 2: cart stats, 3: cart subtotal. */
				__( 'Your cart is at %1$s: %2$s, %3$s.', 'protech-wholesale' ),
				$tier_label,
				$state['stats'],
				$state['subtotal_html']
			)
		);
		?>
		<span class="protech-account-panel-message"><?php echo esc_html( $state['message'] ); ?></span>
	</p>
	<ul class="protech-account-panel-tiers">
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of displays. */
					__( 'Volume pricing and free shipping from %d combined displays.', 'protech-wholesale' ),
					$state['volume_threshold_displays']
				)
			);
			?>
		</li>
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of cases. */
					__( 'Bulk pricing (our best price) from %d combined cases.', 'protech-wholesale' ),
					$state['bulk_threshold_cases']
				)
			);
			?>
		</li>
	</ul>
	<p class="protech-account-panel-actions">
		<a class="button" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Shop at wholesale pricing', 'protech-wholesale' ); ?></a>
		<a href="<?php echo esc_url( $cart_url ); ?>"><?php esc_html_e( 'View cart', 'protech-wholesale' ); ?></a>
	</p>
</section>
