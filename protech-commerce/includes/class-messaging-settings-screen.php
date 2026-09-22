<?php
/**
 * Messaging → Settings: the WooCommerce Settings API form built from
 * MessagingSettings::get_fields(), plus the "Test Brevo connection" button.
 * Distinct from MessagingSettings itself, which owns the option constants,
 * defaults and field definitions this screen only renders and saves.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessagingSettingsScreen
 */
class MessagingSettingsScreen {

	public function register_hooks(): void {
		add_action( 'admin_post_protech_test_brevo_connection', array( $this, 'handle_test_brevo_connection' ) );
	}

	public static function render(): void {
		$settings = new MessagingSettings();

		if ( isset( $_POST['protech_wholesale_msg_settings_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_msg_settings_nonce'] ) ), 'protech_wholesale_save_msg_settings' )
		) {
			woocommerce_update_options( $settings->get_fields() );
			echo '<div class="updated notice"><p>' . esc_html__( 'Settings saved.', 'protech-wholesale' ) . '</p></div>';
			Logger::info( 'Messaging settings updated by user #' . get_current_user_id() );
		}

		$test = AdminStash::unstash( 'brevo_test' );

		if ( null !== $test ) {
			$class = $test['ok'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $test['message'] ) . '</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_save_msg_settings', 'protech_wholesale_msg_settings_nonce' );
		woocommerce_admin_fields( $settings->get_fields() );
		submit_button();
		echo '</form>';

		$test_url = wp_nonce_url( add_query_arg( 'action', 'protech_test_brevo_connection', admin_url( 'admin-post.php' ) ), 'protech_test_brevo_connection' );
		echo '<p><a class="button" href="' . esc_url( $test_url ) . '">' . esc_html__( 'Test Brevo connection', 'protech-wholesale' ) . '</a></p>';
	}

	public function handle_test_brevo_connection(): void {
		check_admin_referer( 'protech_test_brevo_connection' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$result = ( new BrevoClient() )->get_account();

		if ( $result['ok'] ) {
			$email = (string) ( $result['data']['email'] ?? '' );
			AdminStash::stash( 'brevo_test', array( 'ok' => true, 'message' => sprintf( /* translators: %s: Brevo account email. */ __( 'Connected as %s.', 'protech-wholesale' ), $email ) ) );
		} else {
			AdminStash::stash( 'brevo_test', array( 'ok' => false, 'message' => sprintf( /* translators: %s: error message. */ __( 'Could not connect to Brevo: %s', 'protech-wholesale' ), $result['error'] ) ) );
		}

		wp_safe_redirect( MessagingTab::url( 'settings' ) );
		exit;
	}
}
