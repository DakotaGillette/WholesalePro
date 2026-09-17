<?php
/**
 * WP_List_Table of wholesale applicants (pending) and approved wholesale
 * customers, with row actions to approve/reject.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ApplicantsListTable
 */
class ApplicantsListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'applicant',
				'plural'   => 'applicants',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'store_name' => __( 'Store', 'protech-wholesale' ),
			'name'       => __( 'Contact', 'protech-wholesale' ),
			'email'      => __( 'Email', 'protech-wholesale' ),
			'phone'      => __( 'Phone', 'protech-wholesale' ),
			'status'     => __( 'Status', 'protech-wholesale' ),
			'submitted'  => __( 'Submitted', 'protech-wholesale' ),
		);
	}

	protected function get_views(): array {
		$current = sanitize_key( $_GET['status'] ?? 'pending' );

		$counts = array(
			'pending'  => count( get_users( array( 'role' => Roles::PENDING, 'fields' => 'ID' ) ) ),
			'approved' => count( get_users( array( 'role' => Roles::CUSTOMER, 'fields' => 'ID' ) ) ),
		);

		$base = remove_query_arg( 'status' );

		return array(
			'pending'  => sprintf(
				'<a href="%s" class="%s">%s (%d)</a>',
				esc_url( add_query_arg( 'status', 'pending', $base ) ),
				'pending' === $current ? 'current' : '',
				esc_html__( 'Pending', 'protech-wholesale' ),
				$counts['pending']
			),
			'approved' => sprintf(
				'<a href="%s" class="%s">%s (%d)</a>',
				esc_url( add_query_arg( 'status', 'approved', $base ) ),
				'approved' === $current ? 'current' : '',
				esc_html__( 'Approved', 'protech-wholesale' ),
				$counts['approved']
			),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$status = sanitize_key( $_GET['status'] ?? 'pending' );
		$role   = 'approved' === $status ? Roles::CUSTOMER : Roles::PENDING;

		$users = get_users(
			array(
				'role'    => $role,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);

		$this->items = array_map(
			static function ( \WP_User $user ): array {
				return array(
					'id'         => $user->ID,
					'store_name' => get_user_meta( $user->ID, '_protech_wholesale_app_store_name', true ),
					'name'       => get_user_meta( $user->ID, '_protech_wholesale_app_name', true ) ?: $user->display_name,
					'email'      => $user->user_email,
					'phone'      => get_user_meta( $user->ID, '_protech_wholesale_app_phone', true ),
					'status'     => get_user_meta( $user->ID, '_protech_wholesale_app_status', true ) ?: 'approved',
					'submitted'  => get_user_meta( $user->ID, '_protech_wholesale_app_submitted_at', true ),
				);
			},
			$users
		);
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	protected function column_store_name( array $item ): string {
		$actions = array();

		$edit_url = get_edit_user_link( $item['id'] );
		$actions['view'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'View profile', 'protech-wholesale' ) );

		if ( 'pending' === $item['status'] ) {
			$approve_url = wp_nonce_url(
				add_query_arg(
					array( 'action' => 'protech_approve_applicant', 'user_id' => $item['id'] ),
					admin_url( 'admin-post.php' )
				),
				'protech_approve_applicant_' . $item['id']
			);

			$reject_url = wp_nonce_url(
				add_query_arg(
					array( 'action' => 'protech_reject_applicant', 'user_id' => $item['id'] ),
					admin_url( 'admin-post.php' )
				),
				'protech_reject_applicant_' . $item['id']
			);

			$actions['approve'] = sprintf( '<a href="%s" style="color:#2271b1;">%s</a>', esc_url( $approve_url ), esc_html__( 'Approve', 'protech-wholesale' ) );
			$actions['reject']  = sprintf(
				'<a href="%s" style="color:#b32d2e;" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $reject_url ),
				esc_js( __( 'Reject this application?', 'protech-wholesale' ) ),
				esc_html__( 'Reject', 'protech-wholesale' )
			);
		}

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( $item['store_name'] ?: __( '(no store name)', 'protech-wholesale' ) ),
			$this->row_actions( $actions )
		);
	}

	public function no_items(): void {
		esc_html_e( 'No applicants found.', 'protech-wholesale' );
	}
}
