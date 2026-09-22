<?php
/**
 * Picks which MessageProvider actually sends, for each channel:
 * Automatic (Brevo if it's connected, otherwise the site's mail for email;
 * Brevo only for SMS, since nothing else here can send one) or a provider
 * the admin picked explicitly on Messaging → Settings. dispatch() and
 * send_sms() go through this instead of asking BrevoClient directly, so a
 * future provider (SMTP, Twilio) only has to register here.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageProviders
 */
class MessageProviders {

	/**
	 * Every registered provider, in preference order for "Automatic".
	 *
	 * @return MessageProvider[]
	 */
	public static function all(): array {
		/**
		 * Lets another plugin add its own MessageProvider (an SMTP service, Twilio for
		 * SMS). Appended after the built-in ones, so "Automatic" prefers Brevo/the
		 * site mailer unless neither is configured.
		 *
		 * @param MessageProvider[] $providers
		 */
		return (array) apply_filters(
			'protech_wholesale_message_providers',
			array( new BrevoProvider(), new WcMailerProvider() )
		);
	}

	/** The provider that should carry the next email. Never null: the site mailer always supports email. */
	public static function email(): MessageProvider {
		return self::resolve( MessagingSettings::email_provider(), 'email' ) ?? new WcMailerProvider();
	}

	/** The provider that should carry the next text, or null when nothing can (no SMS provider is configured). */
	public static function sms(): ?MessageProvider {
		return self::resolve( MessagingSettings::sms_provider(), 'sms' );
	}

	/**
	 * @return array<string, string> id => label, for a Settings dropdown.
	 */
	public static function choices( string $channel ): array {
		$choices = array( 'auto' => __( 'Automatic', 'protech-wholesale' ) );

		foreach ( self::all() as $provider ) {
			if ( $provider->supports( $channel ) ) {
				$choices[ $provider->id() ] = $provider->label();
			}
		}

		return $choices;
	}

	private static function resolve( string $forced, string $channel ): ?MessageProvider {
		$candidates = array_values( array_filter( self::all(), static fn( MessageProvider $p ): bool => $p->supports( $channel ) ) );

		if ( 'auto' !== $forced ) {
			foreach ( $candidates as $provider ) {
				if ( $provider->id() === $forced ) {
					return $provider;
				}
			}

			return null;
		}

		foreach ( $candidates as $provider ) {
			if ( $provider->is_configured() ) {
				return $provider;
			}
		}

		return null;
	}
}
