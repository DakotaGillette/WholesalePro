<?php
/**
 * Messaging → Contacts: everyone the store has a relationship with,
 * wholesale, retail or guest, searchable and filterable, with a CSV export
 * and a manual "Unsubscribe" action. Nothing here targets a send yet (see
 * class-contacts.php); this is a directory, not an audience source.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ContactsScreen
 */
class ContactsScreen {

	public const UNSUBSCRIBE_ACTION = 'protech_unsubscribe_contact';
	public const EXPORT_ACTION      = 'protech_export_contacts';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::UNSUBSCRIBE_ACTION, array( $this, 'handle_unsubscribe' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/** @return array<string, string> */
	private static function status_labels(): array {
		return array(
			Contacts::STATUS_SUBSCRIBED         => __( 'Subscribed', 'protech-wholesale' ),
			Contacts::STATUS_TRANSACTIONAL_ONLY => __( 'Transactional only', 'protech-wholesale' ),
			Contacts::STATUS_UNSUBSCRIBED       => __( 'Unsubscribed', 'protech-wholesale' ),
		);
	}

	/** @return array<string, string> */
	private static function source_labels(): array {
		return array(
			Contacts::SOURCE_WP_USER        => __( 'Account', 'protech-wholesale' ),
			Contacts::SOURCE_GUEST_CHECKOUT => __( 'Guest checkout', 'protech-wholesale' ),
			Contacts::SOURCE_ADMIN          => __( 'Added by an admin', 'protech-wholesale' ),
			Contacts::SOURCE_BACKFILL       => __( 'Existing account', 'protech-wholesale' ),
		);
	}

	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;

		if ( $view_id > 0 ) {
			self::render_single( $view_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['unsubscribed'] ) ) {
			echo '<div class="updated notice"><p>' . esc_html__( 'Contact unsubscribed.', 'protech-wholesale' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = array_filter(
			array(
				'search' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
				'status' => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
				'source' => sanitize_key( wp_unslash( $_GET['source'] ?? '' ) ),
			),
			static fn( $v ) => '' !== $v
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 25;
		$result   = Contacts::query( $filters, $page, $per_page );

		echo '<p class="description">' . esc_html__( 'Everyone with a wholesale or retail account, and everyone who has checked out as a guest. Nothing here is used to send yet; it is a directory.', 'protech-wholesale' ) . '</p>';

		echo '<form method="get" style="margin-bottom:1em;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( MessagingTab::page_slug( 'contacts' ) ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $filters['search'] ?? '' ) . '" placeholder="' . esc_attr__( 'Search name, email or company', 'protech-wholesale' ) . '" style="width:220px;" /> ';

		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'protech-wholesale' ) . '</option>';
		foreach ( self::status_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['status'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';

		echo '<select name="source"><option value="">' . esc_html__( 'All sources', 'protech-wholesale' ) . '</option>';
		foreach ( self::source_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['source'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'protech-wholesale' ), '', '', false );
		echo '</form>';

		$export_url = wp_nonce_url( add_query_arg( 'action', self::EXPORT_ACTION, admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export contacts (CSV)', 'protech-wholesale' ) . '</a></p>';

		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'No contacts match yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Name', 'protech-wholesale' ),
				__( 'Email', 'protech-wholesale' ),
				__( 'Status', 'protech-wholesale' ),
				__( 'Source', 'protech-wholesale' ),
				__( 'Orders', 'protech-wholesale' ),
				__( 'Lifetime value', 'protech-wholesale' ),
				'',
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			$name      = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );
			$view_url  = add_query_arg( array( 'page' => MessagingTab::page_slug( 'contacts' ), 'view' => $row['id'] ), admin_url( 'admin.php' ) );
			$unsub_url = wp_nonce_url( add_query_arg( array( 'action' => self::UNSUBSCRIBE_ACTION, 'contact_id' => $row['id'] ), admin_url( 'admin-post.php' ) ), self::UNSUBSCRIBE_ACTION . '_' . $row['id'] );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $view_url ) . '"><strong>' . esc_html( '' !== $name ? $name : '—' ) . '</strong></a></td>';
			echo '<td>' . esc_html( (string) $row['email'] ) . '</td>';
			echo '<td>' . esc_html( self::status_labels()[ $row['status'] ] ?? (string) $row['status'] ) . '</td>';
			echo '<td>' . esc_html( self::source_labels()[ $row['source'] ] ?? (string) $row['source'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['order_count'] ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $row['lifetime_value'] ) ) . '</td>';
			echo '<td>' . ( Contacts::STATUS_UNSUBSCRIBED === $row['status']
				? '—'
				: '<a href="' . esc_url( $unsub_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Unsubscribe this contact from marketing?', 'protech-wholesale' ) ) . '\');">' . esc_html__( 'Unsubscribe', 'protech-wholesale' ) . '</a>' ) . '</td>';
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

	private static function render_single( int $id ): void {
		$contact = Contacts::get( $id );

		if ( null === $contact ) {
			echo '<p>' . esc_html__( 'That contact no longer exists.', 'protech-wholesale' ) . ' <a href="' . esc_url( MessagingTab::url( 'contacts' ) ) . '">' . esc_html__( 'Back to all contacts', 'protech-wholesale' ) . '</a></p>';
			return;
		}

		$name = trim( (string) $contact['first_name'] . ' ' . (string) $contact['last_name'] );

		echo '<p><a href="' . esc_url( MessagingTab::url( 'contacts' ) ) . '">&larr; ' . esc_html__( 'All contacts', 'protech-wholesale' ) . '</a></p>';
		echo '<h2>' . esc_html( '' !== $name ? $name : (string) $contact['email'] ) . '</h2>';

		echo '<table class="widefat striped" style="max-width:700px;"><tbody>';
		$fields = array(
			__( 'Email', 'protech-wholesale' )          => (string) $contact['email'],
			__( 'Company', 'protech-wholesale' )        => (string) $contact['company'],
			__( 'Phone', 'protech-wholesale' )           => (string) $contact['phone'],
			__( 'Status', 'protech-wholesale' )          => self::status_labels()[ $contact['status'] ] ?? (string) $contact['status'],
			__( 'Source', 'protech-wholesale' )          => self::source_labels()[ $contact['source'] ] ?? (string) $contact['source'],
			__( 'Orders', 'protech-wholesale' )          => (string) $contact['order_count'],
			__( 'Lifetime value', 'protech-wholesale' )  => wp_strip_all_tags( wc_price( (float) $contact['lifetime_value'] ) ),
			__( 'Last order', 'protech-wholesale' )      => $contact['last_order_at'] ? mysql2date( get_option( 'date_format' ), (string) $contact['last_order_at'] ) : '—',
		);

		foreach ( $fields as $label => $value ) {
			echo '<tr><th style="width:160px;text-align:left;">' . esc_html( $label ) . '</th><td>' . esc_html( '' !== $value ? $value : '—' ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( (int) $contact['user_id'] > 0 ) {
			echo '<p><a href="' . esc_url( get_edit_user_link( (int) $contact['user_id'] ) ) . '">' . esc_html__( 'View the linked WordPress account', 'protech-wholesale' ) . '</a></p>';
		}

		$log = Contacts::consent_log_for( $id );

		if ( ! empty( $log ) ) {
			echo '<h3>' . esc_html__( 'Consent history (recorded here)', 'protech-wholesale' ) . '</h3>';
			echo '<ul style="list-style:disc;margin-left:1.5em;">';

			foreach ( $log as $entry ) {
				echo '<li>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $entry['at'] ) . ' — ' . ( $entry['note'] ? (string) $entry['note'] : __( 'Unsubscribed', 'protech-wholesale' ) ) ) . '</li>';
			}

			echo '</ul>';
		}
	}

	public function handle_unsubscribe(): void {
		$id = absint( $_GET['contact_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::UNSUBSCRIBE_ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		Contacts::record_manual_unsubscribe( $id, __( 'Unsubscribed from the Contacts screen.', 'protech-wholesale' ), get_current_user_id() );

		wp_safe_redirect( add_query_arg( 'unsubscribed', 1, MessagingTab::url( 'contacts' ) ) );
		exit;
	}

	public function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wholesale-contacts-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'first_name', 'last_name', 'company', 'phone', 'status', 'source', 'order_count', 'lifetime_value', 'last_order_at' ) );

		foreach ( Contacts::all() as $row ) {
			fputcsv(
				$out,
				array(
					$row['email'],
					$row['first_name'],
					$row['last_name'],
					$row['company'],
					$row['phone'],
					$row['status'],
					$row['source'],
					$row['order_count'],
					$row['lifetime_value'],
					$row['last_order_at'],
				)
			);
		}

		fclose( $out );
		exit;
	}
}
