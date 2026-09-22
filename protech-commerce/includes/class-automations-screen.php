<?php
/**
 * Messaging → Emails (the "automations" view, still on the landing/top-level
 * slug so old bookmarks keep working, see MessagingTab::get_views()): the
 * lifecycle emails list (EmailsScreen), the order emails list, and a pointer
 * to Automatic (FlowsScreen) — the rule editor that used to live here was
 * removed in 3.6.0 when flows replaced single-shot automation rules.
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

	public function register_hooks(): void {
		add_action( 'admin_post_protech_run_automations_now', array( $this, 'handle_run_automations_now' ) );
	}

	public static function render(): void {
		if ( isset( $_GET['ran'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="updated notice"><p>' . esc_html__( 'Automations are running in the background — check the Log in a moment.', 'protech-wholesale' ) . '</p></div>';
		}

		EmailsScreen::render_lifecycle();
		EmailsScreen::render_order_emails();

		echo '<h2>' . esc_html__( 'Automatic', 'protech-wholesale' ) . '</h2>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: %s: link to the Automatic (flows) view. */
				__( 'Multi-step flows send on their own when something happens — build and manage them under <a href="%s">Automatic</a>.', 'protech-wholesale' ),
				esc_url( MessagingTab::url( 'flows' ) )
			)
		) . '</p>';

		EmailsScreen::render_sent();

		self::render_runner_status();
	}

	private static function render_runner_status(): void {
		$status = AutomationRunner::status();

		echo '<p class="description">';

		if ( $status['next_daily_run'] > 0 ) {
			printf(
				/* translators: %s: date/time. */
				esc_html__( 'Next automated run: %s. ', 'protech-wholesale' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['next_daily_run'] ) )
			);
		}

		printf(
			/* translators: %d: number of queued/sending messages. */
			esc_html( _n( '%d message currently queued.', '%d messages currently queued.', $status['pending'], 'protech-wholesale' ) ) . ' ',
			(int) $status['pending']
		);

		echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=protech-wholesale' ) ) . '">' . esc_html__( 'View in Scheduled Actions', 'protech-wholesale' ) . '</a>';

		if ( MessagingSettings::enabled() ) {
			$run_url = wp_nonce_url( add_query_arg( 'action', 'protech_run_automations_now', admin_url( 'admin-post.php' ) ), 'protech_run_automations_now' );
			echo ' &middot; <a href="' . esc_url( $run_url ) . '">' . esc_html__( 'Run automations now', 'protech-wholesale' ) . '</a>';
		}

		echo '</p>';
	}

	public function handle_run_automations_now(): void {
		check_admin_referer( 'protech_run_automations_now' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		AutomationRunner::run_now();

		wp_safe_redirect( add_query_arg( 'ran', '1', MessagingTab::url( 'automations' ) ) );
		exit;
	}
}
