<?php
/**
 * REST routes for the stepped new-email flow (3.9.0), under protech/v1:
 * the Template step's gallery and thumbnails, creating a draft from a
 * starter, a library template or an earlier email, saving it as it is
 * designed and its send options change, the Send step's recipient
 * estimate, and sending. Emails are Campaigns with their own design
 * (EmailDesigns); nothing here bypasses the checks the old Compose form
 * applies.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestEmails
 */
class RestEmails {

	/** Starter categories offered for an email you send yourself; the rest are lifecycle and order emails. */
	private const GALLERY_CATEGORIES = array( 'promotions', 'newsletter', 'blank' );

	private const ID_PATTERN = '(?P<id>c_\d+_[a-f0-9]{6})';

	public function register_routes(): void {
		$ns   = RestApi::NAMESPACE;
		$perm = array( RestApi::class, 'permission_admin' );

		register_rest_route(
			$ns,
			'/emails/gallery',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'gallery' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/emails/thumbnail',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'thumbnail' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/emails',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/emails/' . self::ID_PATTERN,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$ns,
			'/emails/' . self::ID_PATTERN . '/send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/emails/' . self::ID_PATTERN . '/unschedule',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'unschedule' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/emails/' . self::ID_PATTERN . '/save-as-template',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_as_template' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/audience/estimate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'estimate' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * What the Template step offers: starters for emails you send yourself,
	 * your saved templates (none bound to an automatic email), and emails you
	 * have sent, to start again from.
	 *
	 * @return array{starters: array<int, array<string, string>>, templates: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>, labels: array<string, string>}
	 */
	public function gallery(): array {
		$starters = array();

		foreach ( EmailStarters::all() as $key => $starter ) {
			$category = (string) ( $starter['category'] ?? '' );

			if ( in_array( $category, self::GALLERY_CATEGORIES, true ) ) {
				$starters[] = array(
					'key'      => (string) $key,
					'name'     => (string) $starter['name'],
					'category' => $category,
				);
			}
		}

		$templates = array();

		foreach ( EmailTemplates::all() as $template ) {
			if ( '' === (string) $template['slot'] ) {
				$templates[] = array(
					'id'         => (string) $template['id'],
					'name'       => (string) $template['name'],
					'category'   => (string) $template['category'],
					'updated_at' => (int) $template['updated_at'],
				);
			}
		}

		$recent = array();

		foreach ( Campaigns::recent_sent( 12 ) as $campaign ) {
			$has_design = Campaigns::has_own_design( $campaign ) ? null !== EmailDesigns::get( (string) $campaign['id'] ) : EmailTemplates::exists( (string) ( $campaign['email']['template_id'] ?? '' ) );

			if ( $has_design ) {
				$recent[] = array(
					'id'      => (string) $campaign['id'],
					'name'    => (string) $campaign['name'],
					'sent_at' => (int) ( $campaign['sent_at'] ?? $campaign['created_at'] ?? 0 ),
				);
			}
		}

		return array(
			'starters'  => $starters,
			'templates' => $templates,
			'recent'    => $recent,
			'labels'    => EmailTemplates::category_labels(),
		);
	}

	/**
	 * The design a gallery source names (`starter:<key>`, `template:<id>` or
	 * `campaign:<id>`), or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function design_for_source( string $source ): ?array {
		$parts = explode( ':', $source, 2 );
		$key   = sanitize_text_field( $parts[1] ?? '' );

		switch ( $parts[0] ) {
			case 'starter':
				return EmailDesigns::from_starter( $key );
			case 'template':
				return EmailDesigns::from_template( $key );
			case 'campaign':
				return EmailDesigns::from_campaign( $key );
			default:
				return null;
		}
	}

	/**
	 * A rendered email for a gallery thumbnail, cached for a day per source
	 * and version of it, so opening the gallery does not re-render every
	 * email every time.
	 */
	public function thumbnail( \WP_REST_Request $request ) {
		$source = (string) $request->get_param( 'source' );
		$design = self::design_for_source( $source );

		if ( null === $design ) {
			return new \WP_Error( 'protech_email_source_not_found', __( 'That design no longer exists.', 'protech-wholesale' ), array( 'status' => 404 ) );
		}

		$template  = EmailTemplates::validate( $design )['template'];
		$cache_key = 'protech_wholesale_thumb_' . md5( $source . '|' . (string) ( $design['updated_at'] ?? '' ) . '|' . PROTECH_WHOLESALE_VERSION . '|' . get_current_user_id() );
		$html      = get_transient( $cache_key );

		if ( ! is_string( $html ) ) {
			$user_id = get_current_user_id();
			$context = EmailRenderer::context( $user_id, true );
			$footer  = MessageTransport::footer_html_for( $user_id, MessageLog::CATEGORY_MARKETING );
			$html    = EmailRenderer::render( $template, $context, array( 'footer_html' => $footer ) )['html'];

			set_transient( $cache_key, $html, DAY_IN_SECONDS );
		}

		return array( 'html' => $html );
	}

	/**
	 * A new draft from a gallery pick. Body: `source` (`starter`, `template`,
	 * `campaign`), `key` or `id`, and optionally `audience` (a preset).
	 */
	public function create( \WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$source = sanitize_key( (string) ( $body['source'] ?? '' ) );
		$ref    = (string) ( $body['key'] ?? $body['id'] ?? '' );
		$design = self::design_for_source( $source . ':' . $ref );

		if ( null === $design ) {
			return new \WP_Error( 'protech_email_source_not_found', __( 'That design no longer exists. Pick another.', 'protech-wholesale' ), array( 'status' => 404 ) );
		}

		$audience = is_array( $body['audience'] ?? null ) ? $body['audience'] : array();
		$created  = Campaigns::create_draft( $design, $audience, get_current_user_id() );

		Logger::info( sprintf( 'Email draft "%s" started by admin #%d.', $created['campaign']['name'], get_current_user_id() ) );

		return new \WP_REST_Response( self::payload( $created['campaign'], $created['design'], $created['errors'] ), 201 );
	}

	public function get( \WP_REST_Request $request ) {
		$campaign = Campaigns::get( (string) $request->get_param( 'id' ) );

		if ( null === $campaign ) {
			return self::not_found();
		}

		return self::payload( $campaign, EmailDesigns::get( (string) $campaign['id'] ), array() );
	}

	/**
	 * Saves a draft: any of `design`, `name`, `audience`, `service_message`.
	 * Always saves; problems come back as `errors` for the screen to show,
	 * and only sending refuses them.
	 */
	public function update( \WP_REST_Request $request ) {
		$campaign = Campaigns::get( (string) $request->get_param( 'id' ) );

		if ( null === $campaign ) {
			return self::not_found();
		}

		if ( Campaigns::STATUS_DRAFT !== Campaigns::status( $campaign ) ) {
			return new \WP_Error( 'protech_email_not_draft', __( 'Only a draft can be changed.', 'protech-wholesale' ), array( 'status' => 409 ) );
		}

		$body     = (array) $request->get_json_params();
		$campaign = Campaigns::apply_options( $campaign, $body );
		$design   = is_array( $body['design'] ?? null ) ? $body['design'] : array();

		// The design's name and kind follow the email's, so the editor's test send shows the right footer.
		$design['name'] = $campaign['name'];
		$design['kind'] = MessageLog::CATEGORY_TRANSACTIONAL === $campaign['category'] ? EmailTemplates::KIND_TRANSACTIONAL : EmailTemplates::KIND_MARKETING;

		$saved = EmailDesigns::save( (string) $campaign['id'], $design );
		Campaigns::save_draft( $campaign );

		return self::payload( $campaign, $saved['design'], $saved['errors'] );
	}

	public function delete( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );

		if ( null === Campaigns::get( $id ) ) {
			return self::not_found();
		}

		if ( ! Campaigns::delete_draft( $id ) ) {
			return new \WP_Error( 'protech_email_not_draft', __( 'Only a draft can be deleted.', 'protech-wholesale' ), array( 'status' => 409 ) );
		}

		return array( 'deleted' => true );
	}

	/**
	 * Sends a draft now (`when` = `now`), or schedules it (`when` =
	 * `schedule`, with `date` as Y-m-d and `time` as H:i, in the site's own
	 * timezone).
	 */
	public function send( \WP_REST_Request $request ) {
		$id   = (string) $request->get_param( 'id' );
		$body = (array) $request->get_json_params();

		if ( null === Campaigns::get( $id ) ) {
			return self::not_found();
		}

		if ( 'schedule' === ( $body['when'] ?? '' ) ) {
			$send_at = self::site_time_to_timestamp( (string) ( $body['date'] ?? '' ), (string) ( $body['time'] ?? '' ) );

			if ( null === $send_at ) {
				return new \WP_REST_Response( array( 'ok' => false, 'errors' => array( __( 'Choose a date and a time.', 'protech-wholesale' ) ) ), 422 );
			}

			$scheduled = Campaigns::schedule( $id, $send_at );

			if ( ! $scheduled['ok'] ) {
				return new \WP_REST_Response( array( 'ok' => false, 'errors' => $scheduled['errors'] ), 422 );
			}

			Logger::info( sprintf( 'Email %s scheduled by admin #%d for %s.', $id, get_current_user_id(), gmdate( 'c', $send_at ) ) );

			return array(
				'ok'           => true,
				'scheduled'    => true,
				'send_at'      => $send_at,
				'redirect_url' => MessagingTab::url( 'emails', array( 'status' => Campaigns::STATUS_SCHEDULED ) ),
			);
		}

		$result = Campaigns::send_now( $id );

		if ( ! $result['ok'] ) {
			return new \WP_REST_Response( array( 'ok' => false, 'errors' => $result['errors'] ), 422 );
		}

		Logger::info( sprintf( 'Email %s sent by admin #%d: %d message(s) queued.', $id, get_current_user_id(), $result['queued'] ) );

		return array(
			'ok'      => true,
			'queued'  => $result['queued'],
			'skipped' => $result['skipped'],
			'log_url' => MessagingTab::url( 'log', array( 'rule_id' => 'campaign:' . $id, 'queued' => $result['queued'] ) ),
		);
	}

	/** A date and time on the site's own clock as a Unix timestamp, or null when either is missing or malformed. */
	public static function site_time_to_timestamp( string $date, string $time ): ?int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return null;
		}

		$moment = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );

		return false === $moment ? null : $moment->getTimestamp();
	}

	/** Takes a scheduled email back to a draft. */
	public function unschedule( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );

		if ( null === Campaigns::get( $id ) ) {
			return self::not_found();
		}

		if ( ! Campaigns::unschedule( $id ) ) {
			return new \WP_Error( 'protech_email_not_scheduled', __( 'That email is not scheduled.', 'protech-wholesale' ), array( 'status' => 409 ) );
		}

		return self::payload( (array) Campaigns::get( $id ), EmailDesigns::get( $id ), array() );
	}

	/**
	 * Keeps a copy of this email's design in the template library, to start
	 * other emails from. Body: `name`. The email itself is unchanged.
	 */
	public function save_as_template( \WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$design = EmailDesigns::get( $id );

		if ( null === Campaigns::get( $id ) || null === $design ) {
			return self::not_found();
		}

		$body = (array) $request->get_json_params();
		$name = sanitize_text_field( (string) ( $body['name'] ?? '' ) );

		$design['id']     = '';
		$design['slot']   = '';
		$design['seeded'] = '';
		$design['name']   = '' !== $name ? $name : (string) $design['name'];
		$design['blocks'] = EmailTemplates::reid( (array) $design['blocks'] );

		$validated = EmailTemplates::validate( $design );

		if ( ! empty( $validated['errors'] ) ) {
			return new \WP_REST_Response( array( 'errors' => $validated['errors'] ), 422 );
		}

		$template_id = EmailTemplates::save( $validated['template'] );

		Logger::info( sprintf( 'Email %s saved as template "%s" by admin #%d.', $id, $validated['template']['name'], get_current_user_id() ) );

		return new \WP_REST_Response( array( 'id' => $template_id, 'name' => (string) $validated['template']['name'], 'errors' => array() ), 201 );
	}

	/**
	 * Who an audience reaches by email right now, and who is left out and
	 * why. Body: `audience`, `service_message`.
	 *
	 * @return array{label: string, total: int, sent_to: int, reasons: array<int, array{reason: string, label: string, count: int}>}
	 */
	public function estimate( \WP_REST_Request $request ): array {
		$body     = (array) $request->get_json_params();
		$audience = Audience::normalize( is_array( $body['audience'] ?? null ) ? $body['audience'] : array() );
		$category = ! empty( $body['service_message'] ) ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;
		$estimate = Audience::estimate( $audience, array( 'email' ), $category );
		$reasons  = array();

		foreach ( $estimate['reasons'] as $reason => $count ) {
			$reasons[] = array(
				'reason' => (string) $reason,
				'label'  => ComposeScreen::reason_label( (string) $reason ),
				'count'  => (int) $count,
			);
		}

		return array(
			'label'   => Audience::describe( $audience ),
			'total'   => $estimate['total'],
			'sent_to' => (int) $estimate['sent_to']['email'],
			'reasons' => $reasons,
		);
	}

	/**
	 * What the app knows about one email.
	 *
	 * @param array<string, mixed>      $campaign
	 * @param array<string, mixed>|null $design
	 * @param string[]                  $errors
	 * @return array<string, mixed>
	 */
	public static function payload( array $campaign, ?array $design, array $errors ): array {
		return array(
			'email'  => array(
				'id'              => (string) $campaign['id'],
				'name'            => (string) $campaign['name'],
				'status'          => Campaigns::status( $campaign ),
				'audience'        => (array) $campaign['audience'],
				'service_message' => MessageLog::CATEGORY_TRANSACTIONAL === ( $campaign['category'] ?? '' ),
				'updated_at'      => (int) ( $campaign['updated_at'] ?? 0 ),
				'sent_at'         => (int) ( $campaign['sent_at'] ?? 0 ),
				'send_at'         => (int) ( $campaign['send_at'] ?? 0 ),
				// The date and time it is scheduled for, on the site's own clock, for the screen.
				'send_at_label'   => ! empty( $campaign['send_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $campaign['send_at'] ) : '',
				// Only what this email sets; the screen shows Settings' sender for anything empty.
				'sender'          => array(
					'from_name'  => (string) ( $campaign['sender']['from_name'] ?? '' ),
					'from_email' => (string) ( $campaign['sender']['from_email'] ?? '' ),
					'reply_to'   => (string) ( $campaign['sender']['reply_to'] ?? '' ),
				),
				// Why a scheduled email came back as a draft, when it did.
				'last_error'      => (string) ( $campaign['last_error'] ?? '' ),
			),
			'design' => $design,
			'errors' => array_values( $errors ),
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'protech_email_not_found', __( 'That email no longer exists.', 'protech-wholesale' ), array( 'status' => 404 ) );
	}
}
