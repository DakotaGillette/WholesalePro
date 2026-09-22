<?php
/**
 * The top-level "Messaging" section of wp-admin (it lived under
 * WooCommerce → Wholesale until 2.1.0): five views (Automations, Compose,
 * Log, Compliance, Settings), each its own sub-menu page. This class is the
 * shell: the menu, the one URL builder, the legacy-URL redirect, and the
 * router that hands each view's body to its own screen class
 * (AutomationsScreen, ComposeScreen, EmailComposer for templates, LogScreen,
 * ComplianceScreen, MessagingSettingsScreen). Every action that changes
 * something goes through admin-post.php and comes back as a redirect, and
 * carries validation errors or dry-run results across it with AdminStash,
 * the same way WordPress core carries settings-saved state.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessagingTab
 */
class MessagingTab {

	/** The top-level menu page; the first view (Automations) is its landing page. */
	public const PAGE = 'protech-messaging';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_url' ) );
	}

	/** The admin page slug of a view: the landing view owns the top-level slug, the rest hang off it. */
	public static function page_slug( string $view ): string {
		return 'automations' === $view ? self::PAGE : self::PAGE . '-' . $view;
	}

	/**
	 * The one place that builds a Messaging URL, so moving the section was a
	 * change here and nowhere else.
	 *
	 * @param array<string, scalar> $args
	 */
	public static function url( string $view, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::page_slug( $view ) ), $args ), admin_url( 'admin.php' ) );
	}

	/** Which view the current request is for, from the admin page slug. */
	private static function current_view(): string {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		foreach ( array_keys( self::get_views() ) as $view ) {
			if ( self::page_slug( $view ) === $page ) {
				return $view;
			}
		}

		return 'automations';
	}

	/** The top-level menu and one sub-menu page per view. */
	public function register_menu(): void {
		add_menu_page(
			__( 'Messaging', 'protech-wholesale' ),
			__( 'Messaging', 'protech-wholesale' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-email-alt',
			56
		);

		foreach ( self::get_views() as $view => $label ) {
			add_submenu_page(
				self::PAGE,
				$label,
				$label,
				'manage_woocommerce',
				self::page_slug( $view ),
				array( __CLASS__, 'render_page' )
			);
		}
	}

	/** The page shell: the heading, then the current view. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Messaging', 'protech-wholesale' ) . '</h1>';
		self::render();
		echo '</div>';
	}

	/**
	 * Bookmarks and old links (WooCommerce → Wholesale → Messaging, with its
	 * `&tab=messaging&view=…`) go to the same view in the new section, with
	 * every other query argument carried across.
	 */
	public function redirect_legacy_url(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only redirect of a GET URL.
		if ( 'protech-wholesale' !== sanitize_key( $_GET['page'] ?? '' ) || 'messaging' !== sanitize_key( $_GET['tab'] ?? '' ) ) {
			return;
		}

		$view = sanitize_key( $_GET['view'] ?? 'automations' );
		$args = array();

		foreach ( $_GET as $key => $value ) {
			if ( ! in_array( $key, array( 'page', 'tab', 'view' ), true ) && is_scalar( $value ) ) {
				$args[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		// phpcs:enable

		if ( ! array_key_exists( $view, self::get_views() ) ) {
			$view = 'automations';
		}

		wp_safe_redirect( self::url( $view, $args ) );
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_views(): array {
		return array(
			'automations' => __( 'Emails', 'protech-wholesale' ),
			'compose'     => __( 'Compose', 'protech-wholesale' ),
			'templates'   => __( 'Email templates', 'protech-wholesale' ),
			'contacts'    => __( 'Contacts', 'protech-wholesale' ),
			'log'         => __( 'Log', 'protech-wholesale' ),
			'compliance'  => __( 'Compliance', 'protech-wholesale' ),
			'settings'    => __( 'Settings', 'protech-wholesale' ),
		);
	}

	/**
	 * Fills the next Compose page with an earlier message ("Duplicate and edit").
	 *
	 * @param array<string, mixed> $input Compose form shape.
	 */
	public static function prefill_compose( array $input ): void {
		AdminStash::stash( 'compose_form', array( 'input' => $input, 'errors' => array() ) );
	}

	// -----------------------------------------------------------------
	// Router.
	// -----------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$view  = self::current_view();
		$views = self::get_views();

		echo '<p>' . esc_html__( 'Send emails and texts to wholesale customers — automatically, on a rule, or on demand — through Brevo.', 'protech-wholesale' ) . '</p>';

		if ( ! MessagingSettings::enabled() && 'settings' !== $view ) {
			echo '<div class="notice notice-info inline"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the Settings view. */
					__( 'Automations are turned off — turn them on from <a href="%s">Settings</a> when you\'re ready. Compose (manual sends) works either way.', 'protech-wholesale' ),
					esc_url( self::url( 'settings' ) )
				)
			) . '</p></div>';
		}

		echo '<ul class="subsubsub">';
		$keys = array_keys( $views );
		foreach ( $views as $slug => $label ) {
			$sep = end( $keys ) === $slug ? '' : ' |';
			printf(
				'<li><a href="%s" class="%s">%s</a>%s</li>',
				esc_url( self::url( $slug ) ),
				$view === $slug ? 'current' : '',
				esc_html( $label ),
				$sep
			);
		}
		echo '</ul><br class="clear" />';

		switch ( $view ) {
			case 'compose':
				ComposeScreen::render();
				break;
			case 'templates':
				EmailComposer::render();
				break;
			case 'contacts':
				ContactsScreen::render();
				break;
			case 'log':
				LogScreen::render();
				break;
			case 'compliance':
				ComplianceScreen::render();
				break;
			case 'settings':
				MessagingSettingsScreen::render();
				break;
			default:
				AutomationsScreen::render();
		}
	}
}
