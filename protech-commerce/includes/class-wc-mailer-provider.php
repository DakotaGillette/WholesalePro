<?php
/**
 * The fallback MessageProvider: the site's own WooCommerce mailer. Email
 * only: WooCommerce has no concept of sending a text message. Always
 * "configured", since it needs no API key or setup of its own; it is
 * whatever WooCommerce → Settings → Emails already sends through.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WcMailerProvider
 */
class WcMailerProvider implements MessageProvider {

	public function id(): string {
		return 'wc_mailer';
	}

	public function label(): string {
		return __( "This site's mail", 'protech-wholesale' );
	}

	public function supports( string $channel ): bool {
		return 'email' === $channel;
	}

	public function is_configured(): bool {
		return true;
	}

	public function send_email( array $message ): array {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// WC_Email::send() passes these headers straight to wp_mail(). Until 3.10.0 the reply-to was never sent this way.
		if ( ! empty( $message['reply_to'] ) && is_email( (string) $message['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $message['reply_to'];
		}

		// WC_Email::send() sets the From through these two filters (checked against WooCommerce's
		// class-wc-email.php), so a per-email sender goes in here and nowhere else, for this one send.
		$from_name  = (string) ( $message['from_name'] ?? '' );
		$from_email = (string) ( $message['from_email'] ?? '' );
		$name_cb    = static fn() => $from_name;
		$email_cb   = static fn() => $from_email;

		if ( '' !== $from_name ) {
			add_filter( 'woocommerce_email_from_name', $name_cb, 999 );
		}

		if ( '' !== $from_email ) {
			add_filter( 'woocommerce_email_from_address', $email_cb, 999 );
		}

		try {
			$sent = WC()->mailer()->send( $message['to'], $message['subject'], $message['html'], $headers );
		} finally {
			remove_filter( 'woocommerce_email_from_name', $name_cb, 999 );
			remove_filter( 'woocommerce_email_from_address', $email_cb, 999 );
		}

		return array(
			'ok'          => $sent,
			'provider_id' => '',
			'error'       => $sent ? '' : __( "The site's mail sender rejected or failed to send this message.", 'protech-wholesale' ),
			'retryable'   => false,
		);
	}

	public function send_sms( array $message ): array {
		return array(
			'ok'          => false,
			'provider_id' => '',
			'error'       => __( 'This provider cannot send text messages.', 'protech-wholesale' ),
			'retryable'   => false,
		);
	}

	public function is_email_blacklisted( string $email ): ?bool {
		return null;
	}

	public function is_sms_blacklisted( string $email ): ?bool {
		return null;
	}

	public function verify(): array {
		return array( 'ok' => true, 'error' => '' );
	}
}
