<?php
/**
 * A thin client for the parts of the Brevo (formerly Sendinblue) v3 API
 * this plugin uses: transactional email, transactional SMS, the account
 * endpoint (for a "test connection" check), and contact lookups (to
 * honor a STOP/unsubscribe recorded directly in Brevo).
 *
 * Follows the same house pattern as Updater::fetch_latest(): wp_remote_*
 * with a short timeout, a shaped return array, never an exception. The
 * API key is the one already saved by the Brevo WordPress plugin
 * (option `sib_api_key_v3`) unless a key is set on this plugin's own
 * Settings, so nothing new has to be entered for a site that already has
 * Brevo connected.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BrevoClient
 */
class BrevoClient {

	public const BASE = 'https://api.brevo.com/v3';

	private const TIMEOUT = 10;

	/** How long a "is this contact blacklisted" answer is trusted before asking again. */
	private const BLACKLIST_CACHE_TTL = HOUR_IN_SECONDS;

	private string $api_key;

	public function __construct( ?string $api_key = null ) {
		$this->api_key = $api_key ?? MessagingSettings::brevo_api_key();
	}

	public static function is_configured(): bool {
		return '' !== MessagingSettings::brevo_api_key();
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array{ok: bool, code: int, data: array<string, mixed>, error: string, retryable: bool}
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		if ( '' === $this->api_key ) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'data'      => array(),
				'error'     => __( 'No Brevo API key is configured.', 'protech-wholesale' ),
				'retryable' => false,
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'api-key'      => $this->api_key,
				'accept'       => 'application/json',
				'content-type' => 'application/json',
				'User-Agent'   => 'protech-commerce/' . PROTECH_WHOLESALE_VERSION . '; ' . home_url(),
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'data'      => array(),
				'error'     => $response->get_error_message(),
				'retryable' => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'ok'        => true,
				'code'      => $code,
				'data'      => $data,
				'error'     => '',
				'retryable' => false,
			);
		}

		$message = (string) ( $data['message'] ?? '' );

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Brevo answered with HTTP %d.', 'protech-wholesale' ), $code );
		}

		return array(
			'ok'        => false,
			'code'      => $code,
			'data'      => $data,
			'error'     => $message,
			'retryable' => 429 === $code || $code >= 500,
		);
	}

	/**
	 * @param array{sender: array{name:string,email:string}, to: array<int,array{email:string,name?:string}>, subject: string, htmlContent: string, textContent?: string, replyTo?: array{email:string,name?:string}, tags?: string[], headers?: array<string,string>} $payload
	 * @return array{ok: bool, code: int, data: array<string, mixed>, error: string, retryable: bool}
	 */
	public function send_email( array $payload ): array {
		return $this->request( 'POST', '/smtp/email', $payload );
	}

	/**
	 * @return array{ok: bool, code: int, data: array<string, mixed>, error: string, retryable: bool}
	 */
	public function send_sms( string $sender, string $recipient_e164, string $content, string $type = 'transactional', string $tag = 'protech-wholesale', bool $unicode = false ): array {
		$payload = array(
			'recipient' => $recipient_e164,
			'content'   => $content,
			'type'      => $type,
			'tag'       => $tag,
		);

		if ( '' !== $sender ) {
			$payload['sender'] = $sender;
		}

		if ( $unicode ) {
			$payload['unicodeEnabled'] = true;
		}

		return $this->request( 'POST', '/transactionalSMS/sms', $payload );
	}

	/**
	 * @return array{ok: bool, code: int, data: array<string, mixed>, error: string, retryable: bool}
	 */
	public function get_account(): array {
		return $this->request( 'GET', '/account' );
	}

	/**
	 * @return array{ok: bool, code: int, data: array<string, mixed>, error: string, retryable: bool}
	 */
	public function get_contact( string $email ): array {
		return $this->request( 'GET', '/contacts/' . rawurlencode( $email ) );
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return array{ok: bool, code: int, data: array<string, mixed>, error: string, retryable: bool}
	 */
	public function upsert_contact( string $email, array $attributes ): array {
		return $this->request(
			'POST',
			'/contacts',
			array(
				'email'         => $email,
				'attributes'    => $attributes,
				'updateEnabled' => true,
			)
		);
	}

	/**
	 * Whether Brevo has this contact marked as SMS-blacklisted (i.e. they
	 * replied STOP). Cached briefly per email so a busy delivery batch
	 * doesn't make one Brevo call per row. Returns null when it cannot be
	 * determined (no key, network error) — callers should treat that as
	 * "unknown", not "safe to send".
	 */
	public function is_sms_blacklisted( string $email ): ?bool {
		$cache_key = 'protech_wholesale_sms_blacklist_' . md5( strtolower( $email ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		$result = $this->get_contact( $email );

		if ( 404 === $result['code'] ) {
			set_transient( $cache_key, 'no', self::BLACKLIST_CACHE_TTL );
			return false;
		}

		if ( ! $result['ok'] ) {
			return null;
		}

		$blacklisted = ! empty( $result['data']['smsBlacklisted'] );
		set_transient( $cache_key, $blacklisted ? 'yes' : 'no', self::BLACKLIST_CACHE_TTL );

		return $blacklisted;
	}

	/**
	 * Whether Brevo has this contact marked as email-blacklisted. Same
	 * caching/unknown semantics as is_sms_blacklisted().
	 */
	public function is_email_blacklisted( string $email ): ?bool {
		$cache_key = 'protech_wholesale_email_blacklist_' . md5( strtolower( $email ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		$result = $this->get_contact( $email );

		if ( 404 === $result['code'] ) {
			set_transient( $cache_key, 'no', self::BLACKLIST_CACHE_TTL );
			return false;
		}

		if ( ! $result['ok'] ) {
			return null;
		}

		$blacklisted = ! empty( $result['data']['emailBlacklisted'] );
		set_transient( $cache_key, $blacklisted ? 'yes' : 'no', self::BLACKLIST_CACHE_TTL );

		return $blacklisted;
	}
}
