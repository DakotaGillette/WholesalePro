<?php
/**
 * Manual sends ("Compose"): a one-off message to a chosen audience, plus
 * "send a test to me" and the progress a launched campaign shows in the
 * Log. A campaign is stored just long enough to resolve its content at
 * delivery time (Automations::content_for() resolves a "campaign:<id>"
 * rule_id the same way it resolves a real rule) — the index option caps
 * itself at the most recent 100 so it can't grow forever.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Campaigns
 */
class Campaigns {

	public const OPTION = 'protech_wholesale_campaigns';

	private const MAX_STORED = 100;

	/**
	 * @param array<string, mixed> $input
	 * @return array{campaign: array<string, mixed>, errors: string[]}
	 */
	public static function create( array $input, int $author_id ): array {
		$errors  = array();
		$channel = in_array( $input['channel'] ?? '', array( 'email', 'sms', 'both' ), true ) ? $input['channel'] : 'email';
		$category = ! empty( $input['service_message'] ) ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;

		$email = array(
			'subject' => sanitize_text_field( (string) ( $input['email']['subject'] ?? '' ) ),
			'heading' => sanitize_text_field( (string) ( $input['email']['heading'] ?? '' ) ),
			'body'    => wp_kses_post( (string) ( $input['email']['body'] ?? '' ) ),
		);

		$sms = array(
			'body' => sanitize_textarea_field( (string) ( $input['sms']['body'] ?? '' ) ),
		);

		$channels = 'both' === $channel ? array( 'email', 'sms' ) : array( $channel );

		if ( in_array( 'email', $channels, true ) && '' === trim( $email['body'] ) ) {
			$errors[] = __( 'Write an email body.', 'protech-wholesale' );
		}

		if ( in_array( 'sms', $channels, true ) ) {
			if ( '' === trim( $sms['body'] ) ) {
				$errors[] = __( 'Write an SMS body.', 'protech-wholesale' );
			}

			if ( ! BrevoClient::is_configured() ) {
				$errors[] = __( 'Brevo is not connected, so SMS cannot be sent.', 'protech-wholesale' );
			}
		}

		foreach ( array( 'email' => $email['subject'] . ' ' . $email['heading'] . ' ' . $email['body'], 'sms' => $sms['body'] ) as $part => $body ) {
			if ( ! in_array( $part, $channels, true ) ) {
				continue;
			}

			$unknown = MergeTags::unknown_tags( $body );

			if ( ! empty( $unknown ) ) {
				/* translators: %s: comma-separated list of merge tags. */
				$errors[] = sprintf( __( 'Unknown merge tags: %s', 'protech-wholesale' ), implode( ', ', $unknown ) );
			}
		}

		$audience = Audience::normalize( (array) ( $input['audience'] ?? array() ) );

		if ( Audience::TYPE_SELECTED === $audience['type'] && empty( $audience['user_ids'] ) ) {
			$errors[] = __( 'No customers were selected.', 'protech-wholesale' );
		}

		$campaign = array(
			'id'         => 'c_' . time() . '_' . substr( md5( uniqid( '', true ) ), 0, 6 ),
			'name'       => sanitize_text_field( (string) ( $input['name'] ?? '' ) ) ?: sprintf( /* translators: %s: date. */ __( 'Message sent %s', 'protech-wholesale' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
			'channel'    => $channel,
			'category'   => $category,
			'audience'   => $audience,
			'email'      => $email,
			'sms'        => $sms,
			'created_at' => time(),
			'created_by' => $author_id,
		);

		return array( 'campaign' => $campaign, 'errors' => $errors );
	}

	public static function get( string $id ): ?array {
		$campaigns = self::all();

		return $campaigns[ $id ] ?? null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$campaigns = get_option( self::OPTION, array() );

		return is_array( $campaigns ) ? $campaigns : array();
	}

	/**
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public static function recent( int $limit = 20 ): array {
		$campaigns = array_reverse( self::all() );

		return array_slice( array_values( $campaigns ), 0, $limit );
	}

	private static function store( array $campaign ): void {
		$campaigns                    = self::all();
		$campaigns[ $campaign['id'] ] = $campaign;

		if ( count( $campaigns ) > self::MAX_STORED ) {
			$campaigns = array_slice( $campaigns, -self::MAX_STORED, null, true );
		}

		update_option( self::OPTION, $campaigns, false );
	}

	/**
	 * Queues one log row per recipient x channel and schedules delivery.
	 * Never sends synchronously — even a few hundred inserts is cheap,
	 * but a Brevo call per recipient is not something an admin request
	 * should ever wait on.
	 *
	 * @param array<string, mixed> $campaign From create().
	 * @return array{queued: int, skipped: array<string, int>}
	 */
	public static function launch( array $campaign ): array {
		self::store( $campaign );

		$channels = 'both' === $campaign['channel'] ? array( 'email', 'sms' ) : array( $campaign['channel'] );
		$user_ids = Audience::resolve( $campaign['audience'] );
		$category = (string) $campaign['category'];
		$rule_id  = 'campaign:' . $campaign['id'];

		$ids     = array();
		$queued  = 0;
		$skipped = array();

		foreach ( $user_ids as $user_id ) {
			foreach ( $channels as $channel ) {
				$gate = MessageLog::CHANNEL_SMS === $channel
					? SmsConsent::can_receive_sms( $user_id, $category )
					: SmsConsent::can_receive_email( $user_id, $category );

				if ( ! $gate['ok'] ) {
					$skipped[ $gate['reason'] ] = ( $skipped[ $gate['reason'] ] ?? 0 ) + 1;
					continue;
				}

				$id = MessageLog::enqueue(
					array(
						'user_id'  => $user_id,
						'channel'  => $channel,
						'kind'     => MessageLog::KIND_MANUAL,
						'category' => $category,
						'rule_id'  => $rule_id,
						'anchor'   => '',
					)
				);

				if ( $id ) {
					$ids[] = $id;
					++$queued;
				} else {
					$skipped['already_sent'] = ( $skipped['already_sent'] ?? 0 ) + 1;
				}
			}
		}

		AutomationRunner::schedule_delivery( $ids );

		Logger::info( sprintf( 'Campaign "%s" launched: %d message(s) queued.', $campaign['name'], $queued ) );

		return array( 'queued' => $queued, 'skipped' => $skipped );
	}

	/**
	 * @return array<string, int> status => count.
	 */
	public static function progress( string $id ): array {
		return MessageLog::counts_for( 'campaign:' . $id );
	}

	/**
	 * Sends immediately, bypassing consent gating (the recipient is the
	 * admin who clicked "Send test to me"), so there is instant feedback
	 * on whether the content and connection actually work. Still gets
	 * the same footer/STOP text a real customer would see.
	 *
	 * @param array<string, mixed> $input Same shape as create()'s input.
	 * @return array{ok: bool, error: string, provider: string}
	 */
	public static function send_test( array $input, int $to_user_id ): array {
		$channel  = in_array( $input['channel'] ?? '', array( 'email', 'sms' ), true ) ? $input['channel'] : 'email';
		$category = ! empty( $input['service_message'] ) ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;
		$context  = MergeTags::context_for_customer( $to_user_id );

		$log_id = MessageLog::enqueue(
			array(
				'user_id'  => $to_user_id,
				'channel'  => $channel,
				'kind'     => MessageLog::KIND_TEST,
				'category' => $category,
				'rule_id'  => 'test',
				'anchor'   => 'test:' . microtime( true ),
			)
		);

		if ( $log_id ) {
			MessageLog::claim( $log_id );
		}

		if ( 'sms' === $channel ) {
			$phone  = SmsConsent::normalize_phone( (string) ( $input['test_phone'] ?? '' ) ) ?: SmsConsent::phone_for( $to_user_id );
			$body   = MergeTags::render( (string) ( $input['sms']['body'] ?? '' ), $context, 'text' );
			$result = MessageTransport::send_sms( $to_user_id, $phone, $body, $category );
		} else {
			$user    = get_userdata( $to_user_id );
			$to      = $user ? $user->user_email : '';
			$subject = MergeTags::render( (string) ( $input['email']['subject'] ?? '' ), $context, 'subject' );
			$heading = MergeTags::render( (string) ( $input['email']['heading'] ?? '' ), $context, 'subject' );
			$body    = MergeTags::render( (string) ( $input['email']['body'] ?? '' ), $context, 'html' );
			$result  = MessageTransport::send_email( $to_user_id, $to, $subject, $heading, $body, $category, array( 'test' ) );
		}

		if ( $log_id ) {
			MessageLog::finish(
				$log_id,
				$result['status'],
				array(
					'provider'    => $result['provider'],
					'provider_id' => $result['provider_id'],
					'recipient'   => $result['recipient'],
					'subject'     => $result['subject'],
					'error'       => $result['error'],
					'reason'      => $result['reason'],
				)
			);
		}

		return array(
			'ok'       => 'sent' === $result['status'],
			'error'    => $result['error'],
			'provider' => $result['provider'],
		);
	}
}
