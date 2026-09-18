<?php
/**
 * Internal wholesale tiers (Bronze/Silver/Gold/Platinum) — a hidden
 * classification layered on top of the wholesale_customer role. Tiers
 * are never shown to the customer anywhere (shop, portal, emails); they
 * exist purely so the store owner can give a group of customers a price
 * discount without touching every customer individually. A per-customer
 * price override (Approval::META_PRICE_OVERRIDES) still always wins over
 * the customer's tier — tiers sit between the global default and that
 * per-customer layer, not above it.
 *
 * Bronze is deliberately not a row in the tier settings table: it IS
 * the default (each product's wholesale price, no discount), so "assign
 * everyone to Bronze by default" falls out for free.
 *
 * The dollar "minimum order" this class once carried per tier was
 * removed in 1.3.0: nothing had enforced it since the Display/Case
 * quantity ladder replaced it, and the fields only misled. An old
 * saved option may still hold a `min_order` key per tier; it is ignored.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tiers
 */
class Tiers {

	public const BRONZE   = 'bronze';
	public const SILVER   = 'silver';
	public const GOLD     = 'gold';
	public const PLATINUM = 'platinum';

	/** Tiers with their own overridable settings — Bronze is the implicit global default, not a row here. */
	private const OVERRIDABLE_TIERS = array( self::SILVER, self::GOLD, self::PLATINUM );

	public const META_USER_TIER    = '_protech_wholesale_tier';
	public const OPT_TIER_SETTINGS = 'protech_wholesale_tier_settings';

	public function register_hooks(): void {
		// Tier settings are rendered/saved from Approval's admin page (the
		// "Tiers" tab); tier assignment is rendered/saved from Approval's
		// per-user profile fields. Nothing to hook globally here.
	}

	/**
	 * @return array<string, string> tier slug => label, in tier order.
	 */
	public static function get_tier_labels(): array {
		return array(
			self::BRONZE   => __( 'Bronze (default)', 'protech-wholesale' ),
			self::SILVER   => __( 'Silver', 'protech-wholesale' ),
			self::GOLD     => __( 'Gold', 'protech-wholesale' ),
			self::PLATINUM => __( 'Platinum', 'protech-wholesale' ),
		);
	}

	public static function get_user_tier( int $user_id ): string {
		$tier = (string) get_user_meta( $user_id, self::META_USER_TIER, true );

		return array_key_exists( $tier, self::get_tier_labels() ) ? $tier : self::BRONZE;
	}

	public static function set_user_tier( int $user_id, string $tier ): void {
		if ( ! array_key_exists( $tier, self::get_tier_labels() ) ) {
			return;
		}

		if ( self::BRONZE === $tier ) {
			delete_user_meta( $user_id, self::META_USER_TIER );
		} else {
			update_user_meta( $user_id, self::META_USER_TIER, $tier );
		}
	}

	/**
	 * @return array<string, array{discount_percent: string}>
	 */
	private static function get_all_tier_settings(): array {
		$settings = get_option( self::OPT_TIER_SETTINGS, array() );

		return is_array( $settings ) ? $settings : array();
	}

	public static function get_tier_discount_percent( string $tier ): float {
		if ( self::BRONZE === $tier ) {
			return 0.0;
		}

		$value = self::get_all_tier_settings()[ $tier ]['discount_percent'] ?? '';

		return ( '' !== $value && is_numeric( $value ) ) ? (float) $value : 0.0;
	}

	/**
	 * The "Tiers" tab of the WooCommerce → Wholesale admin screen.
	 */
	public static function render_tiers_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		if ( isset( $_POST['protech_wholesale_tiers_nonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_tiers_nonce'] ) ),
				'protech_wholesale_save_tiers'
			)
		) {
			self::save_tiers_tab();
			echo '<div class="updated notice"><p>' . esc_html__( 'Tier settings saved.', 'protech-wholesale' ) . '</p></div>';
		}

		$settings = self::get_all_tier_settings();
		$labels   = self::get_tier_labels();

		echo '<p>' . esc_html__( 'Customer tiers are internal only: a wholesale customer never sees their tier, or that tiers exist. Every approved customer starts on Bronze. Move a customer to another tier from the Customers tab or their user profile. The discount comes off whichever quantity price (Standard, Volume or Bulk) their cart has reached.', 'protech-wholesale' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_save_tiers', 'protech_wholesale_tiers_nonce' );

		echo '<table class="widefat striped" style="max-width:640px;"><thead><tr>';
		foreach (
			array(
				__( 'Tier', 'protech-wholesale' ),
				__( 'Discount off the wholesale price', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		echo '<tr>';
		echo '<td><strong>' . esc_html( $labels[ self::BRONZE ] ) . '</strong></td>';
		echo '<td>' . esc_html__( 'None. Each product\'s wholesale price as set.', 'protech-wholesale' ) . '</td>';
		echo '</tr>';

		foreach ( self::OVERRIDABLE_TIERS as $tier ) {
			$discount = $settings[ $tier ]['discount_percent'] ?? '';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $labels[ $tier ] ) . '</strong></td>';
			echo '<td><input type="number" step="0.01" min="0" max="100" style="width:90px;" name="protech_tier[' . esc_attr( $tier ) . '][discount_percent]" value="' . esc_attr( (string) $discount ) . '" placeholder="0" /> %</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	private static function save_tiers_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by render_tiers_tab() before calling this.
		$posted = isset( $_POST['protech_tier'] ) && is_array( $_POST['protech_tier'] ) ? wp_unslash( $_POST['protech_tier'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		$clean  = array();

		foreach ( self::OVERRIDABLE_TIERS as $tier ) {
			$discount = isset( $posted[ $tier ]['discount_percent'] ) ? sanitize_text_field( (string) $posted[ $tier ]['discount_percent'] ) : '';

			$clean[ $tier ] = array(
				'discount_percent' => is_numeric( $discount ) ? (string) min( 100, max( 0, round( (float) $discount, 2 ) ) ) : '',
			);
		}

		update_option( self::OPT_TIER_SETTINGS, $clean );
		Logger::info( 'Wholesale tier settings updated by user #' . get_current_user_id() );
	}
}
