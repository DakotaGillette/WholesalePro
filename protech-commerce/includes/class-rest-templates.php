<?php
/**
 * REST routes for the email template library, under protech/v1/templates.
 * Reuses exactly what the admin-post editor already uses underneath
 * (EmailTemplates::validate/save/duplicate/delete, EmailRenderer,
 * MessageTransport, EmailComposer's category/logging helpers), so a REST
 * client and the form-based editor can never drift: the same call produces
 * the same saved template and the same sent email either way.
 *
 * Nothing here is wired to the admin UI yet. The existing admin-post
 * handlers keep working as they always have. This is the foundation the
 * client-side editor (a later release) is built on.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestTemplates
 */
class RestTemplates {

	public function register_routes(): void {
		$ns   = RestApi::NAMESPACE;
		$perm = array( RestApi::class, 'permission_admin' );

		register_rest_route(
			$ns,
			'/templates',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_templates' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_template' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$ns,
			'/templates/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'schema' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/templates/starters',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'starters' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/templates/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/templates/test-send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_send' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/templates/(?P<id>[\w-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_template' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_template' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$ns,
			'/templates/(?P<id>[\w-]+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_template' ),
				'permission_callback' => $perm,
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function list_templates(): array {
		return array_values( EmailTemplates::all() );
	}

	public function get_template( \WP_REST_Request $request ) {
		$template = EmailTemplates::get( (string) $request->get_param( 'id' ) );

		if ( null === $template ) {
			return new \WP_Error( 'protech_template_not_found', __( 'That template no longer exists.', 'protech-wholesale' ), array( 'status' => 404 ) );
		}

		return $template;
	}

	public function create_template( \WP_REST_Request $request ) {
		$input = (array) $request->get_json_params();
		unset( $input['id'] ); // A create can never overwrite an existing template by guessing its id.

		return $this->validate_and_save( $input );
	}

	public function update_template( \WP_REST_Request $request ) {
		$input       = (array) $request->get_json_params();
		$input['id'] = (string) $request->get_param( 'id' );

		if ( ! EmailTemplates::exists( $input['id'] ) ) {
			return new \WP_Error( 'protech_template_not_found', __( 'That template no longer exists.', 'protech-wholesale' ), array( 'status' => 404 ) );
		}

		return $this->validate_and_save( $input );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return \WP_REST_Response
	 */
	private function validate_and_save( array $input ): \WP_REST_Response {
		$result = EmailTemplates::validate( $input );

		if ( ! empty( $result['errors'] ) ) {
			return new \WP_REST_Response( array( 'template' => $result['template'], 'errors' => $result['errors'] ), 422 );
		}

		$id = EmailTemplates::save( $result['template'] );

		Logger::info( sprintf( 'Email template "%s" saved via REST by admin #%d.', $result['template']['name'], get_current_user_id() ) );

		return new \WP_REST_Response( array( 'template' => EmailTemplates::get( $id ), 'errors' => array() ), 200 );
	}

	public function delete_template( \WP_REST_Request $request ) {
		$id       = (string) $request->get_param( 'id' );
		$template = EmailTemplates::get( $id );

		if ( null === $template ) {
			return new \WP_Error( 'protech_template_not_found', __( 'That template no longer exists.', 'protech-wholesale' ), array( 'status' => 404 ) );
		}

		$used_by = EmailComposer::used_by( $id, (string) $template['slot'] );

		if ( '' !== $used_by ) {
			return new \WP_Error(
				'protech_template_in_use',
				sprintf(
					/* translators: %s: what is still using this template. */
					__( 'Still in use by: %s', 'protech-wholesale' ),
					$used_by
				),
				array( 'status' => 409 )
			);
		}

		EmailTemplates::delete( $id );

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public function duplicate_template( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$new = EmailTemplates::duplicate( $id );

		if ( '' === $new ) {
			return new \WP_Error( 'protech_template_not_found', __( 'That template no longer exists.', 'protech-wholesale' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( array( 'template' => EmailTemplates::get( $new ) ), 201 );
	}

	/** @return array<string, mixed> */
	public function schema(): array {
		return EmailBlocks::schema();
	}

	/**
	 * Every starter, already run through validate() so the editor's Gallery
	 * can drop one straight into its (still unsaved) state: choosing one is
	 * local until the admin actually saves, the same as typing.
	 *
	 * @return array<int, array{key: string, name: string, category: string, template: array<string, mixed>}>
	 */
	public function starters(): array {
		$out = array();

		foreach ( EmailStarters::all() as $key => $starter ) {
			$template = EmailTemplates::validate( $starter )['template'];

			$out[] = array(
				'key'      => (string) $key,
				'name'     => (string) $starter['name'],
				'category' => (string) $template['category'],
				'template' => $template,
			);
		}

		return $out;
	}

	/**
	 * Renders an unsaved (or not-yet-valid) template exactly as a real send
	 * would: the same EmailRenderer, the same marketing footer, merge tags
	 * filled from the requesting admin's own account. Errors are reported,
	 * not fatal, since a half-finished template needs a preview too.
	 */
	public function preview( \WP_REST_Request $request ) {
		$input   = (array) $request->get_json_params();
		$user_id = (int) ( $request->get_param( 'user_id' ) ?: get_current_user_id() );
		$result  = EmailTemplates::validate( $input );
		$template = $result['template'];

		$context  = EmailRenderer::context( $user_id, true );
		$category = EmailComposer::category_of( $template );
		$footer   = MessageTransport::footer_html_for( $user_id, $category );
		$rendered = EmailRenderer::render( $template, $context, array( 'footer_html' => $footer ) );

		return array(
			'subject' => EmailRenderer::subject( $template, $context ),
			'html'    => $rendered['html'],
			'text'    => $rendered['text'],
			'errors'  => $result['errors'],
		);
	}

	/** Sends the posted template, as it stands, to any address: the same path Send a preview in the editor uses. */
	public function test_send( \WP_REST_Request $request ) {
		$input   = (array) $request->get_json_params();
		$to_raw  = (string) ( $request->get_param( 'to' ) ?? '' );
		$admin   = wp_get_current_user();
		$to      = is_email( $to_raw ) ? $to_raw : (string) $admin->user_email;
		$template = EmailTemplates::validate( $input )['template']; // Errors do not matter for a test send.

		$context = EmailRenderer::context( (int) $admin->ID, true );
		$subject = EmailRenderer::subject( $template, $context );

		$result = MessageTransport::send_template_email( (int) $admin->ID, $to, $subject, $template, $context, EmailComposer::category_of( $template ), array( 'test' ) );

		EmailComposer::log_test( (int) $admin->ID, $result );

		return array(
			'ok'    => 'sent' === $result['status'],
			'to'    => $to,
			'error' => $result['error'],
		);
	}
}
