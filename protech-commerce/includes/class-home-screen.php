<?php
/**
 * Messaging → Home (3.7.0), the landing page, like MailPoet's Home: what is
 * left to set up, four counts, the latest sends, and when automations next
 * run. It also owns "Run automations now", which lived on the old landing
 * page (AutomationsScreen) before Emails became a page of tabs.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HomeScreen
 */
class HomeScreen {

	public function register_hooks(): void {
		add_action( 'admin_post_protech_run_automations_now', array( $this, 'handle_run_automations_now' ) );
	}

	public static function render(): void {
		if ( isset( $_GET['ran'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="updated notice"><p>' . esc_html__( 'Automations are running in the background. Check the Log in a moment.', 'protech-wholesale' ) . '</p></div>';
		}

		self::render_checklist();
		self::render_counts();
		self::render_recent();
		self::render_runner_status();
	}

	/**
	 * What still needs doing before emails go out, each with a link to where it is done.
	 *
	 * @return array<int, array{done: bool, text: string, url: string, link: string}>
	 */
	public static function checklist(): array {
		return array(
			array(
				'done' => MessageProviders::email()->is_configured(),
				'text' => __( 'Connect a service to send email', 'protech-wholesale' ),
				'url'  => MessagingTab::url( 'settings', array( 'tab' => 'sending' ) ),
				'link' => __( 'Open sending settings', 'protech-wholesale' ),
			),
			array(
				'done' => '' !== MessagingSettings::from_email(),
				'text' => __( 'Set the address emails come from', 'protech-wholesale' ),
				'url'  => MessagingTab::url( 'settings', array( 'tab' => 'sending' ) ),
				'link' => __( 'Set the sender', 'protech-wholesale' ),
			),
			array(
				'done' => '' !== MergeTags::store_address(),
				'text' => __( 'Add your store address (marketing emails must show it)', 'protech-wholesale' ),
				'url'  => admin_url( 'admin.php?page=wc-settings&tab=general' ),
				'link' => __( 'Set the store address', 'protech-wholesale' ),
			),
			array(
				'done' => MessagingSettings::enabled(),
				'text' => __( 'Turn on automations (manual sends work without this)', 'protech-wholesale' ),
				'url'  => MessagingTab::url( 'settings' ),
				'link' => __( 'Turn them on', 'protech-wholesale' ),
			),
		);
	}

	private static function render_checklist(): void {
		$items = self::checklist();
		$left  = count( array_filter( $items, static fn( array $item ): bool => ! $item['done'] ) );

		if ( 0 === $left ) {
			return;
		}

		echo '<div class="protech-home-card">';
		echo '<h2>' . esc_html__( 'Finish setting up', 'protech-wholesale' ) . '</h2>';
		echo '<ul class="protech-home-checklist">';

		foreach ( $items as $item ) {
			if ( $item['done'] ) {
				echo '<li class="is-done"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html( $item['text'] ) . '</li>';
			} else {
				echo '<li><span class="dashicons dashicons-marker" aria-hidden="true"></span> ' . esc_html( $item['text'] ) . ' <a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['link'] ) . '</a></li>';
			}
		}

		echo '</ul></div>';
	}

	private static function render_counts(): void {
		$status = AutomationRunner::status();
		$sent   = MessageLog::table_exists() ? MessageLog::query( array( 'status' => MessageLog::STATUS_SENT ), 1, 1 )['total'] : 0;
		$next   = $status['next_daily_run'] > 0
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['next_daily_run'] )
			: __( 'Not scheduled', 'protech-wholesale' );

		$counts = array(
			array( __( 'Contacts', 'protech-wholesale' ), number_format_i18n( Contacts::count() ), MessagingTab::url( 'contacts' ) ),
			array( __( 'Messages sent', 'protech-wholesale' ), number_format_i18n( $sent ), MessagingTab::url( 'log', array( 'status' => MessageLog::STATUS_SENT ) ) ),
			array( __( 'Waiting to send', 'protech-wholesale' ), number_format_i18n( $status['pending'] ), MessagingTab::url( 'log' ) ),
			array( __( 'Next automated run', 'protech-wholesale' ), (string) $next, '' ),
		);

		echo '<div class="protech-home-stats">';

		foreach ( $counts as $count ) {
			echo '<div class="protech-home-card"><div class="protech-home-stat-label">' . esc_html( $count[0] ) . '</div>';

			if ( '' !== $count[2] ) {
				echo '<a class="protech-home-stat-value" href="' . esc_url( $count[2] ) . '">' . esc_html( $count[1] ) . '</a>';
			} else {
				echo '<div class="protech-home-stat-value is-small">' . esc_html( $count[1] ) . '</div>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	private static function render_recent(): void {
		$campaigns = Campaigns::recent_sent( 5 );

		echo '<div class="protech-home-card">';
		echo '<h2>' . esc_html__( 'Latest sends', 'protech-wholesale' ) . '</h2>';

		if ( empty( $campaigns ) ) {
			echo '<p>' . esc_html__( 'You have not written and sent an email here yet. When you do, it shows up here.', 'protech-wholesale' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( MessagingTab::url( 'compose' ) ) . '">' . esc_html__( 'Write an email', 'protech-wholesale' ) . '</a></p>';
			echo '</div>';
			return;
		}

		echo '<ul class="protech-home-recent">';

		foreach ( $campaigns as $campaign ) {
			echo '<li><a href="' . esc_url( MessagingTab::url( 'log', array( 'rule_id' => 'campaign:' . (string) $campaign['id'] ) ) ) . '">' . esc_html( (string) $campaign['name'] ) . '</a> <span class="description">' . esc_html( human_time_diff( (int) $campaign['created_at'] ) . ' ' . __( 'ago', 'protech-wholesale' ) ) . '</span></li>';
		}

		echo '</ul>';
		echo '<p><a href="' . esc_url( MessagingTab::url( 'emails' ) ) . '">' . esc_html__( 'See all sent emails', 'protech-wholesale' ) . '</a></p>';
		echo '</div>';
	}

	private static function render_runner_status(): void {
		echo '<p class="description">';
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

		wp_safe_redirect( add_query_arg( 'ran', '1', MessagingTab::url( 'home' ) ) );
		exit;
	}
}
