<?php
/**
 * "Your wholesale account" card on the My Account dashboard — see
 * MyAccount::render_wholesale_dashboard_panel(). The same cart state the
 * sticky tier bar shows, laid out as a card: current tier, a progress
 * rail, the cart in displays/cases/dollars, what each tier unlocks.
 *
 * Override by copying to yourtheme/woocommerce/account-wholesale-panel.php.
 *
 * @package ProtechWholesale
 *
 * @var array{tier: string, displays: float, cases: float, fill_percent: float, message: string, stats: string, subtotal_html: string, savings_html: string, volume_price_html: string, bulk_price_html: string, volume_threshold_displays: int, bulk_threshold_cases: int, volume_marker_percent: float} $state
 * @var string $tier_label
 * @var string $displays_label
 * @var string $cases_label
 * @var string $shop_url
 * @var string $cart_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$protech_reached_volume = in_array( $state['tier'], array( 'volume', 'bulk' ), true );
$protech_reached_bulk   = 'bulk' === $state['tier'];

$protech_tiers = array(
	array(
		'reached' => true,
		'name'    => __( 'Standard', 'protech-wholesale' ),
		'detail'  => __( 'Your wholesale price on every order, any quantity.', 'protech-wholesale' ),
	),
	array(
		'reached' => $protech_reached_volume,
		'name'    => __( 'Volume', 'protech-wholesale' ),
		/* translators: %d: number of displays. */
		'detail'  => sprintf( __( 'Better pricing and free shipping from %d combined displays.', 'protech-wholesale' ), $state['volume_threshold_displays'] ),
	),
	array(
		'reached' => $protech_reached_bulk,
		'name'    => __( 'Bulk', 'protech-wholesale' ),
		/* translators: %d: number of cases. */
		'detail'  => sprintf( __( 'Our best price from %d combined cases.', 'protech-wholesale' ), $state['bulk_threshold_cases'] ),
	),
);
?>
<section class="protech-account-panel" data-active-tier="<?php echo esc_attr( $state['tier'] ); ?>">
	<header class="protech-account-panel-head">
		<h3 class="protech-account-panel-title"><?php esc_html_e( 'Your wholesale account', 'protech-wholesale' ); ?></h3>
		<span class="protech-badge protech-badge--on-blue"><?php echo esc_html( $tier_label ); ?></span>
	</header>

	<div class="protech-account-panel-body">
		<p class="protech-account-panel-message"><?php echo esc_html( $state['message'] ); ?></p>

		<div class="protech-account-panel-rail" role="img" aria-label="<?php echo esc_attr( $state['stats'] ); ?>">
			<span class="protech-account-panel-rail-fill" style="width:<?php echo esc_attr( (string) $state['fill_percent'] ); ?>%"></span>
			<span class="protech-account-panel-rail-mark" style="left:<?php echo esc_attr( (string) $state['volume_marker_percent'] ); ?>%"></span>
		</div>

		<dl class="protech-account-panel-stats">
			<div class="protech-account-panel-stat">
				<dt><?php esc_html_e( 'Displays in cart', 'protech-wholesale' ); ?></dt>
				<dd><?php echo esc_html( $displays_label ); ?></dd>
			</div>
			<div class="protech-account-panel-stat">
				<dt><?php esc_html_e( 'Cases', 'protech-wholesale' ); ?></dt>
				<dd><?php echo esc_html( $cases_label ); ?></dd>
			</div>
			<div class="protech-account-panel-stat">
				<dt><?php esc_html_e( 'Cart subtotal', 'protech-wholesale' ); ?></dt>
				<dd><?php echo wp_kses_post( $state['subtotal_html'] ); ?></dd>
			</div>
			<?php if ( '' !== $state['savings_html'] ) : ?>
				<div class="protech-account-panel-stat protech-account-panel-stat--savings">
					<dt><?php esc_html_e( 'Savings vs retail', 'protech-wholesale' ); ?></dt>
					<dd><?php echo wp_kses_post( $state['savings_html'] ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>

		<ul class="protech-account-panel-tiers">
			<?php foreach ( $protech_tiers as $protech_tier ) : ?>
				<li class="protech-account-panel-tier<?php echo $protech_tier['reached'] ? ' is-reached' : ''; ?>">
					<span class="protech-account-panel-tier-check" aria-hidden="true"></span>
					<span>
						<strong><?php echo esc_html( $protech_tier['name'] ); ?></strong>
						<?php if ( $protech_tier['reached'] ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( '(unlocked)', 'protech-wholesale' ); ?></span>
						<?php endif; ?>
						<span class="protech-account-panel-tier-detail"><?php echo esc_html( $protech_tier['detail'] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="protech-account-panel-actions">
			<a class="protech-button" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Shop at wholesale pricing', 'protech-wholesale' ); ?></a>
			<a class="protech-button protech-button--ghost" href="<?php echo esc_url( $cart_url ); ?>"><?php esc_html_e( 'View cart', 'protech-wholesale' ); ?></a>
		</p>
	</div>
</section>
