<?php
/**
 * A WooCommerce shipping method, invisible to retail customers entirely,
 * that charges a flat rate below the store's Volume threshold (see
 * class-volume-pricing.php / Settings::OPT_VOLUME_THRESHOLD_DISPLAYS)
 * and is free at/above it.
 *
 * The store owner adds this once to each shipping zone under WooCommerce
 * → Settings → Shipping — same as adding any other shipping method. It
 * doesn't create a zone or configure itself; see DECISIONS.md.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WholesaleShippingMethod
 */
class WholesaleShippingMethod extends \WC_Shipping_Method {

	public const METHOD_ID = 'protech_wholesale_shipping';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Protech Wholesale Shipping', 'protech-wholesale' );
		$this->method_description = __( 'Free once a wholesale customer\'s cart reaches the Volume threshold (WooCommerce → Wholesale → Pricing); a flat rate below it. Only ever shown to approved wholesale customers — never to retail.', 'protech-wholesale' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', __( 'Wholesale Shipping', 'protech-wholesale' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Method title', 'protech-wholesale' ),
				'type'        => 'text',
				'description' => __( 'Shown to wholesale customers at checkout.', 'protech-wholesale' ),
				'default'     => __( 'Wholesale Shipping', 'protech-wholesale' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * @param array $package
	 */
	public function is_available( $package ): bool {
		if ( ! Roles::is_wholesale_customer() ) {
			return false;
		}

		// A wholesale customer whose cart holds only retail-priced items
		// (nothing wholesale-eligible) is shipping a retail order: leave
		// the zone's normal methods in place for it.
		$totals = VolumePricing::get_totals_for_items( $package['contents'] ?? array(), get_current_user_id() );

		if ( $totals['displays'] <= 0 ) {
			return false;
		}

		return parent::is_available( $package );
	}

	/**
	 * @param array $package
	 */
	public function calculate_shipping( $package = array() ): void {
		$user_id = get_current_user_id();
		$totals  = VolumePricing::get_totals_for_items( $package['contents'] ?? array(), $user_id );
		$is_free = $totals['displays'] >= Settings::get_volume_threshold_displays();

		$this->add_rate(
			array(
				'id'    => $this->get_rate_id(),
				'label' => $this->title,
				'cost'  => $is_free ? 0 : Settings::get_shipping_flat_rate(),
			)
		);
	}
}
