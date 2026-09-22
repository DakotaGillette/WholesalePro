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
	public const DB_VERSION     = '9';
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
	private TierLadder $tier_ladder;
	private StarterKit $starter_kit;
	private Updater $updater;
	private HeaderNotice $header_notice;
	private MessagingSettings $messaging_settings;
	private Automations $automations;
	private AutomationRunner $automation_runner;
	private SmsConsent $sms_consent;
	private Unsubscribe $unsubscribe;
	private NotificationsEndpoint $notifications_endpoint;
	private MessagingTab $messaging_tab;
	private AutomationsScreen $automations_screen;
	private ComposeScreen $compose_screen;
	private LogScreen $log_screen;
	private ComplianceScreen $compliance_screen;
	private MessagingSettingsScreen $messaging_settings_screen;
	private CustomersTab $customers_tab;
	private WelcomeEmail $welcome_email;
	private EmailComposer $email_composer;
	private EmailsScreen $emails_screen;
	private WcEmailSlots $wc_email_slots;
	private Contacts $contacts;
	private ContactsScreen $contacts_screen;
	private RestApi $rest_api;

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
		$this->tier_ladder       = new TierLadder();
		$this->starter_kit       = new StarterKit();
		$this->updater                = new Updater();
		$this->header_notice          = new HeaderNotice();
		$this->messaging_settings     = new MessagingSettings();
		$this->automations            = new Automations();
		$this->automation_runner      = new AutomationRunner();
		$this->sms_consent            = new SmsConsent();
		$this->unsubscribe            = new Unsubscribe();
		$this->notifications_endpoint = new NotificationsEndpoint();
		$this->messaging_tab          = new MessagingTab();
		$this->automations_screen     = new AutomationsScreen();
		$this->compose_screen         = new ComposeScreen();
		$this->log_screen             = new LogScreen();
		$this->compliance_screen      = new ComplianceScreen();
		$this->messaging_settings_screen = new MessagingSettingsScreen();
		$this->customers_tab          = new CustomersTab();
		$this->welcome_email          = new WelcomeEmail();
		$this->email_composer         = new EmailComposer();
		$this->emails_screen          = new EmailsScreen();
		$this->wc_email_slots         = new WcEmailSlots();
		$this->contacts               = new Contacts();
		$this->contacts_screen        = new ContactsScreen();
		$this->rest_api               = new RestApi();

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
				$this->tier_ladder,
				$this->starter_kit,
				$this->updater,
				$this->header_notice,
				$this->messaging_settings,
				$this->automations,
				$this->automation_runner,
				$this->sms_consent,
				$this->unsubscribe,
				$this->notifications_endpoint,
				$this->messaging_tab,
				$this->automations_screen,
				$this->compose_screen,
				$this->log_screen,
				$this->compliance_screen,
				$this->messaging_settings_screen,
				$this->customers_tab,
				$this->welcome_email,
				$this->email_composer,
				$this->emails_screen,
				$this->wc_email_slots,
				$this->contacts,
				$this->contacts_screen,
				$this->rest_api,
			) as $component
		) {
			$component->register_hooks();
		}

		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp', array( $this, 'move_short_description_below_add_to_cart' ) );
		add_filter( 'plugin_action_links_' . PROTECH_WHOLESALE_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * "Applicants" and "Settings" on the plugin's row in the Plugins list,
	 * so the screen is one click away from where plugins get looked at.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function plugin_action_links( array $links ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $links;
		}

		array_unshift(
			$links,
			'<a href="' . esc_url( Approval::tab_url( 'applicants' ) ) . '">' . esc_html__( 'Applicants', 'protech-wholesale' ) . '</a>',
			'<a href="' . esc_url( Approval::tab_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'protech-wholesale' ) . '</a>'
		);

		return $links;
	}

	/**
	 * `protech-wholesale` on <body> for an approved wholesale customer:
	 * wholesale.css scopes its overrides of the THEME's own elements (the
	 * header cart badge) to it, so nothing changes for a retail visitor.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public function add_body_class( array $classes ): array {
		if ( Roles::is_wholesale_customer() ) {
			$classes[] = 'protech-wholesale';
		}

		return $classes;
	}

	/**
	 * On a product page, a wholesale customer gets the short description
	 * (size, count, finish) below the add-to-cart button instead of above the
	 * pricing: their page leads with how wholesale pricing and ordering work,
	 * and the buying controls should not be pushed down by retail copy they
	 * have read before. WooCommerce prints it at 20 and the add-to-cart form
	 * at 30; this moves it to 35, ahead of the category/brand line (40).
	 * Everyone else keeps the standard order.
	 */
	public function move_short_description_below_add_to_cart(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! Roles::is_wholesale_customer() ) {
			return;
		}

		// Whatever priority the theme left it at: moving it must never duplicate it.
		$priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' );

		if ( false !== $priority ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', (int) $priority );
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 35 );
		}
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
	 * One-off data migrations, keyed by DB_VERSION. Each step only runs
	 * once, in order — an update from version 1 straight to 3 runs both
	 * the version-2 and version-3 steps in the same request.
	 *
	 *  2: backfill the parent-level _protech_has_wholesale_price flag that
	 *     CatalogQuery filters on — without it every product that hadn't
	 *     been re-saved since the flag was introduced would be hidden
	 *     from wholesale customers.
	 *  3: create the message log table (Messaging & automations, 1.5.0),
	 *     backfill an approval date for every existing wholesale
	 *     customer (Automations::backfill_approved_at()), and flush
	 *     rewrite rules for the new My Account "Notifications" endpoint —
	 *     none of which activation would otherwise do, since this plugin
	 *     updates itself from GitHub releases rather than being
	 *     reinstalled (see class-updater.php).
	 *  4: seed the starter email templates (2.2.0), only into an empty library.
	 *  5: add the approved and rejected application starters (2.6.0).
	 *  6: create the four standard automations, switched off (2.7.0).
	 *  7: nothing new of its own. Its only job is to make every site still on
	 *     an older version run the table safety-net below one more time.
	 *  9: create the contacts and contact-consent-log tables (3.4.0), and
	 *     backfill a contact for every existing user with a customer-ish role.
	 */
	public function maybe_upgrade(): void {
		$current = get_option( self::OPT_DB_VERSION, '0' );

		if ( self::DB_VERSION === $current ) {
			return;
		}

		// Cheap lock so two overlapping requests don't both run it.
		if ( get_transient( 'protech_wholesale_upgrading' ) ) {
			return;
		}

		set_transient( 'protech_wholesale_upgrading', 1, 5 * MINUTE_IN_SECONDS );

		// A safety net, not a numbered step: the version-3 step below only ever
		// runs once, so a table dropped after that (or lost to a bad manual
		// migration) would otherwise stay missing forever. Re-running dbDelta()
		// is a no-op when the table already matches, so this is free on every
		// ordinary upgrade and a real fix on the rare one where it is not.
		MessageLog::install_table();

		if ( version_compare( $current, '2', '<' ) ) {
			$count = ProductFields::backfill_has_wholesale_price_flags();
			Logger::info( sprintf( 'Upgraded plugin data to version 2 (%d products flagged).', $count ) );
		}

		if ( version_compare( $current, '3', '<' ) ) {
			MessageLog::install_table();
			$backfilled = Automations::backfill_approved_at();
			flush_rewrite_rules();
			Logger::info( sprintf( 'Upgraded plugin data to version 3 (message log created, %d customer(s) backfilled with an approval date).', $backfilled ) );
		}

		if ( version_compare( $current, '4', '<' ) ) {
			$seeded = EmailTemplates::seed_starters();
			Logger::info( sprintf( 'Upgraded plugin data to version 4 (%d starter email template(s) created).', $seeded ) );
		}

		if ( version_compare( $current, '5', '<' ) ) {
			// The approved and rejected application starters. A fresh library was just seeded with every starter, and
			// seeding is additive by key, so this only adds what an existing library is missing.
			$added = EmailTemplates::seed_starters( array( 'application_approved', 'application_rejected' ) );
			Logger::info( sprintf( 'Upgraded plugin data to version 5 (%d starter email template(s) added).', $added ) );
		}

		if ( version_compare( $current, '6', '<' ) ) {
			// The four standard automations, ready to read and switch on: only into a store with no rules of its own.
			$standard = Automations::seed_standard();
			Logger::info( sprintf( 'Upgraded plugin data to version 6 (%d standard automation(s) created, all switched off).', $standard ) );
		}

		if ( version_compare( $current, '8', '<' ) ) {
			// 3.1.0's four new starters: thank-you, sale, back-in-stock, newsletter update.
			$added = EmailTemplates::seed_starters( array( 'thank_you_order', 'sale_announcement', 'back_in_stock', 'newsletter_update' ) );
			Logger::info( sprintf( 'Upgraded plugin data to version 8 (%d starter email template(s) added).', $added ) );
		}

		if ( version_compare( $current, '9', '<' ) ) {
			Contacts::install_tables();
			$backfilled = Contacts::backfill();
			Logger::info( sprintf( 'Upgraded plugin data to version 9 (contacts tables created, %d contact(s) backfilled).', $backfilled ) );
		}

		update_option( self::OPT_DB_VERSION, self::DB_VERSION );
		delete_transient( 'protech_wholesale_upgrading' );
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
		// render on ordinary shop/product pages, not just the portal). A
		// pending applicant gets it on My Account only, for the "under
		// review" strip MyAccount::render_account_header() shows there.
		$is_pending_on_account = Roles::is_wholesale_pending() && function_exists( 'is_account_page' ) && is_account_page();

		if ( ! $has_portal_shortcode && ! $is_wholesale && ! $is_pending_on_account ) {
			return;
		}

		wp_enqueue_style(
			'protech-wholesale',
			PROTECH_WHOLESALE_URL . 'assets/css/wholesale.css',
			array(),
			$this->asset_version( 'assets/css/wholesale.css' )
		);

		// The portal's login form (show/hide password) — logged-out only.
		if ( $has_portal_shortcode && ! is_user_logged_in() ) {
			wp_enqueue_script(
				'protech-wholesale-portal',
				PROTECH_WHOLESALE_URL . 'assets/js/portal.js',
				array(),
				$this->asset_version( 'assets/js/portal.js' ),
				true
			);
		}

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
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( GlobalTierBar::AJAX_NONCE_ACTION ),
					/**
					 * CSS selector for the site header the sticky bar matches its
					 * width to. A header that is itself a card narrower than the
					 * viewport (Salient's #header-outer here) is matched edge for
					 * edge; a full-bleed header is matched on the content box of
					 * the .container inside it. Return '' to keep the stylesheet's
					 * own width. Keep in step with DEFAULT_ALIGN_TO in
					 * assets/js/global-tier-bar.js.
					 *
					 * @param string $selector
					 */
					'alignTo'     => (string) apply_filters( 'protech_wholesale_tier_bar_align_selector', '#header-outer, .site-header, #masthead, header[role="banner"], body > header' ),
					/**
					 * Background color of the space reserved for the sticky bar
					 * at the very end of the page. '' (the default) samples it
					 * from the page itself, so it matches the footer above it;
					 * set a CSS color only if that guess is ever wrong.
					 *
					 * @param string $color
					 */
					'spacerColor' => (string) apply_filters( 'protech_wholesale_tier_bar_spacer_color', '' ),
				)
			);
		}

		if ( StarterKit::is_kit_page() ) {
			wp_enqueue_script(
				'protech-wholesale-starter-kit',
				PROTECH_WHOLESALE_URL . 'assets/js/starter-kit.js',
				array( 'jquery' ),
				$this->asset_version( 'assets/js/starter-kit.js' ),
				true
			);

			wp_localize_script(
				'protech-wholesale-starter-kit',
				'ProtechStarterKit',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( StarterKit::NONCE_ACTION ),
					'i18n'    => array(
						'kitOne'    => __( 'kit', 'protech-wholesale' ),
						'kitMany'   => __( 'kits', 'protech-wholesale' ),
						'addOne'    => __( 'Add starter kit to cart', 'protech-wholesale' ),
						/* translators: %d: number of starter kits. */
						'addMany'   => __( 'Add %d starter kits to cart', 'protech-wholesale' ),
						'addFailed' => __( 'Could not add the starter kit to your cart.', 'protech-wholesale' ),
					),
				)
			);
		}

		// "Add one display of every color" under a variable product's form.
		if ( $is_wholesale && is_product() && StarterKit::is_every_color_source( (int) get_the_ID() ) ) {
			wp_enqueue_script(
				'protech-wholesale-every-color',
				PROTECH_WHOLESALE_URL . 'assets/js/every-color.js',
				array( 'jquery' ),
				$this->asset_version( 'assets/js/every-color.js' ),
				true
			);

			wp_localize_script(
				'protech-wholesale-every-color',
				'ProtechEveryColor',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( StarterKit::NONCE_ACTION ),
					'i18n'    => array(
						'addFailed' => __( 'Could not add these to your cart.', 'protech-wholesale' ),
					),
				)
			);
		}

		// A kit page has no add-to-cart form for the unit selector to drive.
		if ( $is_wholesale && is_product() && ! StarterKit::is_kit_page() ) {
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
					// Singular and plural are separate strings on purpose: the
					// script picks between them itself, per quantity, with no
					// wp.i18n dependency.
					'i18n'     => array(
						/* translators: %d: packs per display. */
						'display'         => __( 'Display (%d packs)', 'protech-wholesale' ),
						/* translators: %d: packs per case. */
						'case'            => __( 'Case (%d packs)', 'protech-wholesale' ),
						/* translators: 1: displays per case, 2: packs per case. */
						'caseMeta'        => __( '%1$d displays · %2$d packs', 'protech-wholesale' ),
						/* translators: %d: number of displays, always 1. */
						'displayOne'      => __( '%d display', 'protech-wholesale' ),
						/* translators: %d: number of displays. */
						'displayMany'     => __( '%d displays', 'protech-wholesale' ),
						/* translators: %d: number of cases, always 1. */
						'caseOne'         => __( '%d case', 'protech-wholesale' ),
						/* translators: %d: number of cases. */
						'caseMany'        => __( '%d cases', 'protech-wholesale' ),
						/* translators: %d: number of packs, always 1. */
						'packOne'         => __( '%d pack', 'protech-wholesale' ),
						/* translators: %d: number of packs. */
						'packMany'        => __( '%d packs', 'protech-wholesale' ),
						'wordDisplayOne'  => __( 'display', 'protech-wholesale' ),
						'wordDisplayMany' => __( 'displays', 'protech-wholesale' ),
						'wordCaseOne'     => __( 'case', 'protech-wholesale' ),
						'wordCaseMany'    => __( 'cases', 'protech-wholesale' ),
						/* translators: %s: what is being added, e.g. "3 displays". */
						'addButton'       => __( 'Add %s to cart', 'protech-wholesale' ),
						/* translators: 1: what was added, e.g. "3 displays"; 2: the same in packs, e.g. "30 packs". */
						'added'           => __( 'Added %1$s (%2$s) to your cart.', 'protech-wholesale' ),
						'addFailed'       => __( 'Could not add this to your cart.', 'protech-wholesale' ),
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
		// The wholesale screens and the top-level Messaging section (whose
		// page ids contain `protech-messaging`) share one set of admin assets.
		$is_wholesale_screen = $screen && ( str_contains( (string) $screen->id, 'wholesale' ) || str_contains( (string) $screen->id, MessagingTab::PAGE ) );
		$is_user_screen     = in_array( $hook, array( 'profile.php', 'user-edit.php' ), true );

		if ( ! $is_product_screen && ! $is_wholesale_screen && ! $is_user_screen ) {
			return;
		}

		if ( $is_user_screen || $is_wholesale_screen ) {
			// The per-customer price override product picker (user screen)
			// and the Customers tab's "add existing customers" search both
			// use WooCommerce's own enhanced select (selectWoo + its
			// customer/product search AJAX). WooCommerce registers it on
			// every admin screen but only enqueues it on its own screens.
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		if ( $is_wholesale_screen ) {
			wp_enqueue_style(
				'protech-wholesale-admin',
				PROTECH_WHOLESALE_URL . 'assets/css/admin.css',
				array(),
				$this->asset_version( 'assets/css/admin.css' )
			);
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
				'bulkPricePrompt'  => sprintf( __( 'Set the wholesale (base) price for all variations (%s per pack):', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %s: currency symbol. */
				'bulkVolumePrompt' => sprintf( __( 'Set the Standard price override for all variations (%s per pack):', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %s: currency symbol. */
				'bulkBulkPrompt'   => sprintf( __( 'Set the Volume price override for all variations (%s per pack):', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'deleteTemplateConfirm' => __( 'Delete this email template? This cannot be undone.', 'protech-wholesale' ),
				'approveConfirm'   => __( 'Approve this application? The applicant is emailed a password link and sees wholesale pricing immediately.', 'protech-wholesale' ),
				'rejectPrompt'     => __( 'Reject this application? Enter an optional reason to include in the email to the applicant, or leave blank:', 'protech-wholesale' ),
				'deleteAutomationConfirm' => __( 'Delete this automation rule? This cannot be undone.', 'protech-wholesale' ),
				'chooseLogoTitle'  => __( 'Choose a logo', 'protech-wholesale' ),
				'chooseLogoButton' => __( 'Use this logo', 'protech-wholesale' ),
			)
		);

		// The Settings view's logo field is a Media Library picker too, but
		// otherwise needs none of the editor's own script or stylesheet.
		$is_settings_screen = $screen && str_contains( (string) $screen->id, MessagingTab::page_slug( 'settings' ) );

		if ( $is_settings_screen ) {
			wp_enqueue_media();
		}

		// The template editor is a separate app, built under editor-src/ and
		// committed as a fixed-name bundle, loaded only on its own screen.
		$is_editor = $screen && str_contains( (string) $screen->id, MessagingTab::page_slug( 'templates' ) ) && isset( $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a screen, changes nothing.

		if ( $is_editor ) {
			wp_enqueue_media();
			wp_enqueue_style(
				'protech-wholesale-editor',
				PROTECH_WHOLESALE_URL . 'assets/editor/editor.css',
				array( 'protech-wholesale-admin' ),
				$this->asset_version( 'assets/editor/editor.css' )
			);
			wp_enqueue_script(
				'protech-wholesale-editor',
				PROTECH_WHOLESALE_URL . 'assets/editor/editor.js',
				array(),
				$this->asset_version( 'assets/editor/editor.js' ),
				true
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which template to bootstrap, changes nothing.
			$edit_id = sanitize_text_field( wp_unslash( $_GET['edit'] ) );

			wp_localize_script(
				'protech-wholesale-editor',
				'protechEditor',
				array(
					'restRoot'       => esc_url_raw( rest_url( RestApi::NAMESPACE ) ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'template'       => EmailComposer::template_for_edit( $edit_id ),
					'schema'         => EmailBlocks::schema(),
					// Same shape as a template's own `style` (font choices as keys, not resolved
					// CSS stacks): the JS falls back to these exactly as EmailRenderer::style()
					// falls back to the site-wide settings.
					'styleDefaults'  => array(
						'width'          => MessagingSettings::email_width(),
						'page_bg'        => '#f4f5f7',
						'canvas'         => '#ffffff',
						'brand'          => MessagingSettings::email_brand_color(),
						'text'           => '#1f2937',
						'muted'          => '#6b7280',
						'font'           => 'helvetica',
						'heading_font'   => '' !== MessagingSettings::email_heading_font() ? MessagingSettings::email_heading_font() : 'helvetica',
						'link_color'     => '' !== MessagingSettings::email_link_color() ? MessagingSettings::email_link_color() : MessagingSettings::email_brand_color(),
						'mobile_padding' => MessagingSettings::email_mobile_padding(),
					),
					// Always the full set, order tags included: the editor has one merge-tag picker for every
					// template regardless of which slot (if any) it is bound to. Saving a template that uses an
					// order tag without an order-bound slot reports the same "not recognised" error any other
					// invalid tag would.
					'mergeTags'      => MergeTags::all( '', 'order' ),
					'slots'          => array_merge( EmailTemplates::slots(), WcEmailSlots::slots() ),
					'categoryLabels' => EmailTemplates::category_labels(),
					'caps'           => array( 'unfiltered_html' => current_user_can( 'unfiltered_html' ) ),
					'urls'           => array( 'list' => MessagingTab::url( 'templates' ) ),
				)
			);
		}
	}
}
