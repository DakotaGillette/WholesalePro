<?php
/**
 * Messaging → Settings: three tabs (General, Sending, Compliance), each a
 * WooCommerce Settings API form over its slice of
 * MessagingSettings::get_fields(). Sending adds the "Test Brevo connection"
 * button, and Compliance shows ComplianceScreen's report below its form.
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

	/**
	 * The Settings tabs (3.7.0, like MailPoet's Basics / Send With / Advanced),
	 * each listing the MessagingSettings::get_fields() sections it shows, by
	 * the section's title id.
	 *
	 * @return array<string, array{label: string, sections: string[]}>
	 */
	public static function tabs(): array {
		return array(
			'general'    => array(
				'label'    => __( 'General', 'protech-wholesale' ),
				'sections' => array( 'protech_wholesale_msg_settings_automations', 'protech_wholesale_msg_settings_design' ),
			),
			'sending'    => array(
				'label'    => __( 'Sending', 'protech-wholesale' ),
				'sections' => array( 'protech_wholesale_msg_settings_sending', 'protech_wholesale_msg_settings_brevo', 'protech_wholesale_msg_settings_sms', 'protech_wholesale_msg_settings_rules' ),
			),
			'compliance' => array(
				'label'    => __( 'Compliance', 'protech-wholesale' ),
				'sections' => array( 'protech_wholesale_msg_settings_compliance' ),
			),
		);
	}

	/**
	 * One tab's slice of the fields, in the tab's section order. Saving only
	 * this slice matters: woocommerce_update_options() sets every checkbox it
	 * is given and does not find in the POST to "no".
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_for_tab( array $fields, string $tab ): array {
		$by_section = array();
		$section    = '';

		foreach ( $fields as $field ) {
			if ( 'title' === ( $field['type'] ?? '' ) ) {
				$section = (string) ( $field['id'] ?? '' );
			}

			$by_section[ $section ][] = $field;
		}

		$slice = array();

		foreach ( self::tabs()[ $tab ]['sections'] ?? array() as $id ) {
			$slice = array_merge( $slice, $by_section[ $id ] ?? array() );
		}

		return $slice;
	}

	public static function render(): void {
		$settings = new MessagingSettings();
		$tabs     = self::tabs();
		$tab      = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		$fields = self::fields_for_tab( $settings->get_fields(), $tab );

		if ( isset( $_POST['protech_wholesale_msg_settings_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_msg_settings_nonce'] ) ), 'protech_wholesale_save_msg_settings' )
		) {
			woocommerce_update_options( $fields );
			echo '<div class="updated notice"><p>' . esc_html__( 'Settings saved.', 'protech-wholesale' ) . '</p></div>';
			Logger::info( 'Messaging settings (' . $tab . ') updated by user #' . get_current_user_id() );
		}

		echo '<nav class="nav-tab-wrapper wp-clearfix">';

		foreach ( $tabs as $key => $info ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( MessagingTab::url( 'settings', array( 'tab' => $key ) ) ),
				$tab === $key ? ' nav-tab-active' : '',
				esc_html( $info['label'] )
			);
		}

		echo '</nav>';

		$test = AdminStash::unstash( 'brevo_test' );

		if ( null !== $test ) {
			$class = $test['ok'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $test['message'] ) . '</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_save_msg_settings', 'protech_wholesale_msg_settings_nonce' );
		woocommerce_admin_fields( $fields );
		submit_button();
		echo '</form>';

		if ( 'sending' === $tab ) {
			$test_url = wp_nonce_url( add_query_arg( 'action', 'protech_test_brevo_connection', admin_url( 'admin-post.php' ) ), 'protech_test_brevo_connection' );
			echo '<p><a class="button" href="' . esc_url( $test_url ) . '">' . esc_html__( 'Test Brevo connection', 'protech-wholesale' ) . '</a></p>';
		}

		if ( 'compliance' === $tab ) {
			echo '<hr />';
			ComplianceScreen::render();
		}
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

		wp_safe_redirect( MessagingTab::url( 'settings', array( 'tab' => 'sending' ) ) );
		exit;
	}
}
