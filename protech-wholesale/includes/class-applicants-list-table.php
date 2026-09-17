<?php
/**
 * WP_List_Table of wholesale applications — Pending, Approved, and
 * Rejected views — with row actions to approve/reject.
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
				// The admin page's real screen id, so the table can also be
				// built where no "current screen" exists (tests, CLI).
				'screen'   => 'woocommerce_page_protech-wholesale',
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

	/**
	 * @return array<string, string> status => label, in display order.
	 */
	private static function view_labels(): array {
		return array(
			Approval::STATUS_PENDING  => __( 'Pending', 'protech-wholesale' ),
			Approval::STATUS_APPROVED => __( 'Approved', 'protech-wholesale' ),
			Approval::STATUS_REJECTED => __( 'Rejected', 'protech-wholesale' ),
		);
	}

	public static function current_view(): string {
		$status = sanitize_key( $_GET['status'] ?? Approval::STATUS_PENDING );

		return array_key_exists( $status, self::view_labels() ) ? $status : Approval::STATUS_PENDING;
	}

	/**
	 * Pending and Rejected are driven by the application status meta, not
	 * by role: a rejected applicant no longer holds the pending role
	 * (Approval::handle_reject() removes it), and a pending application
	 * recorded against a staff account never gets the role at all (see
	 * ApplicationForm::create_pending_applicant()) — both still have to
	 * show up here. Approved is role-driven so customers flagged manually
	 * from their profile (who may have no application record) are listed.
	 *
	 * @return array<string, mixed> get_users() args.
	 */
	private static function query_args_for( string $status ): array {
		if ( Approval::STATUS_APPROVED === $status ) {
			return array( 'role' => Roles::CUSTOMER );
		}

		return array(
			'meta_key'   => Approval::META_APP_STATUS,
			'meta_value' => $status,
		);
	}

	protected function get_views(): array {
		$current = self::current_view();
		$base    = remove_query_arg( 'status' );
		$views   = array();

		foreach ( self::view_labels() as $status => $label ) {
			$count = count( get_users( array_merge( self::query_args_for( $status ), array( 'fields' => 'ID' ) ) ) );

			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? 'current' : '',
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page = 20;
		$paged    = $this->get_pagenum();
		$search   = sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) );

		$args = array_merge(
			self::query_args_for( self::current_view() ),
			array(
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'number'      => $per_page,
				'offset'      => ( $paged - 1 ) * $per_page,
				'count_total' => true,
			)
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->get_total(),
				'per_page'    => $per_page,
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
					'status'     => get_user_meta( $user->ID, Approval::META_APP_STATUS, true ) ?: Approval::STATUS_APPROVED,
					'reason'     => (string) get_user_meta( $user->ID, Approval::META_APP_REJECT_REASON, true ),
					'submitted'  => get_user_meta( $user->ID, '_protech_wholesale_app_submitted_at', true ),
					'privileged' => Roles::is_privileged( $user->ID ),
				);
			},
			$users
		);
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	protected function column_submitted( array $item ): string {
		$submitted = (string) $item['submitted'];

		if ( '' === $submitted ) {
			return '—';
		}

		return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submitted ) );
	}

	protected function column_status( array $item ): string {
		$labels = self::view_labels();
		$out    = esc_html( $labels[ $item['status'] ] ?? (string) $item['status'] );

		if ( Approval::STATUS_REJECTED === $item['status'] && '' !== $item['reason'] ) {
			$out .= '<br /><span class="description">' . esc_html( $item['reason'] ) . '</span>';
		}

		if ( $item['privileged'] && Approval::STATUS_PENDING === $item['status'] ) {
			$out .= '<br /><span class="description">' . esc_html__( 'Existing staff account — its roles were left unchanged. Approve only if this request is genuine.', 'protech-wholesale' ) . '</span>';
		}

		return $out;
	}

	protected function column_store_name( array $item ): string {
		$actions = array();

		$edit_url        = get_edit_user_link( $item['id'] );
		$actions['view'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'View profile', 'protech-wholesale' ) );

		// Approve is offered for rejected applications too (a reconsideration);
		// Reject only while pending.
		if ( in_array( $item['status'], array( Approval::STATUS_PENDING, Approval::STATUS_REJECTED ), true ) ) {
			$approve_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'protech_approve_applicant',
						'user_id' => $item['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'protech_approve_applicant_' . $item['id']
			);

			$actions['approve'] = sprintf(
				'<a href="%s" class="protech-approve-link" style="color:#2271b1;">%s</a>',
				esc_url( $approve_url ),
				esc_html__( 'Approve', 'protech-wholesale' )
			);
		}

		if ( Approval::STATUS_PENDING === $item['status'] ) {
			$reject_nonce = wp_create_nonce( 'protech_reject_applicant_' . $item['id'] );
			$reject_url   = add_query_arg(
				array(
					'action'   => 'protech_reject_applicant',
					'user_id'  => $item['id'],
					'_wpnonce' => $reject_nonce,
				),
				admin_url( 'admin-post.php' )
			);

			// admin.js turns this into a POST carrying an optional reason;
			// the href is the no-JS fallback (rejects with no reason).
			$actions['reject'] = sprintf(
				'<a href="%s" class="protech-reject-link" data-user-id="%d" data-nonce="%s" style="color:#b32d2e;">%s</a>',
				esc_url( $reject_url ),
				(int) $item['id'],
				esc_attr( $reject_nonce ),
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
