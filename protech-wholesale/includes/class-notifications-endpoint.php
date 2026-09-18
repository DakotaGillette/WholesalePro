<?php
/**
 * My Account -> "Notifications": a wholesale customer's own SMS number,
 * the two SMS consents (order updates, marketing/reorder reminders), and
 * an email-marketing opt-out — the self-service half of the consent
 * model (the other half is Approval's "Messaging" profile section, for
 * an admin recording consent on a customer's behalf).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationsEndpoint
 */
class NotificationsEndpoint {

	public const ENDPOINT = 'wholesale-notifications';

	private const NONCE_ACTION = 'protech_wholesale_notifications';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'template_redirect', array( $this, 'handle_save' ) );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		if ( ! Roles::is_wholesale_customer() ) {
			return $items;
		}

		$with_item = array();

		foreach ( $items as $key => $label ) {
			$with_item[ $key ] = $label;

			if ( 'edit-account' === $key ) {
				$with_item[ self::ENDPOINT ] = __( 'Notifications', 'protech-wholesale' );
			}
		}

		if ( ! isset( $with_item[ self::ENDPOINT ] ) ) {
			$with_item[ self::ENDPOINT ] = __( 'Notifications', 'protech-wholesale' );
		}

		return $with_item;
	}

	public function endpoint_title( string $title ): string {
		return __( 'Notifications', 'protech-wholesale' );
	}

	public function render(): void {
		if ( ! Roles::is_wholesale_customer() ) {
			echo '<p>' . esc_html__( 'Not available.', 'protech-wholesale' ) . '</p>';
			return;
		}

		$user_id = get_current_user_id();
		$wording = SmsConsent::wording_variants();

		wc_get_template(
			'account-notifications.php',
			array(
				'state'                     => SmsConsent::state( $user_id ),
				'wording_transactional'     => $wording['transactional'],
				'wording_marketing'         => $wording['marketing'],
				'nonce_action'              => self::NONCE_ACTION,
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['protech_wholesale_notifications_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_notifications_nonce'] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		if ( ! is_user_logged_in() || ! Roles::is_wholesale_customer() ) {
			return;
		}

		$user_id = get_current_user_id();
		$phone   = isset( $_POST['protech_sms_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['protech_sms_phone'] ) ) : '';

		SmsConsent::record(
			$user_id,
			array(
				'phone'             => $phone,
				'sms_transactional' => ! empty( $_POST['protech_sms_transactional'] ),
				'sms_marketing'     => ! empty( $_POST['protech_sms_marketing'] ),
				'email_marketing'   => ! empty( $_POST['protech_email_marketing'] ),
			),
			SmsConsent::SOURCE_MY_ACCOUNT
		);

		wc_add_notice( __( 'Your notification preferences were saved.', 'protech-wholesale' ) );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}
}
