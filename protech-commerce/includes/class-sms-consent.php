<?php
/**
 * SMS/email consent: phone normalization, the two separate SMS consents
 * (order-update texts and marketing/reorder-reminder texts — kept apart
 * because carriers require a customer to have specifically agreed to
 * marketing messages, while a transactional "your order shipped" text is
 * a service message), an append-only consent record for every change
 * (with the exact wording shown, so it can be produced as proof of
 * opt-in for Brevo's toll-free number verification), and the gating
 * checks every send re-runs.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SmsConsent
 */
class SmsConsent {

	public const META_PHONE             = '_protech_wholesale_sms_phone';
	public const META_SMS_TRANSACTIONAL = '_protech_wholesale_sms_transactional'; // 'yes' | 'no' | ''.
	public const META_SMS_MARKETING     = '_protech_wholesale_sms_marketing';     // 'yes' | 'no' | ''.
	public const META_EMAIL_MARKETING   = '_protech_wholesale_email_marketing';   // '' (allowed) | 'no'.
	public const META_CONSENT_LOG       = '_protech_wholesale_consent_log';

	public const SOURCE_APPLICATION_FORM = 'application_form';
	public const SOURCE_MY_ACCOUNT       = 'my_account';
	public const SOURCE_ADMIN            = 'admin';
	public const SOURCE_UNSUBSCRIBE_LINK = 'unsubscribe_link';
	public const SOURCE_BREVO_STOP       = 'brevo_stop';

	public function register_hooks(): void {
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ), 20 );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ), 20 );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
		add_action( 'protech_wholesale_application_submitted', array( $this, 'on_application_submitted' ), 10, 2 );
	}

	/**
	 * Digits-and-plus only; a bare 10-digit US number gets +1, an
	 * 11-digit number starting with 1 gets a +. Anything else that
	 * doesn't already look like a full international number is rejected
	 * rather than guessed at.
	 */
	public static function normalize_phone( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		$has_plus = str_starts_with( $raw, '+' );
		$digits   = preg_replace( '/\D/', '', $raw ) ?? '';

		if ( $has_plus && strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) {
			return '+' . $digits;
		}

		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}

		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
			return '+' . $digits;
		}

		return '';
	}

	public static function phone_for( int $user_id ): string {
		$stored = self::normalize_phone( (string) get_user_meta( $user_id, self::META_PHONE, true ) );

		if ( '' !== $stored ) {
			return $stored;
		}

		$billing = self::normalize_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) );

		if ( '' !== $billing ) {
			return $billing;
		}

		return self::normalize_phone( (string) get_user_meta( $user_id, '_protech_wholesale_app_phone', true ) );
	}

	/**
	 * @return array{phone: string, sms_transactional: string, sms_marketing: string, email_marketing: string}
	 */
	public static function state( int $user_id ): array {
		return array(
			'phone'              => self::phone_for( $user_id ),
			'sms_transactional'  => (string) get_user_meta( $user_id, self::META_SMS_TRANSACTIONAL, true ),
			'sms_marketing'      => (string) get_user_meta( $user_id, self::META_SMS_MARKETING, true ),
			'email_marketing'    => (string) get_user_meta( $user_id, self::META_EMAIL_MARKETING, true ),
		);
	}

	/**
	 * Only meta that actually changes is written, logged, or acted on —
	 * a customer re-saving the Notifications form without touching
	 * anything must never write a fresh consent-log entry, and must
	 * never re-trigger the opt-in confirmation text or a Brevo contact
	 * sync for a consent they already had.
	 *
	 * @param array{phone?: string, sms_transactional?: bool, sms_marketing?: bool, email_marketing?: bool} $changes
	 */
	public static function record( int $user_id, array $changes, string $source, string $note = '', int $recorded_by = 0 ): void {
		$current = self::state( $user_id );
		$applied = array();

		if ( array_key_exists( 'phone', $changes ) ) {
			$normalized = self::normalize_phone( (string) $changes['phone'] );

			if ( '' !== $normalized && $normalized !== $current['phone'] ) {
				update_user_meta( $user_id, self::META_PHONE, $normalized );
				$applied['phone'] = $normalized;
			}
		}

		$sms_marketing_granted = false;

		if ( array_key_exists( 'sms_transactional', $changes ) ) {
			$value = $changes['sms_transactional'] ? 'yes' : 'no';
			$prev  = '' !== $current['sms_transactional'] ? $current['sms_transactional'] : 'no';

			if ( $value !== $prev ) {
				update_user_meta( $user_id, self::META_SMS_TRANSACTIONAL, $value );
				$applied['sms_transactional'] = $value;
			}
		}

		if ( array_key_exists( 'sms_marketing', $changes ) ) {
			$value = $changes['sms_marketing'] ? 'yes' : 'no';
			$prev  = '' !== $current['sms_marketing'] ? $current['sms_marketing'] : 'no';

			if ( $value !== $prev ) {
				update_user_meta( $user_id, self::META_SMS_MARKETING, $value );
				$applied['sms_marketing'] = $value;
				$sms_marketing_granted    = 'yes' === $value;
			}
		}

		if ( array_key_exists( 'email_marketing', $changes ) ) {
			$value = $changes['email_marketing'] ? '' : 'no';

			if ( $value !== $current['email_marketing'] ) {
				update_user_meta( $user_id, self::META_EMAIL_MARKETING, $value );
				$applied['email_marketing'] = $value;
			}
		}

		if ( empty( $applied ) ) {
			return;
		}

		$log   = get_user_meta( $user_id, self::META_CONSENT_LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'          => current_time( 'mysql', true ),
			'source'      => $source,
			'ip'          => self::client_ip(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'changes'     => $applied,
			'wording'     => self::wording(),
			'note'        => $note,
			'recorded_by' => $recorded_by,
		);

		// Bounded, so a very active account's history can't grow forever.
		if ( count( $log ) > 200 ) {
			$log = array_slice( $log, -200 );
		}

		update_user_meta( $user_id, self::META_CONSENT_LOG, $log );

		if ( $sms_marketing_granted && '' !== self::phone_for( $user_id ) ) {
			$user = get_userdata( $user_id );

			if ( $user && BrevoClient::is_configured() ) {
				AutomationRunner::schedule_sync_contact( $user_id );
			}

			if ( MessagingSettings::sms_optin_confirmation() ) {
				self::queue_optin_confirmation( $user_id );
			}
		}
	}

	private static function queue_optin_confirmation( int $user_id ): void {
		$id = MessageLog::enqueue(
			array(
				'user_id'  => $user_id,
				'channel'  => MessageLog::CHANNEL_SMS,
				'kind'     => MessageLog::KIND_AUTO,
				'category' => MessageLog::CATEGORY_TRANSACTIONAL,
				'rule_id'  => 'sms_optin_confirmation',
				'anchor'   => 'optin:' . time(),
			)
		);

		if ( $id ) {
			AutomationRunner::schedule_delivery( array( $id ) );
		}
	}

	public static function revoke( int $user_id, string $source ): void {
		self::record( $user_id, array( 'sms_marketing' => false, 'sms_transactional' => false, 'email_marketing' => false ), $source );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function records( int $user_id ): array {
		$log = get_user_meta( $user_id, self::META_CONSENT_LOG, true );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * @return array{ok: bool, reason: string}
	 */
	public static function can_receive_sms( int $user_id, string $category ): array {
		$state = self::state( $user_id );

		if ( '' === $state['phone'] ) {
			return array( 'ok' => false, 'reason' => 'no_phone' );
		}

		if ( MessageLog::CATEGORY_MARKETING === $category ) {
			if ( 'yes' !== $state['sms_marketing'] ) {
				return array( 'ok' => false, 'reason' => 'no_consent' );
			}
		} elseif ( 'yes' !== $state['sms_transactional'] ) {
			return array( 'ok' => false, 'reason' => 'no_consent' );
		}

		$blacklisted = self::is_sms_blacklisted( $user_id );

		if ( MessageLog::CATEGORY_MARKETING === $category ) {
			// Fail closed: an unknown blacklist state is treated the same
			// as "blacklisted" for a marketing message, since sending one
			// against a real STOP would be the worse mistake.
			if ( null === $blacklisted || true === $blacklisted ) {
				if ( true === $blacklisted ) {
					self::record( $user_id, array( 'sms_marketing' => false ), self::SOURCE_BREVO_STOP );
				}

				return array( 'ok' => false, 'reason' => null === $blacklisted ? 'consent_unverified' : 'unsubscribed' );
			}
		} elseif ( true === $blacklisted ) {
			return array( 'ok' => false, 'reason' => 'unsubscribed' );
		}

		return array( 'ok' => true, 'reason' => '' );
	}

	/**
	 * @return array{ok: bool, reason: string}
	 */
	public static function can_receive_email( int $user_id, string $category ): array {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return array( 'ok' => false, 'reason' => 'no_email' );
		}

		if ( MessageLog::CATEGORY_MARKETING === $category ) {
			if ( 'no' === get_user_meta( $user_id, self::META_EMAIL_MARKETING, true ) ) {
				return array( 'ok' => false, 'reason' => 'unsubscribed' );
			}

			$blacklisted = self::is_email_blacklisted( $user_id );

			if ( true === $blacklisted ) {
				self::record( $user_id, array( 'email_marketing' => false ), self::SOURCE_BREVO_STOP );
				return array( 'ok' => false, 'reason' => 'unsubscribed' );
			}
		}

		return array( 'ok' => true, 'reason' => '' );
	}

	private static function is_sms_blacklisted( int $user_id ): ?bool {
		if ( ! BrevoClient::is_configured() ) {
			return null;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		return ( new BrevoClient() )->is_sms_blacklisted( $user->user_email );
	}

	private static function is_email_blacklisted( int $user_id ): ?bool {
		if ( ! BrevoClient::is_configured() ) {
			return null;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		return ( new BrevoClient() )->is_email_blacklisted( $user->user_email );
	}

	/**
	 * The exact wording a customer sees next to an SMS opt-in checkbox
	 * ("order update" variant), and its marketing counterpart.
	 *
	 * @return array{transactional: string, marketing: string}
	 */
	public static function wording_variants(): array {
		$template = MessagingSettings::consent_wording_template();
		$replace  = array(
			'{brand}'        => MessagingSettings::brand(),
			'{privacy_url}'  => MessagingSettings::privacy_url(),
			'{terms_url}'    => MessagingSettings::terms_url(),
		);

		$transactional = strtr( str_replace( '{kind}', __( 'order update', 'protech-wholesale' ), $template ), $replace );
		$marketing     = strtr( str_replace( '{kind}', __( 'marketing and reorder reminder', 'protech-wholesale' ), $template ), $replace );

		return array(
			'transactional' => $transactional,
			'marketing'     => $marketing,
		);
	}

	/** The marketing variant, used as the "exact wording shown" on a consent record when the source doesn't say which checkbox. */
	public static function wording(): string {
		return self::wording_variants()['marketing'];
	}

	private static function client_ip(): string {
		if ( class_exists( '\WC_Geolocation' ) ) {
			return \WC_Geolocation::get_ip_address();
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Reads the two consent checkboxes + phone from a submitted wholesale
	 * application (native form or a third-party adapter — see
	 * ApplicationForm::FIELD_KEYS).
	 *
	 * @param array<string, mixed> $data Normalized FIELD_KEYS => value.
	 */
	public function on_application_submitted( int $user_id, array $data ): void {
		$changes = array();

		if ( array_key_exists( 'phone', $data ) && '' !== (string) $data['phone'] ) {
			$changes['phone'] = (string) $data['phone'];
		}

		if ( ! empty( $data['sms_transactional_consent'] ) ) {
			$changes['sms_transactional'] = true;
		}

		if ( ! empty( $data['sms_marketing_consent'] ) ) {
			$changes['sms_marketing'] = true;
		}

		if ( ! empty( $changes ) ) {
			self::record( $user_id, $changes, self::SOURCE_APPLICATION_FORM );
		}
	}

	/**
	 * @return string[] CSV column headers.
	 */
	public static function csv_headers(): array {
		return array( 'user_id', 'email', 'phone', 'recorded_at_gmt', 'source', 'ip', 'sms_marketing', 'sms_transactional', 'email_marketing', 'wording', 'note', 'recorded_by' );
	}

	/**
	 * One CSV row per consent record, across every wholesale customer.
	 *
	 * @return \Generator<int, array<int, string>>
	 */
	public static function all_records_csv_rows(): \Generator {
		$user_ids = get_users(
			array(
				'role'   => Roles::CUSTOMER,
				'fields' => 'ID',
			)
		);

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$user    = get_userdata( $user_id );
			$phone   = self::phone_for( $user_id );

			foreach ( self::records( $user_id ) as $record ) {
				$changes = (array) ( $record['changes'] ?? array() );

				yield array(
					(string) $user_id,
					$user ? $user->user_email : '',
					$phone,
					(string) ( $record['at'] ?? '' ),
					(string) ( $record['source'] ?? '' ),
					(string) ( $record['ip'] ?? '' ),
					array_key_exists( 'sms_marketing', $changes ) ? (string) $changes['sms_marketing'] : '',
					array_key_exists( 'sms_transactional', $changes ) ? (string) $changes['sms_transactional'] : '',
					array_key_exists( 'email_marketing', $changes ) ? (string) $changes['email_marketing'] : '',
					(string) ( $record['wording'] ?? '' ),
					(string) ( $record['note'] ?? '' ),
					(string) ( $record['recorded_by'] ?? '' ),
				);
			}
		}
	}

	/**
	 * A "Messaging" section on the customer's profile: phone, both SMS
	 * consents, email marketing, and a note field the save handler
	 * requires before recording a change made here.
	 */
	public function render_profile_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Roles::is_wholesale_customer( $user->ID ) ) {
			return;
		}

		$state   = self::state( $user->ID );
		$records = array_reverse( self::records( $user->ID ) );

		wp_nonce_field( 'protech_wholesale_sms_consent', 'protech_wholesale_sms_consent_nonce' );
		?>
		<h2><?php esc_html_e( 'Messaging', 'protech-wholesale' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="protech_sms_phone"><?php esc_html_e( 'SMS number', 'protech-wholesale' ); ?></label></th>
				<td><input type="tel" id="protech_sms_phone" name="protech_sms_phone" value="<?php echo esc_attr( $state['phone'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Consent', 'protech-wholesale' ); ?></th>
				<td>
					<label><input type="checkbox" name="protech_sms_transactional" value="1" <?php checked( 'yes', $state['sms_transactional'] ); ?> /> <?php esc_html_e( 'Order-update texts', 'protech-wholesale' ); ?></label><br />
					<label><input type="checkbox" name="protech_sms_marketing" value="1" <?php checked( 'yes', $state['sms_marketing'] ); ?> /> <?php esc_html_e( 'Marketing / reorder-reminder texts', 'protech-wholesale' ); ?></label><br />
					<label><input type="checkbox" name="protech_email_marketing" value="1" <?php checked( 'no' !== $state['email_marketing'] ); ?> /> <?php esc_html_e( 'Marketing emails', 'protech-wholesale' ); ?></label>
					<p class="description"><?php esc_html_e( 'Changing any of these here requires a note below and is recorded as proof of consent.', 'protech-wholesale' ); ?></p>
					<p>
						<label for="protech_sms_consent_note"><?php esc_html_e( 'Note (required to save a change above)', 'protech-wholesale' ); ?></label><br />
						<input type="text" id="protech_sms_consent_note" name="protech_sms_consent_note" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. "Customer asked by phone to opt in to texts."', 'protech-wholesale' ); ?>" />
					</p>
				</td>
			</tr>
			<?php if ( ! empty( $records ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Consent history', 'protech-wholesale' ); ?></th>
					<td>
						<table class="widefat striped" style="max-width:700px;">
							<thead><tr>
								<th><?php esc_html_e( 'Date', 'protech-wholesale' ); ?></th>
								<th><?php esc_html_e( 'Source', 'protech-wholesale' ); ?></th>
								<th><?php esc_html_e( 'Change', 'protech-wholesale' ); ?></th>
								<th><?php esc_html_e( 'Note', 'protech-wholesale' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( array_slice( $records, 0, 20 ) as $record ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) ( $record['at'] ?? '' ) ) ); ?></td>
									<td><?php echo esc_html( (string) ( $record['source'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( wp_json_encode( $record['changes'] ?? array() ) ?: '' ); ?></td>
									<td><?php echo esc_html( (string) ( $record['note'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['protech_wholesale_sms_consent_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_sms_consent_nonce'] ) ), 'protech_wholesale_sms_consent' )
		) {
			return;
		}

		if ( ! Roles::is_wholesale_customer( $user_id ) ) {
			return;
		}

		$phone = isset( $_POST['protech_sms_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['protech_sms_phone'] ) ) : '';

		$new_state = array(
			'phone'             => $phone,
			'sms_transactional' => ! empty( $_POST['protech_sms_transactional'] ),
			'sms_marketing'     => ! empty( $_POST['protech_sms_marketing'] ),
			'email_marketing'   => ! empty( $_POST['protech_email_marketing'] ),
		);

		$current = self::state( $user_id );
		$changed = self::normalize_phone( $phone ) !== $current['phone']
			|| ( $new_state['sms_transactional'] ? 'yes' : 'no' ) !== ( '' !== $current['sms_transactional'] ? $current['sms_transactional'] : 'no' )
			|| ( $new_state['sms_marketing'] ? 'yes' : 'no' ) !== ( '' !== $current['sms_marketing'] ? $current['sms_marketing'] : 'no' )
			|| ( $new_state['email_marketing'] ? '' : 'no' ) !== $current['email_marketing'];

		if ( ! $changed ) {
			return;
		}

		$note = isset( $_POST['protech_sms_consent_note'] ) ? sanitize_text_field( wp_unslash( $_POST['protech_sms_consent_note'] ) ) : '';

		if ( '' === $note ) {
			add_action(
				'user_profile_update_errors',
				static function ( \WP_Error $errors ) {
					$errors->add( 'protech_consent_note_required', __( 'A note is required to change a customer\'s messaging consent.', 'protech-wholesale' ) );
				}
			);
			return;
		}

		self::record( $user_id, $new_state, self::SOURCE_ADMIN, $note, get_current_user_id() );
	}
}
