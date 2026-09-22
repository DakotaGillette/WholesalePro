<?php
/**
 * Messaging → Compliance: the SMS opt-in wording in use, which rules are
 * configured, consent on file by scope, a consent-record CSV export, and a
 * checklist for Brevo toll-free verification.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ComplianceScreen
 */
class ComplianceScreen {

	public function register_hooks(): void {
		add_action( 'admin_post_protech_export_consent', array( $this, 'handle_export_consent' ) );
	}

	public static function render(): void {
		$wording = SmsConsent::wording_variants();

		echo '<h2>' . esc_html__( 'SMS opt-in wording', 'protech-wholesale' ) . '</h2>';
		echo '<table class="widefat" style="max-width:800px;"><tbody>';
		echo '<tr><th style="width:180px;">' . esc_html__( 'Order-update texts', 'protech-wholesale' ) . '</th><td>' . esc_html( $wording['transactional'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Marketing texts', 'protech-wholesale' ) . '</th><td>' . esc_html( $wording['marketing'] ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: 1: application form link, 2: notifications preferences description. */
				__( 'Shown at signup on the <a href="%1$s" target="_blank" rel="noopener">wholesale application form</a>, and self-service under My Account &rarr; Notifications for an existing customer.', 'protech-wholesale' ),
				esc_url( home_url( '/wholesale-application' ) )
			)
		) . '</p>';

		echo '<h2>' . esc_html__( 'Message types configured', 'protech-wholesale' ) . '</h2>';
		$rules = Automations::enabled();

		if ( empty( $rules ) ) {
			echo '<p>' . esc_html__( 'No automation rules are enabled yet.', 'protech-wholesale' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:800px;"><thead><tr><th>' . esc_html__( 'Rule', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Category', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Sample SMS', 'protech-wholesale' ) . '</th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				$sample = ! empty( $rule['sms']['body'] ) ? MessageTransport::finalize_sms_text( $rule['sms']['body'], $rule['category'] ) : '—';
				echo '<tr><td>' . esc_html( $rule['name'] ) . '</td><td>' . esc_html( $rule['category'] ) . '</td><td>' . esc_html( $sample ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Consent on file', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'Email and texts are treated differently on purpose. Marketing email may go to any past customer, always with an unsubscribe link and your postal address, until they unsubscribe. Marketing texts go only to people who have said yes to them, and that is never assumed from being a customer.', 'protech-wholesale' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:800px;"><thead><tr><th></th><th>' . esc_html__( 'Customers', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Can get marketing email', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Unsubscribed', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Said yes to marketing texts', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Said yes to order-update texts', 'protech-wholesale' ) . '</th></tr></thead><tbody>';

		foreach ( array( Audience::SCOPE_WHOLESALE => __( 'Wholesale', 'protech-wholesale' ), Audience::SCOPE_RETAIL => __( 'Retail', 'protech-wholesale' ) ) as $scope => $scope_label ) {
			$customers = Audience::pool( $scope );
			$marketing = $transactional = $email_optout = 0;

			foreach ( $customers as $id ) {
				$state = SmsConsent::state( (int) $id );

				if ( 'yes' === $state['sms_marketing'] ) {
					++$marketing;
				}

				if ( 'yes' === $state['sms_transactional'] ) {
					++$transactional;
				}

				if ( 'no' === $state['email_marketing'] ) {
					++$email_optout;
				}
			}

			printf(
				'<tr><th>%s</th><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
				esc_html( $scope_label ),
				count( $customers ),
				count( $customers ) - $email_optout,
				$email_optout,
				$marketing,
				$transactional
			);
		}

		echo '</tbody></table>';

		$export_url = wp_nonce_url( add_query_arg( 'action', 'protech_export_consent', admin_url( 'admin-post.php' ) ), 'protech_export_consent' );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download consent records (CSV)', 'protech-wholesale' ) . '</a></p>';

		$brevo_check     = SetupChecks::brevo_connected();
		$privacy_check   = SetupChecks::privacy_policy_mentions_sms();

		echo '<h2>' . esc_html__( 'Before submitting to Brevo for toll-free verification', 'protech-wholesale' ) . '</h2><ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . ( false === $brevo_check ? '⚠️ ' : '✅ ' ) . esc_html__( 'Brevo is connected.', 'protech-wholesale' ) . '</li>';
		echo '<li>' . ( false === $privacy_check ? '⚠️ ' : '✅ ' ) . esc_html__( 'Your privacy policy and terms mention SMS, message frequency, rates, and STOP/HELP.', 'protech-wholesale' ) . '</li>';
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Suggested privacy policy / terms text', 'protech-wholesale' ) . '</h3>';
		echo '<textarea readonly rows="10" style="width:100%;max-width:800px;" onclick="this.select();">' . esc_textarea( self::suggested_privacy_text() ) . '</textarea>';
	}

	private static function suggested_privacy_text(): string {
		$brand = MessagingSettings::brand();

		return sprintf(
			/* translators: %s: brand/site name. */
			__(
				"SMS Terms: By providing your phone number and opting in, you agree to receive order-update and/or marketing text messages from %1\$s. Message frequency varies. Message and data rates may apply. Reply STOP to opt out at any time, or HELP for help. Your mobile information will not be shared with third parties or affiliates for marketing or promotional purposes. Consent to receive texts is not a condition of any purchase.",
				'protech-wholesale'
			),
			$brand
		);
	}

	public function handle_export_consent(): void {
		check_admin_referer( 'protech_export_consent' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wholesale-sms-consent-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, SmsConsent::csv_headers() );

		foreach ( SmsConsent::all_records_csv_rows() as $row ) {
			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}
}
