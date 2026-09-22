<?php
/**
 * What MessageTransport needs from anything that can actually send an email
 * or a text: Brevo today, the site's own WooCommerce mailer for email, and
 * (later) SMTP or Twilio behind the same shape. dispatch() and send_sms()
 * keep deciding what to send and how to log it; a provider only knows how
 * to hand a finished message to whichever service it wraps.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface MessageProvider
 */
interface MessageProvider {

	/** A short, stable id: stored as the `provider` column on a message-log row, so it must never change once shipped. */
	public function id(): string;

	/** The name shown in Messaging → Settings' provider pickers. */
	public function label(): string;

	/** Whether this provider can carry a channel at all (a site mailer never carries SMS, say). */
	public function supports( string $channel ): bool;

	/** Whether it is actually set up right now (an API key present, for instance), not whether it supports the channel. */
	public function is_configured(): bool;

	/**
	 * @param array{to: string, subject: string, html: string, text: string, tags: string[], reply_to?: string} $message
	 * @return array{ok: bool, provider_id: string, error: string, retryable: bool}
	 */
	public function send_email( array $message ): array;

	/**
	 * @param array{to: string, text: string, sender: string, type: string, unicode: bool, tag: string} $message
	 * @return array{ok: bool, provider_id: string, error: string, retryable: bool}
	 */
	public function send_sms( array $message ): array;

	/** Null when this provider has no way to know (or hasn't been asked yet), never a guess. */
	public function is_email_blacklisted( string $email ): ?bool;

	/** Null when this provider has no way to know. */
	public function is_sms_blacklisted( string $email ): ?bool;

	/**
	 * The "Test connection" check on the Settings screen.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function verify(): array;
}
