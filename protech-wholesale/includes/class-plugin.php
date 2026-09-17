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

	/**
	 * Bump when a one-off data migration must run on the next load — see
	 * maybe_upgrade() for what each version does.
	 */
	public const DB_VERSION     = '2';
	public const OPT_DB_VERSION = 'protech_wholesale_db_version';

	private static ?Plugin $instance = null;

	private Roles $roles;
	private Settings $settings;
	private Tiers $tiers;
	private VolumePricing $volume_pricing;
	private ApplicationForm $application_form;
	private Approval $approval;
	private ProductFields $product_fields;
	private Pricing $pricing;
	private CatalogQuery $catalog_query;
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
		$this->catalog_query     = new CatalogQuery();
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
				$this->catalog_query,
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

		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
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
	 * One-off data migrations, keyed by DB_VERSION.
	 *
	 *  2: backfill the parent-level _protech_has_wholesale_price flag that
	 *     CatalogQuery filters on — without it every product that hadn't
	 *     been re-saved since the flag was introduced would be hidden
	 *     from wholesale customers.
	 */
	public function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::OPT_DB_VERSION ) ) {
			return;
		}

		// Cheap lock so two overlapping requests don't both run it.
		if ( get_transient( 'protech_wholesale_upgrading' ) ) {
			return;
		}

		set_transient( 'protech_wholesale_upgrading', 1, 5 * MINUTE_IN_SECONDS );

		$count = ProductFields::backfill_has_wholesale_price_flags();

		update_option( self::OPT_DB_VERSION, self::DB_VERSION );
		delete_transient( 'protech_wholesale_upgrading' );

		Logger::info( sprintf( 'Upgraded plugin data to version %s (%d products flagged).', self::DB_VERSION, $count ) );
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
			$product      = wc_get_product( get_the_ID() );
			$dependencies = array( 'jquery' );

			// Only a variable product's page carries WooCommerce's
			// variation script (whose found_variation event we listen to).
			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				$dependencies[] = 'wc-add-to-cart-variation';
			}

			wp_enqueue_script(
				'protech-wholesale-unit-selector',
				PROTECH_WHOLESALE_URL . 'assets/js/unit-selector.js',
				$dependencies,
				$this->asset_version( 'assets/js/unit-selector.js' ),
				true
			);

			wp_localize_script(
				'protech-wholesale-unit-selector',
				'ProtechUnitSelector',
				array(
					// Real REST root (subdirectory installs, plain permalinks)
					// and a Store API nonce, so the add-to-cart call needs no
					// extra round trip and no hardcoded /wp-json/ path.
					'restRoot' => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
					'nonce'    => wp_create_nonce( 'wc_store_api' ),
					'i18n'     => array(
						/* translators: %d: packs per display. */
						'display'    => __( 'Display (%d packs)', 'protech-wholesale' ),
						/* translators: %d: packs per case. */
						'case'       => __( 'Case (%d packs)', 'protech-wholesale' ),
						/* translators: %d: number of packs. */
						'packTotal'  => __( '%d pack total', 'protech-wholesale' ),
						/* translators: %d: number of packs. */
						'packsTotal' => __( '%d packs total', 'protech-wholesale' ),
						'addFailed'  => __( 'Could not add this to your cart.', 'protech-wholesale' ),
					),
				)
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
				/* translators: %s: currency symbol. */
				'bulkPricePrompt'  => sprintf( __( 'Set the wholesale (Standard) price for all variations (%s per pack):', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %s: currency symbol. */
				'bulkVolumePrompt' => sprintf( __( 'Set the Volume price override for all variations (%s per pack):', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %s: currency symbol. */
				'bulkBulkPrompt'   => sprintf( __( 'Set the Bulk price override for all variations (%s per pack):', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'approveConfirm'   => __( 'Approve this application? The applicant is emailed a password link and sees wholesale pricing immediately.', 'protech-wholesale' ),
				'rejectPrompt'     => __( 'Reject this application? Enter an optional reason to include in the email to the applicant, or leave blank:', 'protech-wholesale' ),
			)
		);
	}
}
