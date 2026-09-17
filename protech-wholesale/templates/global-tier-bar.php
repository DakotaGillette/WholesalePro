<?php
/**
 * Sticky, site-wide tier progress bar for wholesale customers — see
 * class-global-tier-bar.php. Structure modeled on Bambu Lab's bulk-
 * discount bar (a dark message/totals strip + a light track panel with
 * threshold markers, collapsing to just the message + a slim track on
 * narrow screens — see wholesale.css), rebuilt with this site's own
 * black/white palette instead of Bambu's brand colors.
 *
 * Override by copying to yourtheme/woocommerce/global-tier-bar.php.
 *
 * @package ProtechWholesale
 *
 * @var array{tier: string, displays: float, cases: float, fill_percent: float, message: string, stats: string, subtotal_html: string, volume_threshold_displays: int, bulk_threshold_cases: int, volume_marker_percent: float} $state
 * @var string $cart_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div
	class="protech-tier-bar"
	id="protech-global-tier-bar"
	data-active-tier="<?php echo esc_attr( $state['tier'] ); ?>"
>
	<div class="protech-tier-bar-header">
		<p class="protech-tier-bar-message" id="protech-global-tier-bar-message"><?php echo esc_html( $state['message'] ); ?></p>
		<div class="protech-tier-bar-totals">
			<span class="protech-tier-bar-total-price" id="protech-global-tier-bar-subtotal"><?php echo wp_kses_post( $state['subtotal_html'] ); ?></span>
			<span class="protech-tier-bar-total-count" id="protech-global-tier-bar-stats"><?php echo esc_html( $state['stats'] ); ?></span>
		</div>
	</div>
	<div class="protech-tier-bar-panel">
		<div class="protech-tier-bar-track">
			<div class="protech-tier-bar-fill" id="protech-global-tier-bar-fill" style="width:<?php echo esc_attr( (string) $state['fill_percent'] ); ?>%"></div>
			<div class="protech-tier-marker protech-tier-marker--volume" id="protech-global-tier-bar-marker-volume" style="left:<?php echo esc_attr( (string) $state['volume_marker_percent'] ); ?>%">
				<span class="protech-tier-marker-label"><?php echo esc_html( sprintf( /* translators: %d: number of displays. */ __( '%d displays', 'protech-wholesale' ), $state['volume_threshold_displays'] ) ); ?></span>
				<span class="protech-tier-marker-dot"></span>
				<span class="protech-tier-marker-reward"><?php esc_html_e( 'Free shipping', 'protech-wholesale' ); ?></span>
			</div>
			<div class="protech-tier-marker protech-tier-marker--bulk" style="left:100%">
				<span class="protech-tier-marker-label"><?php echo esc_html( sprintf( /* translators: %d: number of cases. */ __( '%d cases', 'protech-wholesale' ), $state['bulk_threshold_cases'] ) ); ?></span>
				<span class="protech-tier-marker-dot"></span>
				<span class="protech-tier-marker-reward"><?php esc_html_e( 'Best price', 'protech-wholesale' ); ?></span>
			</div>
		</div>
	</div>
	<a href="<?php echo esc_url( $cart_url ); ?>" class="protech-tier-bar-cta"><?php esc_html_e( 'View cart', 'protech-wholesale' ); ?></a>
</div>
