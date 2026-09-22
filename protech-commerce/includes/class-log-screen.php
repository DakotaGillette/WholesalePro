<?php
/**
 * Messaging → Log: every queued, sent, failed and skipped message, filtered
 * by channel/status/rule and paginated.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LogScreen
 */
class LogScreen {

	public function register_hooks(): void {
		// Purely a read/filter screen: nothing to hook globally here.
	}

	public static function render(): void {
		if ( ! MessageLog::table_exists() ) {
			echo '<p>' . esc_html__( 'The message log table has not been created yet. It is created automatically on the next update.', 'protech-wholesale' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		if ( isset( $_GET['queued'] ) ) {
			printf(
				'<div class="updated notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of messages queued. */
						_n( '%d message queued for delivery.', '%d messages queued for delivery.', (int) $_GET['queued'], 'protech-wholesale' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						(int) $_GET['queued'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					)
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'channel' => sanitize_key( wp_unslash( $_GET['channel'] ?? '' ) ),
			'status'  => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
			'rule_id' => sanitize_text_field( wp_unslash( $_GET['rule_id'] ?? '' ) ),
		);
		$filters = array_filter( $filters, static fn( $v ) => '' !== $v );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 25;

		$result = MessageLog::query( $filters, $page, $per_page );

		echo '<form method="get" style="margin-bottom:1em;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( MessagingTab::page_slug( 'log' ) ) . '" />';
		echo '<select name="channel"><option value="">' . esc_html__( 'All channels', 'protech-wholesale' ) . '</option>';
		foreach ( array( 'email' => __( 'Email', 'protech-wholesale' ), 'sms' => __( 'SMS', 'protech-wholesale' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['channel'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'protech-wholesale' ) . '</option>';
		foreach ( array( MessageLog::STATUS_QUEUED, MessageLog::STATUS_SENDING, MessageLog::STATUS_SENT, MessageLog::STATUS_FAILED, MessageLog::STATUS_SKIPPED ) as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['status'] ?? '', $value, false ) . '>' . esc_html( ucfirst( $value ) ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'protech-wholesale' ), '', '', false );
		echo '</form>';

		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Date', 'protech-wholesale' ),
				__( 'Customer', 'protech-wholesale' ),
				__( 'Channel', 'protech-wholesale' ),
				__( 'Rule', 'protech-wholesale' ),
				__( 'Status', 'protech-wholesale' ),
				__( 'Detail', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : ( '#' . $row['user_id'] ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['channel'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['rule_id'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['error'] ?: $row['reason'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / $per_page );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) );
			echo '</div></div>';
		}
	}
}
