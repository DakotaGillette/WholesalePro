<?php
/**
 * Internal wholesale tiers (Bronze/Silver/Gold/Platinum) — a hidden
 * classification layered on top of the wholesale_customer role. Tiers
 * are never shown to the customer anywhere (Quick Order, portal,
 * emails); they exist purely so the store owner can set a minimum order
 * and a price discount per tier without touching every customer
 * individually. A per-customer override (Approval::META_PRICE_OVERRIDES
 * / META_MIN_ORDER) still always wins over the customer's tier — tiers
 * sit between the global default and that per-customer layer, not above
 * it.
 *
 * Bronze is deliberately not a row in the tier settings table: it IS
 * the existing global default (Settings::get_min_order(), the group
 * wholesale price with no discount), so "assign everyone to Bronze by
 * default" falls out for free rather than needing its own duplicate
 * set of fields.
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
	 * @return array<string, array{min_order: string, discount_percent: string}>
	 */
	private static function get_all_tier_settings(): array {
		$settings = get_option( self::OPT_TIER_SETTINGS, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Null means "no tier override — fall through to the global minimum."
	 */
	public static function get_tier_min_order( string $tier ): ?float {
		if ( self::BRONZE === $tier ) {
			return null;
		}

		$value = self::get_all_tier_settings()[ $tier ]['min_order'] ?? '';

		return ( '' !== $value && is_numeric( $value ) ) ? (float) $value : null;
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

		echo '<p>' . esc_html__( 'Tiers are an internal classification only — wholesale customers never see their tier, or that tiers exist at all. Assign a customer\'s tier from their user profile (under the "Protech Wholesale" section).', 'protech-wholesale' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_save_tiers', 'protech_wholesale_tiers_nonce' );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Tier', 'protech-wholesale' ), __( 'Minimum order override', 'protech-wholesale' ), __( 'Discount off wholesale price', 'protech-wholesale' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		echo '<tr>';
		echo '<td><strong>' . esc_html( $labels[ self::BRONZE ] ) . '</strong></td>';
		echo '<td>' . esc_html__( 'Uses the global minimum order setting.', 'protech-wholesale' ) . '</td>';
		echo '<td>' . esc_html__( 'No discount — each product\'s wholesale price as set.', 'protech-wholesale' ) . '</td>';
		echo '</tr>';

		foreach ( self::OVERRIDABLE_TIERS as $tier ) {
			$min_order = $settings[ $tier ]['min_order'] ?? '';
			$discount  = $settings[ $tier ]['discount_percent'] ?? '';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $labels[ $tier ] ) . '</strong></td>';
			echo '<td><input type="number" step="0.01" min="0" name="protech_tier[' . esc_attr( $tier ) . '][min_order]" value="' . esc_attr( (string) $min_order ) . '" placeholder="' . esc_attr( (string) Settings::get_min_order() ) . '" /></td>';
			echo '<td><input type="number" step="0.01" min="0" max="100" name="protech_tier[' . esc_attr( $tier ) . '][discount_percent]" value="' . esc_attr( (string) $discount ) . '" placeholder="0" />%</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	private static function save_tiers_tab(): void {
		$posted = $_POST['protech_tier'] ?? array();
		$clean  = array();

		foreach ( self::OVERRIDABLE_TIERS as $tier ) {
			$min      = isset( $posted[ $tier ]['min_order'] ) ? sanitize_text_field( wp_unslash( (string) $posted[ $tier ]['min_order'] ) ) : '';
			$discount = isset( $posted[ $tier ]['discount_percent'] ) ? sanitize_text_field( wp_unslash( (string) $posted[ $tier ]['discount_percent'] ) ) : '';

			$clean[ $tier ] = array(
				'min_order'        => is_numeric( $min ) ? (string) round( (float) $min, 2 ) : '',
				'discount_percent' => is_numeric( $discount ) ? (string) min( 100, max( 0, round( (float) $discount, 2 ) ) ) : '',
			);
		}

		update_option( self::OPT_TIER_SETTINGS, $clean );
		Logger::info( 'Wholesale tier settings updated by user #' . get_current_user_id() );
	}
}
