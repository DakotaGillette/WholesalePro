<?php
/**
 * The Brevo MessageProvider: builds the payload MessageTransport::dispatch()
 * and send_sms() used to build inline, and hands it to BrevoClient exactly
 * as before. BrevoClient itself is untouched; this class only knows how to
 * turn a generic message into a Brevo-shaped one and back.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BrevoProvider
 */
class BrevoProvider implements MessageProvider {

	public function id(): string {
		return 'brevo';
	}

	public function label(): string {
		return __( 'Brevo', 'protech-wholesale' );
	}

	public function supports( string $channel ): bool {
		return in_array( $channel, array( 'email', 'sms' ), true );
	}

	public function is_configured(): bool {
		return BrevoClient::is_configured();
	}

	public function send_email( array $message ): array {
		$payload = array(
			'sender'      => array(
				'name'  => MessagingSettings::from_name(),
				'email' => MessagingSettings::from_email(),
			),
			'to'          => array( array( 'email' => $message['to'] ) ),
			'subject'     => $message['subject'],
			'htmlContent' => $message['html'],
			'textContent' => $message['text'],
			'tags'        => array_values( array_unique( array_merge( array( 'protech-wholesale' ), $message['tags'] ?? array() ) ) ),
		);

		if ( ! empty( $message['reply_to'] ) ) {
			$payload['replyTo'] = array( 'email' => $message['reply_to'] );
		}

		$result = ( new BrevoClient() )->send_email( $payload );

		return array(
			'ok'          => $result['ok'],
			'provider_id' => (string) ( $result['data']['messageId'] ?? '' ),
			'error'       => $result['error'],
			'retryable'   => $result['retryable'],
		);
	}

	public function send_sms( array $message ): array {
		$result = ( new BrevoClient() )->send_sms(
			$message['sender'],
			$message['to'],
			$message['text'],
			$message['type'],
			$message['tag'] ?? 'protech-wholesale',
			$message['unicode'] ?? false
		);

		return array(
			'ok'          => $result['ok'],
			'provider_id' => (string) ( $result['data']['messageId'] ?? ( $result['data']['reference'] ?? '' ) ),
			'error'       => $result['error'],
			'retryable'   => $result['retryable'],
		);
	}

	public function is_email_blacklisted( string $email ): ?bool {
		return ( new BrevoClient() )->is_email_blacklisted( $email );
	}

	public function is_sms_blacklisted( string $email ): ?bool {
		return ( new BrevoClient() )->is_sms_blacklisted( $email );
	}

	public function verify(): array {
		$result = ( new BrevoClient() )->get_account();

		return array( 'ok' => $result['ok'], 'error' => $result['error'] );
	}
}
