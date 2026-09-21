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

		$email['template_id'] = sanitize_text_field( (string) ( $input['email']['template_id'] ?? '' ) );

		if ( '' !== $email['template_id'] && ! EmailTemplates::exists( $email['template_id'] ) ) {
			$errors[]             = __( 'That email template no longer exists. Choose another, or write the email here.', 'protech-wholesale' );
			$email['template_id'] = '';
		}

		// With a template the design and wording come from it; only the subject can be typed here, to override its own.
		$templated = '' !== $email['template_id'];

		$sms = array(
			'body' => sanitize_textarea_field( (string) ( $input['sms']['body'] ?? '' ) ),
		);

		$channels = 'both' === $channel ? array( 'email', 'sms' ) : array( $channel );

		if ( in_array( 'email', $channels, true ) && ! $templated && '' === trim( $email['body'] ) ) {
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

		foreach ( array( 'email' => $templated ? $email['subject'] : $email['subject'] . ' ' . $email['heading'] . ' ' . $email['body'], 'sms' => $sms['body'] ) as $part => $body ) {
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

		if ( Audience::TYPE_BOUGHT_PRODUCT === $audience['type'] && ! wc_get_product( $audience['product_id'] ) instanceof \WC_Product ) {
			$errors[] = __( 'Choose the product they bought.', 'protech-wholesale' );
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
	 * Sends a preview immediately, bypassing consent gating, so there is
	 * instant feedback on whether the content and connection work. It goes
	 * to `preview_email` / `preview_phone` when the admin typed one (any
	 * address, not just their own), else to the admin's own account. Merge
	 * tags always fill in from the admin's account, since that is who the
	 * preview is "about". Still gets the same footer/STOP text a real
	 * customer would see.
	 *
	 * @param array<string, mixed> $input Same shape as create()'s input, plus optional preview_email / preview_phone.
	 * @return array{ok: bool, error: string, provider: string, recipient: string}
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
			$typed  = (string) ( $input['preview_phone'] ?? '' );
			$phone  = SmsConsent::normalize_phone( $typed ) ?: SmsConsent::phone_for( $to_user_id );
			$body   = MergeTags::render( (string) ( $input['sms']['body'] ?? '' ), $context, 'text' );
			$result = MessageTransport::send_sms( $to_user_id, $phone, $body, $category );
		} else {
			$user   = get_userdata( $to_user_id );
			$typed  = sanitize_email( (string) ( $input['preview_email'] ?? '' ) );
			$to     = is_email( $typed ) ? $typed : ( $user ? $user->user_email : '' );
			$result = MessageTransport::send_content_email( $to_user_id, $to, (array) ( $input['email'] ?? array() ), $category, array( 'test' ), null, true );
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
			'ok'        => 'sent' === $result['status'],
			'error'     => $result['error'],
			'provider'  => $result['provider'],
			'recipient' => $result['recipient'],
		);
	}
}
