<?php
/**
 * Turns a claimed message-log row into an actual send: resolves its
 * content and merge-tag context, re-checks consent/quiet-hours one last
 * time (state can change between queuing and delivery), and hands the
 * finished message to whichever MessageProvider Messaging -> Settings has
 * chosen (MessageProviders::email()/sms()). Automatic sends through Brevo
 * when it's connected, falling back to the site's own WooCommerce mailer
 * for email; SMS has no non-Brevo provider yet, so it fails rather than
 * falling back.
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
			return self::result( 'requeued', '', '', '', '', '', 'quiet_hours', false );
		}

		$content = Automations::content_for( (string) $row['rule_id'], (string) $row['anchor'] );

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

			return self::send_content_email( $user_id, $user->user_email, (array) $content['email'], $category, array( (string) $row['rule_id'] ), $order );
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
	 * The template an email points at, with its own subject replaced by one typed
	 * on the rule or campaign, or null when it has none (or it was deleted).
	 *
	 * @param array<string, mixed> $email `subject`, `heading`, `body` and optionally `template_id`.
	 * @return array<string, mixed>|null
	 */
	private static function template_for( array $email ): ?array {
		$template_id = (string) ( $email['template_id'] ?? '' );
		$template    = '' !== $template_id ? EmailTemplates::get( $template_id ) : null;

		if ( null === $template ) {
			return null;
		}

		$typed = trim( (string) ( $email['subject'] ?? '' ) );

		if ( '' !== $typed ) {
			$template['subject'] = $typed;
		}

		return $template;
	}

	/**
	 * True when an email points at a template that no longer exists and has no
	 * typed body to fall back on: it cannot be sent.
	 *
	 * @param array<string, mixed> $email
	 */
	private static function template_lost( array $email ): bool {
		return '' !== (string) ( $email['template_id'] ?? '' ) && null === self::template_for( $email ) && '' === trim( (string) ( $email['body'] ?? '' ) );
	}

	/**
	 * Sends the email a rule or campaign holds: the template it points at when
	 * it has one, otherwise the subject, heading and body typed into it. The one
	 * place that decides, so a rule, a campaign and a test send cannot drift.
	 * A typed subject beats the template's own; a template that has since been
	 * deleted falls back to the typed body, or fails the message with a reason.
	 *
	 * @param array<string, mixed> $email `subject`, `heading`, `body` and optionally `template_id`.
	 * @param string[]             $tags
	 * @param bool                 $preview Fills merge tags from the sender's own account and shows wholesale-only blocks.
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function send_content_email( int $user_id, string $to, array $email, string $category, array $tags = array(), ?\WC_Order $order = null, bool $preview = false ): array {
		if ( self::template_lost( $email ) ) {
			return self::result( 'failed', '', '', $to, '', __( 'The email template for this message no longer exists.', 'protech-wholesale' ), 'template_missing', false );
		}

		$template = self::template_for( $email );

		if ( null !== $template ) {
			$context = EmailRenderer::context( $user_id, $preview );

			return self::send_template_email( $user_id, $to, EmailRenderer::subject( $template, $context ), $template, $context, $category, $tags );
		}

		$context = MergeTags::context_for_customer( $user_id, $order );
		$subject = MergeTags::render( (string) ( $email['subject'] ?? '' ), $context, 'subject' );
		$heading = MergeTags::render( (string) ( $email['heading'] ?? '' ), $context, 'subject' );
		$body    = MergeTags::render( (string) ( $email['body'] ?? '' ), $context, 'html' );

		return self::send_email( $user_id, $to, $subject, $heading, $body, $category, $tags );
	}

	/**
	 * The email exactly as send_content_email() would send it to $user_id, but
	 * not sent: the subject and the finished HTML, for the review screen.
	 *
	 * @param array<string, mixed> $email
	 * @return array{subject: string, html: string}|null Null when the template is gone and nothing is typed.
	 */
	public static function content_email_preview( int $user_id, array $email, string $category ): ?array {
		if ( self::template_lost( $email ) ) {
			return null;
		}

		$template = self::template_for( $email );

		if ( null !== $template ) {
			$context = EmailRenderer::context( $user_id, true );

			return array(
				'subject' => EmailRenderer::subject( $template, $context ),
				'html'    => self::template_document( $user_id, $template, $context, $category )['html'],
			);
		}

		$context = MergeTags::context_for_customer( $user_id );

		return array(
			'subject' => MergeTags::render( (string) ( $email['subject'] ?? '' ), $context, 'subject' ),
			'html'    => self::legacy_document( $user_id, MergeTags::render( (string) ( $email['heading'] ?? '' ), $context, 'subject' ), MergeTags::render( (string) ( $email['body'] ?? '' ), $context, 'html' ), $category )['html'],
		);
	}

	/**
	 * A typed email as a finished document: WooCommerce's header and footer
	 * around it, its stylesheet inlined, and the footer its category needs.
	 *
	 * @return array{html: string, text: string}
	 */
	private static function legacy_document( int $user_id, string $heading, string $body_html, string $category ): array {
		$full_body = $body_html . self::footer_html_for( $user_id, $category );
		$wrapped   = WC()->mailer()->wrap_message( $heading, $full_body );

		return array(
			'html' => ( new \WC_Email() )->style_inline( $wrapped ),
			'text' => wp_strip_all_tags( $full_body ),
		);
	}

	/**
	 * A template as a finished document. The template owns the whole email, so
	 * it is not wrapped in WooCommerce's header and footer or run through
	 * WC_Email::style_inline(); the footer it needs comes from footer_html_for(),
	 * so a marketing template cannot go out without the unsubscribe link and
	 * postal address.
	 *
	 * @param array<string, mixed> $template
	 * @param array<string, mixed> $context  From EmailRenderer::context().
	 * @return array{html: string, text: string}
	 */
	private static function template_document( int $user_id, array $template, array $context, string $category ): array {
		$rendered = EmailRenderer::render( $template, $context, array( 'footer_html' => self::footer_html_for( $user_id, $category ) ) );

		/**
		 * The finished HTML of a template email, just before it is sent. A hook to
		 * add WC_Email::style_inline() or anything else that must see the final markup.
		 *
		 * @param string               $html
		 * @param array<string, mixed> $template
		 * @param array<string, mixed> $context
		 */
		$html = (string) apply_filters( 'protech_wholesale_email_template_html', $rendered['html'], $template, $context );

		return array( 'html' => $html, 'text' => $rendered['text'] );
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

		$document = self::legacy_document( $user_id, $heading, $body_html, $category );

		return self::dispatch( $to, $subject, $document['html'], $document['text'], $tags );
	}

	/**
	 * Sends an email built from a template.
	 *
	 * @param array<string, mixed> $template
	 * @param array<string, mixed> $context  From EmailRenderer::context().
	 * @param string[]             $tags
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function send_template_email( int $user_id, string $to, string $subject, array $template, array $context, string $category, array $tags = array() ): array {
		if ( ! is_email( $to ) ) {
			return self::result( 'failed', '', '', $to, $subject, __( 'Not a valid email address.', 'protech-wholesale' ), 'invalid_email', false );
		}

		if ( '' === trim( $subject ) ) {
			$subject = (string) get_bloginfo( 'name' );
		}

		$document = self::template_document( $user_id, $template, $context, $category );

		return self::dispatch( $to, $subject, $document['html'], $document['text'], $tags );
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
	 * Hands finished HTML and plain text to whichever provider Messaging →
	 * Settings has chosen for email (Brevo, or the site's own mailer when
	 * Automatic finds Brevo not connected). Knows nothing about templates,
	 * wrappers or footers: everything upstream has already been decided.
	 *
	 * @param string[] $tags
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function dispatch( string $to, string $subject, string $html, string $text, array $tags = array() ): array {
		$provider = MessageProviders::email();
		$reply_to = MessagingSettings::reply_to();

		$message = array(
			'to'      => $to,
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
			'tags'    => $tags,
		);

		if ( '' !== $reply_to ) {
			$message['reply_to'] = $reply_to;
		}

		$result = $provider->send_email( $message );

		return self::result( $result['ok'] ? 'sent' : 'failed', $provider->id(), $result['provider_id'], $to, $subject, $result['error'], '', $result['retryable'] );
	}

	/**
	 * @return array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool}
	 */
	public static function send_sms( int $user_id, string $to_e164, string $text, string $category ): array {
		if ( '' === $to_e164 ) {
			return self::result( 'skipped', '', '', '', '', '', 'no_phone', false );
		}

		$provider = MessageProviders::sms();

		if ( null === $provider || ! $provider->is_configured() ) {
			return self::result( 'failed', '', '', $to_e164, '', __( 'SMS requires Brevo to be connected.', 'protech-wholesale' ), 'sms_unavailable', false );
		}

		$final_text = self::finalize_sms_text( $text, $category );
		$segments   = MergeTags::sms_segments( $final_text );
		$type       = MessageLog::CATEGORY_MARKETING === $category ? 'marketing' : 'transactional';

		$result = $provider->send_sms(
			array(
				'to'      => $to_e164,
				'text'    => $final_text,
				'sender'  => MessagingSettings::sms_sender(),
				'type'    => $type,
				'unicode' => $segments['unicode'],
				'tag'     => 'protech-wholesale',
			)
		);

		return self::result( $result['ok'] ? 'sent' : 'failed', $provider->id(), $result['provider_id'], $to_e164, '', $result['error'], '', $result['retryable'] );
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

		// The preferences page lives in the wholesale part of My Account; a retail customer just gets the unsubscribe link.
		$manage = Roles::is_wholesale_customer( $user_id )
			? ' <a href="' . esc_url( $preferences ) . '">' . esc_html__( 'Manage preferences', 'protech-wholesale' ) . '</a> &middot;'
			: '';

		return '<p style="font-size:12px;color:#767676;margin-top:24px;">'
			. esc_html( $reason )
			. $manage
			. ' <a href="' . esc_url( $unsubscribe ) . '">' . esc_html__( 'Unsubscribe', 'protech-wholesale' ) . '</a>'
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
