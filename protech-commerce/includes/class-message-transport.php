<?php
/**
 * Turns a claimed message-log row into an actual send: resolves its
 * content and merge-tag context, re-checks consent/quiet-hours one last
 * time (state can change between queuing and delivery), and sends
 * through Brevo — falling back to the site's own WooCommerce mailer for
 * email when Brevo isn't connected. SMS has no fallback; Brevo is the
 * only channel.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageTransport
 */
class MessageTransport {

	/**
	 * @param array<string, mixed> $row A row from MessageLog::claim().
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function deliver( array $row ): array {
		$user_id  = (int) $row['user_id'];
		$channel  = (string) $row['channel'];
		$category = (string) $row['category'];

		$gate = MessageLog::CHANNEL_SMS === $channel
			? SmsConsent::can_receive_sms( $user_id, $category )
			: SmsConsent::can_receive_email( $user_id, $category );

		if ( ! $gate['ok'] ) {
			return self::result( 'skipped', '', '', '', '', '', $gate['reason'], false );
		}

		// A text at 3am is unwelcome regardless of category; hold it for
		// the next quiet-hours window rather than counting it as failed.
		if ( MessageLog::CHANNEL_SMS === $channel && MessagingSettings::in_quiet_hours() ) {
			MessageLog::requeue( (int) $row['id'], MessagingSettings::next_quiet_hours_end() );
			return self::result( 'requeued', '', '', '', '', 'quiet_hours', false );
		}

		$content = Automations::content_for( (string) $row['rule_id'] );

		if ( null === $content ) {
			return self::result( 'failed', '', '', '', __( 'No content is configured for this message.', 'protech-wholesale' ), 'no_content', false );
		}

		$order   = self::order_from_anchor( (string) $row['anchor'] );
		$context = MergeTags::context_for_customer( $user_id, $order );

		if ( MessageLog::CHANNEL_EMAIL === $channel ) {
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				return self::result( 'failed', '', '', '', __( 'Customer account no longer exists.', 'protech-wholesale' ), 'no_user', false );
			}

			$subject = MergeTags::render( (string) ( $content['email']['subject'] ?? '' ), $context, 'subject' );
			$heading = MergeTags::render( (string) ( $content['email']['heading'] ?? '' ), $context, 'subject' );
			$body    = MergeTags::render( (string) ( $content['email']['body'] ?? '' ), $context, 'html' );

			return self::send_email( $user_id, $user->user_email, $subject, $heading, $body, $category, array( (string) $row['rule_id'] ) );
		}

		$sms_body = MergeTags::render( (string) ( $content['sms']['body'] ?? '' ), $context, 'text' );

		return self::send_sms( $user_id, SmsConsent::phone_for( $user_id ), $sms_body, $category );
	}

	/**
	 * Anchors are always either empty or "order:<id>" / "order:<id>:...",
	 * so the event order can be recovered without a dedicated column.
	 */
	private static function order_from_anchor( string $anchor ): ?\WC_Order {
		if ( ! preg_match( '/^order:(\d+)/', $anchor, $matches ) ) {
			return null;
		}

		$order = wc_get_order( (int) $matches[1] );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * @param string[] $tags
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function send_email( int $user_id, string $to, string $subject, string $heading, string $body_html, string $category, array $tags = array() ): array {
		if ( ! is_email( $to ) ) {
			return self::result( 'failed', '', '', $to, $subject, __( 'Not a valid email address.', 'protech-wholesale' ), 'invalid_email', false );
		}

		if ( '' === trim( $subject ) ) {
			$subject = (string) get_bloginfo( 'name' );
		}

		$full_body = $body_html . self::footer_html_for( $user_id, $category );
		$wrapped   = WC()->mailer()->wrap_message( $heading, $full_body );
		$html      = ( new \WC_Email() )->style_inline( $wrapped );

		return self::dispatch( $to, $subject, $html, wp_strip_all_tags( $full_body ), $tags );
	}

	/**
	 * The footer a message of this category must carry, or '' for none: the
	 * one place that decides, so no path can send marketing email without the
	 * unsubscribe link and postal address by forgetting to ask.
	 */
	public static function footer_html_for( int $user_id, string $category ): string {
		return ( MessageLog::CATEGORY_MARKETING === $category && MessagingSettings::email_footer_enabled() )
			? self::email_footer_html( $user_id )
			: '';
	}

	/**
	 * Hands finished HTML and plain text to Brevo, or to the site's own mailer
	 * when Brevo is not connected. Knows nothing about templates, wrappers or
	 * footers: everything upstream has already been decided.
	 *
	 * @param string[] $tags
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function dispatch( string $to, string $subject, string $html, string $text, array $tags = array() ): array {
		if ( BrevoClient::is_configured() ) {
			$payload = array(
				'sender'      => array(
					'name'  => MessagingSettings::from_name(),
					'email' => MessagingSettings::from_email(),
				),
				'to'          => array( array( 'email' => $to ) ),
				'subject'     => $subject,
				'htmlContent' => $html,
				'textContent' => $text,
				'tags'        => array_values( array_unique( array_merge( array( 'protech-wholesale' ), $tags ) ) ),
			);

			$reply_to = MessagingSettings::reply_to();

			if ( '' !== $reply_to ) {
				$payload['replyTo'] = array( 'email' => $reply_to );
			}

			$result = ( new BrevoClient() )->send_email( $payload );

			if ( $result['ok'] ) {
				return self::result( 'sent', 'brevo', (string) ( $result['data']['messageId'] ?? '' ), $to, $subject, '', '', false );
			}

			return self::result( 'failed', 'brevo', '', $to, $subject, $result['error'], '', $result['retryable'] );
		}

		$sent = WC()->mailer()->send( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

		return self::result(
			$sent ? 'sent' : 'failed',
			'wc_mailer',
			'',
			$to,
			$subject,
			$sent ? '' : __( 'The site\'s mail sender rejected or failed to send this message.', 'protech-wholesale' ),
			'',
			false
		);
	}

	/**
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function send_sms( int $user_id, string $to_e164, string $text, string $category ): array {
		if ( '' === $to_e164 ) {
			return self::result( 'skipped', '', '', '', '', '', 'no_phone', false );
		}

		if ( ! BrevoClient::is_configured() ) {
			return self::result( 'failed', '', '', $to_e164, '', __( 'SMS requires Brevo to be connected.', 'protech-wholesale' ), 'sms_unavailable', false );
		}

		$final_text = self::finalize_sms_text( $text, $category );
		$segments   = MergeTags::sms_segments( $final_text );
		$type       = MessageLog::CATEGORY_MARKETING === $category ? 'marketing' : 'transactional';

		$result = ( new BrevoClient() )->send_sms( MessagingSettings::sms_sender(), $to_e164, $final_text, $type, 'protech-wholesale', $segments['unicode'] );

		if ( $result['ok'] ) {
			$provider_id = (string) ( $result['data']['messageId'] ?? ( $result['data']['reference'] ?? '' ) );
			return self::result( 'sent', 'brevo', $provider_id, $to_e164, '', '', '', false );
		}

		return self::result( 'failed', 'brevo', '', $to_e164, '', $result['error'], '', $result['retryable'] );
	}

	/**
	 * Marketing SMS gets the brand prefix and, unless already present or
	 * disabled, a "Reply STOP to opt out" suffix — the shape carriers
	 * expect from a marketing message. Transactional text is sent as
	 * written.
	 */
	public static function finalize_sms_text( string $text, string $category ): string {
		$text = trim( $text );

		if ( MessageLog::CATEGORY_MARKETING !== $category ) {
			return $text;
		}

		$prefix = MessagingSettings::brand() . ': ';

		if ( ! str_starts_with( $text, $prefix ) ) {
			$text = $prefix . $text;
		}

		if ( MessagingSettings::sms_append_stop() && false === stripos( $text, 'stop' ) ) {
			$text .= ' ' . __( 'Reply STOP to opt out.', 'protech-wholesale' );
		}

		return $text;
	}

	/**
	 * CAN-SPAM footer appended to a marketing email: why they are getting it
	 * (worded for who they are, a wholesale account or a past customer), the
	 * store's physical postal address, and the way out. The address is
	 * required in every marketing message; MergeTags::store_address() reads
	 * it from WooCommerce -> Settings -> General, and the Messaging setup
	 * check warns while it is empty.
	 */
	public static function email_footer_html( int $user_id ): string {
		$unsubscribe = Unsubscribe::url( $user_id );
		$preferences = wc_get_account_endpoint_url( NotificationsEndpoint::ENDPOINT );
		$site_name   = get_bloginfo( 'name' );

		$reason = Roles::is_wholesale_customer( $user_id )
			/* translators: %s: site name. */
			? sprintf( __( 'You are receiving this because you have a wholesale account with %s.', 'protech-wholesale' ), $site_name )
			/* translators: %s: site name. */
			: sprintf( __( 'You are receiving this because you have shopped with %s.', 'protech-wholesale' ), $site_name );

		$address = MergeTags::store_address();

		return '<p style="font-size:12px;color:#767676;margin-top:24px;">'
			. esc_html( $reason )
			. ' <a href="' . esc_url( $preferences ) . '">' . esc_html__( 'Manage preferences', 'protech-wholesale' ) . '</a>'
			. ' &middot; <a href="' . esc_url( $unsubscribe ) . '">' . esc_html__( 'Unsubscribe', 'protech-wholesale' ) . '</a>'
			. ( '' !== $address ? '<br />' . esc_html( $site_name . ', ' . $address ) : '' )
			. '</p>';
	}

	/**
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	private static function result( string $status, string $provider, string $provider_id, string $recipient, string $subject, string $error, string $reason, bool $retryable ): array {
		return array(
			'status'      => $status,
			'provider'    => $provider,
			'provider_id' => $provider_id,
			'recipient'   => $recipient,
			'subject'     => $subject,
			'error'       => $error,
			'reason'      => $reason,
			'retryable'   => $retryable,
		);
	}
}
