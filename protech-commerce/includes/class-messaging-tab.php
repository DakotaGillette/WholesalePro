<?php
/**
 * The top-level "Messaging" section of wp-admin (it lived under
 * WooCommerce → Wholesale until 2.1.0). Since 3.7.0 it follows MailPoet's
 * shape: seven sidebar items (Home, Emails, Automations, Forms, Contacts,
 * Log, Settings), plus hidden pages (Compose, the template library and
 * editor) that are reached by a button and keep their parent item
 * highlighted. This class is the shell: the menu, the one URL builder, the
 * legacy-URL redirects, and the router that hands each view's body to its
 * own screen class. Every action that changes something goes through
 * admin-post.php and comes back as a redirect, and carries validation errors
 * or dry-run results across it with AdminStash, the same way WordPress core
 * carries settings-saved state.
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

	/** The top-level menu page; the first view (Home) is its landing page. */
	public const PAGE = 'protech-messaging';

	/** A parent slug no menu item has, so pages registered under it never show in the sidebar (MailPoet's trick). */
	public const HIDDEN_PARENT = 'protech-messaging-hidden';

	/** Page slugs from before 3.7.0 that no longer exist, and the view (plus arguments) each now lives at. */
	private const RETIRED = array(
		'protech-messaging-compliance' => array( 'settings', array( 'tab' => 'compliance' ) ),
	);

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_url' ) );
		add_action( 'admin_page_access_denied', array( $this, 'redirect_retired_page' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
	}

	/** The admin page slug of a view: the landing view owns the top-level slug, the rest hang off it. */
	public static function page_slug( string $view ): string {
		return 'home' === $view ? self::PAGE : self::PAGE . '-' . $view;
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

		foreach ( array_keys( self::get_views() + self::hidden_views() ) as $view ) {
			if ( self::page_slug( $view ) === $page ) {
				return $view;
			}
		}

		return 'home';
	}

	/** The top-level menu, one sub-menu page per sidebar view, and the hidden pages. */
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

		foreach ( self::hidden_views() as $view => $info ) {
			add_submenu_page(
				self::HIDDEN_PARENT,
				$info['title'],
				$info['title'],
				'manage_woocommerce',
				self::page_slug( $view ),
				array( __CLASS__, 'render_page' )
			);
		}
	}

	/**
	 * On a hidden page, open the Messaging menu in the sidebar. Returning the
	 * parent alone is not enough: menu-header.php calls get_admin_page_parent()
	 * right after this filter, which looks $plugin_page up in $submenu and
	 * would reset the parent to HIDDEN_PARENT. Pointing $plugin_page at the
	 * sidebar item makes that lookup land on Messaging (MailPoet does the
	 * same). The page callback was already chosen by then, so nothing else
	 * reads it.
	 *
	 * @param string $parent_file
	 * @return string
	 */
	public function highlight_parent( $parent_file ) {
		global $plugin_page;

		$parent = self::hidden_parent_view();

		if ( null === $parent ) {
			return $parent_file;
		}

		$plugin_page = self::page_slug( $parent ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- see the docblock.

		return self::PAGE;
	}

	/**
	 * On a hidden page, highlight the sidebar item it belongs to (Emails for Compose, say).
	 *
	 * @param string|null $submenu_file
	 * @return string|null
	 */
	public function highlight_submenu( $submenu_file ) {
		$parent = self::hidden_parent_view();

		return null !== $parent ? self::page_slug( $parent ) : $submenu_file;
	}

	/** The sidebar view the current hidden page belongs to, or null when this is not a hidden Messaging page. */
	private static function hidden_parent_view(): ?string {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		foreach ( self::hidden_views() as $view => $info ) {
			if ( self::page_slug( $view ) === $page ) {
				return $info['parent'];
			}
		}

		return null;
	}

	/** The page shell: the view's own heading, then the view. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$view    = self::current_view();
		$hidden  = self::hidden_views();
		$views   = self::get_views();
		$editing = isset( $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		if ( 'home' === $view ) {
			$title = __( 'Messaging', 'protech-wholesale' );
		} else {
			$title = $hidden[ $view ]['heading'] ?? $views[ $view ];
		}

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';

		if ( in_array( $view, array( 'emails', 'templates' ), true ) && ! $editing ) {
			echo ' <a href="' . esc_url( self::url( 'compose' ) ) . '" class="page-title-action">' . esc_html__( 'Add new email', 'protech-wholesale' ) . '</a>';
		}

		echo '<hr class="wp-header-end" />';
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

		$view = sanitize_key( $_GET['view'] ?? 'emails' );
		$args = array();

		foreach ( $_GET as $key => $value ) {
			if ( ! in_array( $key, array( 'page', 'tab', 'view' ), true ) && is_scalar( $value ) ) {
				$args[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		// phpcs:enable

		// Views renamed or folded away since then.
		if ( 'automations' === $view ) {
			$view = 'emails';
		} elseif ( 'compliance' === $view ) {
			$view        = 'settings';
			$args['tab'] = 'compliance';
		}

		if ( ! array_key_exists( $view, self::get_views() + self::hidden_views() ) ) {
			$view = 'emails';
		}

		wp_safe_redirect( self::url( $view, $args ) );
		exit;
	}

	/**
	 * A retired page slug (Compliance, folded into Settings in 3.7.0) is no
	 * longer registered, so WordPress refuses it before admin_init runs. This
	 * fires just before that refusal and sends the bookmark to its new home.
	 */
	public function redirect_retired_page(): void {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only redirect of a GET URL.

		if ( ! isset( self::RETIRED[ $page ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_safe_redirect( self::url( self::RETIRED[ $page ][0], self::RETIRED[ $page ][1] ) );
		exit;
	}

	/**
	 * The sidebar items, in order.
	 *
	 * @return array<string, string>
	 */
	private static function get_views(): array {
		return array(
			'home'     => __( 'Home', 'protech-wholesale' ),
			'emails'   => __( 'Emails', 'protech-wholesale' ),
			'flows'    => __( 'Automations', 'protech-wholesale' ),
			'forms'    => __( 'Forms', 'protech-wholesale' ),
			'contacts' => __( 'Contacts', 'protech-wholesale' ),
			'log'      => __( 'Log', 'protech-wholesale' ),
			'settings' => __( 'Settings', 'protech-wholesale' ),
		);
	}

	/**
	 * Pages that are not in the sidebar: the browser title, the page heading,
	 * and the sidebar view to highlight while on them.
	 *
	 * @return array<string, array{title: string, heading: string, parent: string}>
	 */
	private static function hidden_views(): array {
		return array(
			'compose'   => array(
				'title'   => __( 'New email', 'protech-wholesale' ),
				'heading' => __( 'New email', 'protech-wholesale' ),
				'parent'  => 'emails',
			),
			'templates' => array(
				'title'   => __( 'Email templates', 'protech-wholesale' ),
				'heading' => __( 'Emails', 'protech-wholesale' ),
				'parent'  => 'emails',
			),
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

		switch ( self::current_view() ) {
			case 'emails':
				AutomationsScreen::render();
				break;
			case 'compose':
				ComposeScreen::render();
				break;
			case 'templates':
				if ( ! isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
					AutomationsScreen::render_tabs( 'templates' );
				}
				EmailComposer::render();
				break;
			case 'contacts':
				ContactsScreen::render();
				break;
			case 'forms':
				FormsScreen::render();
				break;
			case 'flows':
				FlowsScreen::render();
				break;
			case 'log':
				LogScreen::render();
				break;
			case 'settings':
				MessagingSettingsScreen::render();
				break;
			default:
				HomeScreen::render();
		}
	}
}
