<?php
/**
 * The visible Standard/Volume/Bulk price table on a single product page
 * for wholesale customers — the same ladder the sticky tier bar tracks
 * progress along, but with the actual per-pack prices this customer
 * pays at each tier for THIS product (per-customer overrides and their
 * hidden tier discount already applied), so the incentive to reach the
 * next tier is spelled out rather than implied by two marker dots.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TierLadder
 */
class TierLadder {

	public function register_hooks(): void {
		// Between the short description (20) and the add-to-cart form (30).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_on_product_page' ), 25 );
	}

	public function render_on_product_page(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! Roles::is_wholesale_customer() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! Pricing::is_available_at_wholesale_including_variations( $product->get_id(), $user_id ) ) {
			return;
		}

		$rows = self::get_rows( $product, $user_id );

		if ( empty( $rows ) ) {
			return;
		}

		wc_get_template(
			'tier-ladder.php',
			array(
				'rows'        => $rows,
				'active_tier' => Pricing::get_current_tier( $user_id ),
				'footnote'    => __( 'Tiers are based on the combined Display/Case quantity across your whole cart, every product and colour together — not per item.', 'protech-wholesale' ),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
	}

	/**
	 * One row per quantity tier. For a variable product the price is the
	 * range across its wholesale-priced variations (usually a single value,
	 * since every colour is normally priced the same).
	 *
	 * @return array<int, array{tier: string, label: string, threshold: string, note: string, price_html: string, min: float, max: float}>
	 */
	public static function get_rows( \WC_Product $product, int $user_id ): array {
		$ids = self::priced_ids( $product, $user_id );

		if ( empty( $ids ) ) {
			return array();
		}

		$displays_per_case = max( 1, VolumePricing::get_displays_per_case( $product->get_id() ) );
		$volume_threshold  = Settings::get_volume_threshold_displays();
		$bulk_threshold    = Settings::get_bulk_threshold_cases();

		$definitions = array(
			VolumePricing::TIER_STANDARD => array(
				'label'     => __( 'Standard', 'protech-wholesale' ),
				'threshold' => __( 'Any quantity', 'protech-wholesale' ),
				'note'      => '',
			),
			VolumePricing::TIER_VOLUME   => array(
				'label'     => __( 'Volume', 'protech-wholesale' ),
				/* translators: %d: number of displays. */
				'threshold' => sprintf( _n( '%d+ display', '%d+ displays', $volume_threshold, 'protech-wholesale' ), $volume_threshold ),
				'note'      => __( 'Free shipping', 'protech-wholesale' ),
			),
			VolumePricing::TIER_BULK     => array(
				'label'     => __( 'Bulk', 'protech-wholesale' ),
				/* translators: 1: number of cases, 2: the same quantity in displays. */
				'threshold' => sprintf( __( '%1$d+ cases (%2$d displays)', 'protech-wholesale' ), $bulk_threshold, $bulk_threshold * $displays_per_case ),
				'note'      => __( 'Best price', 'protech-wholesale' ),
			),
		);

		$rows = array();

		foreach ( $definitions as $tier => $definition ) {
			$prices = array();

			foreach ( $ids as $id ) {
				$price = Pricing::get_wholesale_price( $id, $user_id, $tier );

				if ( null !== $price ) {
					$prices[] = $price;
				}
			}

			if ( empty( $prices ) ) {
				continue;
			}

			$min = min( $prices );
			$max = max( $prices );

			$rows[] = array(
				'tier'       => $tier,
				'label'      => $definition['label'],
				'threshold'  => $definition['threshold'],
				'note'       => $definition['note'],
				'price_html' => $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max ),
				'min'        => $min,
				'max'        => $max,
			);
		}

		return $rows;
	}

	/**
	 * @return int[] The concrete product/variation IDs that carry a wholesale price for this user.
	 */
	private static function priced_ids( \WC_Product $product, int $user_id ): array {
		if ( $product->is_type( 'variable' ) ) {
			$ids = array();

			foreach ( $product->get_children() as $variation_id ) {
				if ( Pricing::is_available_at_wholesale( (int) $variation_id, $user_id ) ) {
					$ids[] = (int) $variation_id;
				}
			}

			return $ids;
		}

		return Pricing::is_available_at_wholesale( $product->get_id(), $user_id ) ? array( $product->get_id() ) : array();
	}
}
