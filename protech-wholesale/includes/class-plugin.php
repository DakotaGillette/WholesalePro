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
	private Tiers $tiers;
	private VolumePricing $volume_pricing;
	private ApplicationForm $application_form;
	private Approval $approval;
	private ProductFields $product_fields;
	private Pricing $pricing;
	private CaseRules $case_rules;
	private Reorder $reorder;
	private MyAccount $my_account;
	private Portal $portal;
	private OrdersAdmin $orders_admin;
	private Emails $emails;
	private GlobalTierBar $global_tier_bar;

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
		$this->tiers             = new Tiers();
		$this->volume_pricing    = new VolumePricing();
		$this->application_form  = new ApplicationForm();
		$this->approval          = new Approval();
		$this->product_fields    = new ProductFields();
		$this->pricing           = new Pricing();
		$this->case_rules        = new CaseRules();
		$this->reorder           = new Reorder();
		$this->my_account        = new MyAccount();
		$this->portal            = new Portal();
		$this->orders_admin      = new OrdersAdmin();
		$this->emails            = new Emails();
		$this->global_tier_bar   = new GlobalTierBar();

		foreach (
			array(
				$this->roles,
				$this->settings,
				$this->tiers,
				$this->volume_pricing,
				$this->application_form,
				$this->approval,
				$this->product_fields,
				$this->pricing,
				$this->case_rules,
				$this->reorder,
				$this->my_account,
				$this->portal,
				$this->orders_admin,
				$this->emails,
				$this->global_tier_bar,
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
	 * The plugin's own version constant never changes between ordinary
	 * deploys, which made every enqueued script/style URL byte-identical
	 * across every redeploy this session (?ver=1.0.0, always) — browsers
	 * (and Breeze, this site's caching plugin) had no reason to ever
	 * re-fetch an updated file, so a real fix could ship server-side and
	 * still appear to do nothing for a visitor with an already-cached
	 * copy. Using each file's own last-modified time as its cache-buster
	 * instead means a fresh redeploy always produces a new URL
	 * automatically, with no separate step to remember.
	 */
	private function asset_version( string $relative_path ): string {
		$path = PROTECH_WHOLESALE_DIR . $relative_path;

		return is_readable( $path ) ? (string) filemtime( $path ) : PROTECH_WHOLESALE_VERSION;
	}

	/**
	 * Only enqueue front-end assets on pages that actually use them.
	 */
	public function enqueue_frontend_assets(): void {
		$post_content = is_singular() ? (string) ( get_post()->post_content ?? '' ) : '';

		$has_portal_shortcode = has_shortcode( $post_content, 'protech_wholesale_portal' );
		$is_wholesale         = Roles::is_wholesale_customer();

		// The portal's logged-out login form and pending/retail-only
		// notices need the stylesheet on that specific page regardless of
		// wholesale status; a wholesale customer needs it EVERYWHERE (the
		// "Wholesale price" label and the sticky global tier bar both
		// render on ordinary shop/product pages, not just the portal).
		if ( ! $has_portal_shortcode && ! $is_wholesale ) {
			return;
		}

		wp_enqueue_style(
			'protech-wholesale',
			PROTECH_WHOLESALE_URL . 'assets/css/wholesale.css',
			array(),
			$this->asset_version( 'assets/css/wholesale.css' )
		);

		if ( GlobalTierBar::should_render() ) {
			wp_enqueue_script(
				'protech-wholesale-global-tier-bar',
				PROTECH_WHOLESALE_URL . 'assets/js/global-tier-bar.js',
				array( 'jquery' ),
				$this->asset_version( 'assets/js/global-tier-bar.js' ),
				true
			);

			wp_localize_script(
				'protech-wholesale-global-tier-bar',
				'ProtechGlobalTierBar',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( GlobalTierBar::AJAX_NONCE_ACTION ),
				)
			);
		}

		if ( $is_wholesale && is_product() ) {
			wp_enqueue_script(
				'protech-wholesale-unit-selector',
				PROTECH_WHOLESALE_URL . 'assets/js/unit-selector.js',
				array( 'jquery', 'wc-add-to-cart-variation' ),
				$this->asset_version( 'assets/js/unit-selector.js' ),
				true
			);
		}
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
			$this->asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'protech-wholesale-admin',
			'protechWholesaleAdmin',
			array(
				'bulkPricePrompt' => __( 'Set wholesale price for all variations ($ per pack):', 'protech-wholesale' ),
			)
		);
	}
}
