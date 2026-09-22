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
		$sent = WC()->mailer()->send( $message['to'], $message['subject'], $message['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );

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
