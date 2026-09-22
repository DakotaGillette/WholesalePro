<?php
/**
 * Messaging → Emails (view key `emails`; the class keeps its pre-3.7.0 name).
 * Three tabs, the way MailPoet's Emails page has one list per kind of email:
 * Sent (past one-off sends), Automatic (the lifecycle and WooCommerce order
 * emails, see EmailsScreen) and Templates (EmailComposer's library, which is
 * its own hidden page and prints this same tab row).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutomationsScreen
 */
class AutomationsScreen {

	public static function render(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'sent' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		if ( 'automatic' !== $tab ) {
			$tab = 'sent';
		}

		self::render_tabs( $tab );

		if ( 'automatic' === $tab ) {
			EmailsScreen::render_lifecycle();
			EmailsScreen::render_order_emails();

			echo '<p class="description">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the Automations (flows) view. */
					__( 'Multi-step emails and texts that send when something happens are built under <a href="%s">Automations</a>.', 'protech-wholesale' ),
					esc_url( MessagingTab::url( 'flows' ) )
				)
			) . '</p>';
			return;
		}

		EmailsScreen::render_sent();
	}

	/** The Emails tab row, shared with the template library page. */
	public static function render_tabs( string $current ): void {
		$tabs = array(
			'sent'      => array( __( 'Newsletters', 'protech-wholesale' ), MessagingTab::url( 'emails' ) ),
			'automatic' => array( __( 'Automatic', 'protech-wholesale' ), MessagingTab::url( 'emails', array( 'tab' => 'automatic' ) ) ),
			'templates' => array( __( 'Templates', 'protech-wholesale' ), MessagingTab::url( 'templates' ) ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix">';

		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $tab[1] ),
				$current === $key ? ' nav-tab-active' : '',
				esc_html( $tab[0] )
			);
		}

		echo '</nav>';
	}
}
