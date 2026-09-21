<?php
/**
 * Sticky, site-wide tier progress bar for wholesale customers — see
 * class-global-tier-bar.php. A floating Protech Blue dock (full width on
 * phones): tier chip + message, a two-segment progress track with
 * a marker per tier, live totals, and the way to the cart.
 *
 * assets/js/global-tier-bar.js finds its targets by the ids below, so a
 * theme override that keeps those ids keeps working; every other element
 * is optional to it.
 *
 * Override by copying to yourtheme/woocommerce/global-tier-bar.php.
 *
 * @package ProtechWholesale
 *
 * @var array{tier: string, tier_label: string, displays: float, cases: float, fill_percent: float, message: string, message_html: string, stats: string, subtotal: float, subtotal_html: string, savings_html: string, volume_price_html: string, bulk_price_html: string, volume_threshold_displays: int, bulk_threshold_cases: int, volume_marker_percent: float, scale: array<string, int|float>, ticks: array<int, array{percent: float, cases: int}>} $state
 * @var string $cart_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// What the script needs to preview an add-to-cart and to notice a tier
// change that happened through a full page reload.
$protech_bar_data = array(
	'tier'                 => $state['tier'],
	'displays'             => $state['displays'],
	'cases'                => $state['cases'],
	'subtotal'             => $state['subtotal'],
	'bulk_threshold_cases' => $state['bulk_threshold_cases'],
	'scale'                => $state['scale'],
);

$protech_volume_reward = '' !== $state['volume_price_html']
	/* translators: %s: price per pack. */
	? sprintf( __( '%s/pack + free shipping', 'protech-wholesale' ), $state['volume_price_html'] )
	: __( 'Better price + free shipping', 'protech-wholesale' );

$protech_bulk_reward = '' !== $state['bulk_price_html']
	/* translators: %s: price per pack. */
	? sprintf( __( '%s/pack, our best price', 'protech-wholesale' ), $state['bulk_price_html'] )
	: __( 'Our best price', 'protech-wholesale' );
?>
<div
	class="protech-tier-bar"
	id="protech-global-tier-bar"
	data-active-tier="<?php echo esc_attr( $state['tier'] ); ?>"
	data-state="<?php echo esc_attr( (string) wp_json_encode( $protech_bar_data ) ); ?>"
	role="region"
	aria-label="<?php esc_attr_e( 'Wholesale pricing progress', 'protech-wholesale' ); ?>"
>
	<span class="protech-tier-bar-sheen" aria-hidden="true"></span>

	<div class="protech-tier-bar-inner">
		<div class="protech-tier-bar-info">
			<span class="protech-tier-bar-chip">
				<span class="protech-tier-bar-chip-prefix"><?php esc_html_e( 'Wholesale', 'protech-wholesale' ); ?></span>
				<span class="protech-tier-bar-chip-tier" id="protech-global-tier-bar-tier"><?php echo esc_html( $state['tier_label'] ); ?></span>
			</span>
			<p class="protech-tier-bar-message" id="protech-global-tier-bar-message" role="status" aria-live="polite"><?php echo wp_kses( $state['message_html'], array( 'strong' => array() ) ); ?></p>
		</div>

		<div class="protech-tier-bar-progress">
			<div class="protech-tier-bar-track">
				<div class="protech-tier-bar-rail">
					<div class="protech-tier-bar-ghost" id="protech-global-tier-bar-ghost"></div>
					<div class="protech-tier-bar-fill" id="protech-global-tier-bar-fill" style="width:<?php echo esc_attr( (string) $state['fill_percent'] ); ?>%"><span class="protech-tier-bar-shine"></span></div>
					<?php foreach ( $state['ticks'] as $protech_tick ) : ?>
						<span class="protech-tier-bar-tick" style="left:<?php echo esc_attr( (string) $protech_tick['percent'] ); ?>%" title="<?php echo esc_attr( sprintf( /* translators: %d: number of cases. */ _n( '%d case', '%d cases', $protech_tick['cases'], 'protech-wholesale' ), $protech_tick['cases'] ) ); ?>"></span>
					<?php endforeach; ?>
				</div>

				<div class="protech-tier-marker protech-tier-marker--standard" style="left:0%">
					<span class="protech-tier-marker-label"><?php echo esc_html( sprintf( /* translators: %d: number of displays. */ __( 'Under %d displays', 'protech-wholesale' ), $state['volume_threshold_displays'] ) ); ?></span>
					<span class="protech-tier-marker-dot"></span>
					<span class="protech-tier-marker-reward"><?php esc_html_e( 'Wholesale price', 'protech-wholesale' ); ?></span>
				</div>

				<div class="protech-tier-marker protech-tier-marker--volume" id="protech-global-tier-bar-marker-volume" style="left:<?php echo esc_attr( (string) $state['volume_marker_percent'] ); ?>%" title="<?php echo esc_attr( sprintf( /* translators: %d: number of displays. */ __( 'Free shipping and Standard pricing at %d displays', 'protech-wholesale' ), $state['volume_threshold_displays'] ) ); ?>">
					<span class="protech-tier-marker-label"><?php echo esc_html( sprintf( /* translators: %d: number of displays. */ __( '%d displays', 'protech-wholesale' ), $state['volume_threshold_displays'] ) ); ?></span>
					<span class="protech-tier-marker-dot"></span>
					<span class="protech-tier-marker-reward"><?php echo wp_kses_post( $protech_volume_reward ); ?></span>
				</div>

				<div class="protech-tier-marker protech-tier-marker--bulk" style="left:100%" title="<?php echo esc_attr( sprintf( /* translators: %d: number of cases. */ __( 'Best price at %d cases', 'protech-wholesale' ), $state['bulk_threshold_cases'] ) ); ?>">
					<span class="protech-tier-marker-label"><?php echo esc_html( sprintf( /* translators: %d: number of cases. */ __( '%d cases', 'protech-wholesale' ), $state['bulk_threshold_cases'] ) ); ?></span>
					<span class="protech-tier-marker-dot"></span>
					<span class="protech-tier-marker-reward"><?php echo wp_kses_post( $protech_bulk_reward ); ?></span>
				</div>
			</div>
		</div>

		<div class="protech-tier-bar-summary">
			<span class="protech-tier-bar-total-price" id="protech-global-tier-bar-subtotal" data-protech-subtotal><?php echo wp_kses_post( $state['subtotal_html'] ); ?></span>
			<span class="protech-tier-bar-total-count" id="protech-global-tier-bar-stats"><?php echo esc_html( $state['stats'] ); ?></span>
			<span class="protech-tier-bar-savings" id="protech-global-tier-bar-savings"<?php echo '' === $state['savings_html'] ? ' hidden' : ''; ?>>
				<?php esc_html_e( 'Saving', 'protech-wholesale' ); ?>
				<span id="protech-global-tier-bar-savings-amount"><?php echo wp_kses_post( $state['savings_html'] ); ?></span>
			</span>
		</div>

		<a href="<?php echo esc_url( $cart_url ); ?>" class="protech-tier-bar-cta" id="protech-global-tier-bar-cta">
			<svg class="protech-tier-bar-cta-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M3 4h2.2l2.1 10.2a1.6 1.6 0 0 0 1.6 1.3h8.3a1.6 1.6 0 0 0 1.6-1.2L20.5 8H6.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="19.5" r="1.4" fill="currentColor"/><circle cx="17" cy="19.5" r="1.4" fill="currentColor"/></svg>
			<span class="protech-tier-bar-cta-label"><?php esc_html_e( 'View cart', 'protech-wholesale' ); ?></span>
			<span class="protech-tier-bar-cta-total" data-protech-subtotal aria-hidden="true"><?php echo wp_kses_post( $state['subtotal_html'] ); ?></span>
		</a>
	</div>
</div>
