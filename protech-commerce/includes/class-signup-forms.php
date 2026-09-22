<?php
/**
 * Embeddable signup forms: `[protech_signup id="f_xxxxxxxx"]` renders one,
 * a submission starts double opt-in (an unconfirmed contact plus a
 * confirmation email), and confirming subscribes it for real. The public
 * submit route carries no nonce on purpose (see handle_submit()): a
 * page-cache would otherwise serve one stale nonce to every visitor,
 * breaking the form for everyone but whoever the cache first served.
 *
 * Forms live in a single non-autoloaded option, the same pattern
 * EmailTemplates and Campaigns already use.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SignupForms
 */
class SignupForms {

	public const OPTION     = 'protech_wholesale_signup_forms';
	public const MAX_STORED = 50;

	public const SUBMIT_ACTION  = 'protech_signup_submit';
	public const CONFIRM_ACTION = 'protech_confirm_subscription';

	/** Per-IP submissions allowed in the rate-limit window: generous for a real visitor, tight for a bot loop. */
	private const RATE_LIMIT      = 5;
	private const RATE_LIMIT_SECS = 10 * MINUTE_IN_SECONDS;

	public function register_hooks(): void {
		add_action( 'admin_post_nopriv_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_' . self::CONFIRM_ACTION, array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, array( $this, 'handle_confirm' ) );
		add_shortcode( 'protech_signup', array( $this, 'shortcode' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'id'              => '',
			'name'            => '',
			'show_name_field' => true,
			'consent_text'    => __( "I'd like to receive marketing emails. I can unsubscribe at any time.", 'protech-wholesale' ),
			'success_message' => __( "Almost there. Check your email and click the confirmation link.", 'protech-wholesale' ),
			'created_at'      => 0,
			'updated_at'      => 0,
		);
	}

	// -----------------------------------------------------------------
	// Storage (mirrors EmailTemplates: an option, not a post type).
	// -----------------------------------------------------------------

	/** @return array<string, array<string, mixed>> id => form, newest first. */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$forms = array();

		foreach ( $stored as $id => $form ) {
			if ( is_array( $form ) ) {
				$forms[ (string) $id ] = array_merge( self::defaults(), $form, array( 'id' => (string) $id ) );
			}
		}

		uasort( $forms, static fn( array $a, array $b ): int => (int) $b['updated_at'] <=> (int) $a['updated_at'] );

		return $forms;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		return self::all()[ $id ] ?? null;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{form: array<string, mixed>, errors: string[]}
	 */
	public static function validate( array $input ): array {
		$errors   = array();
		$existing = self::get( (string) ( $input['id'] ?? '' ) );
		$form     = null !== $existing ? $existing : self::defaults();

		$form['name'] = EmailBlocks::text( $input['name'] ?? '', 120 );

		if ( '' === $form['name'] ) {
			$errors[] = __( 'Give the form a name.', 'protech-wholesale' );
		}

		$form['show_name_field'] = EmailBlocks::flag( $input['show_name_field'] ?? false );
		$form['consent_text']    = EmailBlocks::text( $input['consent_text'] ?? $form['consent_text'], 300 );
		$form['success_message'] = EmailBlocks::text( $input['success_message'] ?? $form['success_message'], 300 );

		if ( '' === $form['consent_text'] ) {
			$errors[] = __( 'The consent wording cannot be empty: it is what a subscriber agreed to.', 'protech-wholesale' );
		}

		return array(
			'form'   => $form,
			'errors' => $errors,
		);
	}

	/** @param array<string, mixed> $form */
	public static function save( array $form ): string {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$now    = time();
		$id     = (string) ( $form['id'] ?? '' );

		if ( ! preg_match( '/^f_[a-z0-9]{6,12}$/', $id ) ) {
			$id               = 'f_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$form['created_at'] = $now;
		}

		$form['id']         = $id;
		$form['updated_at'] = $now;
		$stored[ $id ]      = $form;

		if ( count( $stored ) > self::MAX_STORED ) {
			uasort( $stored, static fn( $a, $b ): int => (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ) );
			$stored = array_slice( $stored, 0, self::MAX_STORED, true );
		}

		update_option( self::OPTION, $stored, false );

		return $id;
	}

	public static function delete( string $id ): void {
		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && isset( $stored[ $id ] ) ) {
			unset( $stored[ $id ] );
			update_option( self::OPTION, $stored, false );
		}
	}

	// -----------------------------------------------------------------
	// The public-facing form.
	// -----------------------------------------------------------------

	/** @param array<string, mixed> $atts */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), (array) $atts, 'protech_signup' );
		$form = self::get( sanitize_text_field( (string) $atts['id'] ) );

		if ( null === $form ) {
			return current_user_can( 'manage_woocommerce' ) ? '<p>' . esc_html__( 'Signup form not found.', 'protech-wholesale' ) . '</p>' : '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which message to show.
		$notice = sanitize_key( wp_unslash( $_GET['protech_signup'] ?? '' ) );

		ob_start();
		wc_get_template(
			'signup-form.php',
			array(
				'form'          => $form,
				'notice'        => $notice,
				'submit_url'    => admin_url( 'admin-post.php' ),
				'redirect_to'   => esc_url_raw( home_url( add_query_arg( array() ) ) ),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);

		return (string) ob_get_clean();
	}

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** True once an IP has submitted RATE_LIMIT times within the window; never reveals this to the caller. */
	private static function rate_limited(): bool {
		$ip = self::client_ip();

		if ( '' === $ip ) {
			return false;
		}

		$key   = 'protech_signup_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_SECS );

		return false;
	}

	/**
	 * A submission: no nonce (see the class docblock), a honeypot field a
	 * real visitor never sees or fills in, and a per-IP rate limit. Either
	 * kind of abuse is answered with the same "ok" redirect a real
	 * submission gets, so neither ever learns it was refused.
	 */
	public function handle_submit(): void {
		$form_id     = sanitize_text_field( wp_unslash( $_POST['form_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see the class docblock.
		$redirect_to = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back        = '' !== $redirect_to ? $redirect_to : home_url( '/' );
		$form        = self::get( $form_id );

		if ( null === $form ) {
			wp_safe_redirect( $back );
			exit;
		}

		$honeypot = trim( sanitize_text_field( wp_unslash( $_POST['protech_hp'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' !== $honeypot || self::rate_limited() ) {
			wp_safe_redirect( add_query_arg( 'protech_signup', 'ok', $back ) );
			exit;
		}

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( array( 'protech_signup' => 'error', 'id' => $form_id ), $back ) );
			exit;
		}

		$contact_id = Contacts::start_confirmation(
			$email,
			array( 'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$contact = Contacts::get( $contact_id );

		if ( null !== $contact && Contacts::STATUS_UNCONFIRMED === $contact['status'] ) {
			self::send_confirmation( $contact );
		}

		wp_safe_redirect( add_query_arg( array( 'protech_signup' => 'ok', 'id' => $form_id ), $back ) );
		exit;
	}

	/**
	 * A plain, branded confirmation email — not a designable template
	 * bound to a slot, unlike the welcome/application emails: those
	 * address a real WP_User, and a signup can be a guest with no account
	 * at all, so the existing per-user render context does not fit here.
	 * Logged exactly like the other lifecycle emails.
	 *
	 * @param array<string, mixed> $contact
	 */
	private static function send_confirmation( array $contact ): void {
		$contact_id = (int) $contact['id'];
		$brand      = MessagingSettings::brand();
		$link       = add_query_arg(
			array(
				'action' => self::CONFIRM_ACTION,
				'c'      => $contact_id,
				't'      => Contacts::confirmation_token( $contact_id ),
			),
			admin_url( 'admin-post.php' )
		);

		$body  = '<p>' . esc_html__( 'One more step: confirm your email to start receiving updates.', 'protech-wholesale' ) . '</p>';
		$body .= '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 18px;background:#000;color:#fff;text-decoration:none;border-radius:8px;">' . esc_html__( 'Confirm my email', 'protech-wholesale' ) . '</a></p>';

		/* translators: %s: brand/store name. */
		$subject = sprintf( __( 'Confirm your email for %s', 'protech-wholesale' ), $brand );

		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $subject, $body );
		$sent    = $mailer->send( (string) $contact['email'], $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

		$log_id = MessageLog::enqueue(
			array(
				'user_id'   => (int) $contact['user_id'],
				'channel'   => MessageLog::CHANNEL_EMAIL,
				'kind'      => MessageLog::KIND_LIFECYCLE,
				'category'  => MessageLog::CATEGORY_TRANSACTIONAL,
				'rule_id'   => 'lifecycle:signup_confirmation',
				'anchor'    => 'signup_confirmation:' . $contact_id . ':' . microtime( true ),
				'recipient' => (string) $contact['email'],
				'subject'   => $subject,
			)
		);

		if ( ! $log_id ) {
			return;
		}

		MessageLog::claim( $log_id );
		MessageLog::finish(
			$log_id,
			$sent ? MessageLog::STATUS_SENT : MessageLog::STATUS_FAILED,
			array(
				'provider'  => 'wc_mailer',
				'recipient' => (string) $contact['email'],
				'subject'   => $subject,
				'error'     => $sent ? '' : __( 'The site\'s mailer reported the send failed.', 'protech-wholesale' ),
			)
		);
	}

	/** The confirmation link itself: no nonce, since it is emailed, not submitted from a page a cache could serve stale. */
	public function handle_confirm(): void {
		$contact_id = absint( $_GET['c'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the HMAC token below is the real check.
		$token      = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$confirmed = Contacts::confirm( $contact_id, $token, __( 'Confirmed via the double opt-in email link.', 'protech-wholesale' ) );

		if ( 0 === $confirmed ) {
			wp_die(
				esc_html__( 'This confirmation link is not valid. It may have already been used.', 'protech-wholesale' ),
				esc_html__( 'Link not valid', 'protech-wholesale' ),
				array( 'response' => 200 )
			);
		}

		wp_die(
			wp_kses_post(
				sprintf(
					/* translators: %s: shop link. */
					__( '<p>You\'re subscribed. Thanks for confirming.</p><p><a href="%s">Back to the shop</a></p>', 'protech-wholesale' ),
					esc_url( wc_get_page_permalink( 'shop' ) )
				)
			),
			esc_html__( 'Subscribed', 'protech-wholesale' ),
			array( 'response' => 200 )
		);
	}
}
