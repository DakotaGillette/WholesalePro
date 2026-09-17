<?php
/**
 * Central bootstrap: instantiates every feature class and wires its hooks.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Roles $roles;
	private Settings $settings;
	private ApplicationForm $application_form;
	private Approval $approval;
	private ProductFields $product_fields;
	private Pricing $pricing;
	private CaseRules $case_rules;
	private OrderForm $order_form;
	private Reorder $reorder;
	private MyAccount $my_account;
	private Portal $portal;
	private OrdersAdmin $orders_admin;
	private Emails $emails;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Instantiate feature classes and register their hooks.
	 * Runs on plugins_loaded, after WooCommerce is confirmed active.
	 */
	public function init(): void {
		load_plugin_textdomain( 'protech-wholesale', false, dirname( PROTECH_WHOLESALE_BASENAME ) . '/languages' );

		$this->roles             = new Roles();
		$this->settings          = new Settings();
		$this->application_form  = new ApplicationForm();
		$this->approval          = new Approval();
		$this->product_fields    = new ProductFields();
		$this->pricing           = new Pricing();
		$this->case_rules        = new CaseRules();
		$this->order_form        = new OrderForm();
		$this->reorder           = new Reorder();
		$this->my_account        = new MyAccount();
		$this->portal            = new Portal();
		$this->orders_admin      = new OrdersAdmin();
		$this->emails            = new Emails();

		foreach (
			array(
				$this->roles,
				$this->settings,
				$this->application_form,
				$this->approval,
				$this->product_fields,
				$this->pricing,
				$this->case_rules,
				$this->order_form,
				$this->reorder,
				$this->my_account,
				$this->portal,
				$this->orders_admin,
				$this->emails,
			) as $component
		) {
			$component->register_hooks();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function case_rules(): CaseRules {
		return $this->case_rules;
	}

	public function pricing(): Pricing {
		return $this->pricing;
	}

	/**
	 * Only enqueue front-end assets on pages that actually use them.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'protech_wholesale_customer' ) ) {
			return;
		}

		$needs_assets = is_page() && (
			has_shortcode( get_post()->post_content ?? '', 'protech_wholesale_order_form' ) ||
			has_shortcode( get_post()->post_content ?? '', 'protech_wholesale_portal' )
		);

		$needs_assets = $needs_assets || ( function_exists( 'is_account_page' ) && is_account_page() );

		if ( ! $needs_assets ) {
			return;
		}

		wp_enqueue_style(
			'protech-wholesale',
			PROTECH_WHOLESALE_URL . 'assets/css/wholesale.css',
			array(),
			PROTECH_WHOLESALE_VERSION
		);

		wp_enqueue_script(
			'protech-wholesale-order-form',
			PROTECH_WHOLESALE_URL . 'assets/js/order-form.js',
			array(),
			PROTECH_WHOLESALE_VERSION,
			true
		);

		wp_localize_script(
			'protech-wholesale-order-form',
			'ProtechWholesale',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'protech_wholesale_order_form' ),
				'cartUrl' => wc_get_cart_url(),
				'i18n'    => array(
					'outOfStock' => __( 'Out of stock', 'protech-wholesale' ),
					'added'      => __( 'Added to cart', 'protech-wholesale' ),
					'error'      => __( 'Something went wrong. Please try again.', 'protech-wholesale' ),
				),
			)
		);
	}

	/**
	 * Only enqueue admin assets on the plugin's own screens.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$screen = get_current_screen();

		$is_product_screen = $screen && in_array( $screen->id, array( 'product', 'edit-product' ), true );
		$is_wholesale_screen = $screen && str_contains( (string) $screen->id, 'wholesale' );
		$is_user_screen     = in_array( $hook, array( 'profile.php', 'user-edit.php' ), true );

		if ( ! $is_product_screen && ! $is_wholesale_screen && ! $is_user_screen ) {
			return;
		}

		wp_enqueue_script(
			'protech-wholesale-admin',
			PROTECH_WHOLESALE_URL . 'assets/js/admin.js',
			array(),
			PROTECH_WHOLESALE_VERSION,
			true
		);
	}
}
