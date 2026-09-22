<?php
/**
 * The protech/v1/templates REST routes: every one requires manage_woocommerce,
 * saving goes through the same EmailTemplates::validate()/save() the
 * admin-post editor uses, preview and test-send go through the same
 * EmailRenderer/MessageTransport a real send does, and the schema route
 * exposes the exact field data the form-based editor already renders from.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MessageLog;
use ProtechWholesale\RestApi;

/**
 * Class Test_Rest_Templates
 */
class Test_Rest_Templates extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( EmailTemplates::OPTION );
		reset_phpmailer_instance();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestApi::NAMESPACE . $route );

		if ( ! empty( $body ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/** @return array<string, mixed> */
	private function valid_template( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'   => 'A test template',
				'blocks' => array( array( 'type' => 'heading', 'attrs' => array( 'text' => 'Hello {first_name}' ) ) ),
			),
			$overrides
		);
	}

	public function test_every_route_requires_manage_woocommerce(): void {
		// Logged in but without the capability: WordPress's own
		// rest_authorization_required_code() maps that to 403, reserving 401
		// for a request with no user at all (see the next test).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach (
			array(
				array( 'GET', '/templates' ),
				array( 'POST', '/templates' ),
				array( 'GET', '/templates/t_nonexistent' ),
				array( 'PUT', '/templates/t_nonexistent' ),
				array( 'DELETE', '/templates/t_nonexistent' ),
				array( 'POST', '/templates/t_nonexistent/duplicate' ),
				array( 'GET', '/templates/schema' ),
				array( 'POST', '/templates/preview' ),
				array( 'POST', '/templates/test-send' ),
			) as [ $method, $route ]
		) {
			$response = $this->request( $method, $route );
			$this->assertSame( 403, $response->get_status(), "{$method} {$route} should require manage_woocommerce." );
		}
	}

	public function test_logged_out_is_refused_too(): void {
		wp_set_current_user( 0 );

		$response = $this->request( 'GET', '/templates' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_creating_a_valid_template_saves_it_and_returns_no_errors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->request( 'POST', '/templates', $this->valid_template() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $data['errors'] );
		$this->assertNotSame( '', $data['template']['id'] );
		$this->assertNotNull( EmailTemplates::get( $data['template']['id'] ) );
	}

	public function test_an_invalid_template_returns_422_with_the_cleaned_template_and_errors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->request( 'POST', '/templates', array( 'name' => '', 'blocks' => array() ) );
		$data     = $response->get_data();

		$this->assertSame( 422, $response->get_status() );
		$this->assertNotEmpty( $data['errors'] );
		$this->assertArrayHasKey( 'template', $data );
	}

	public function test_getting_updating_and_deleting_a_template(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = EmailTemplates::save( EmailTemplates::validate( $this->valid_template() )['template'] );

		$get = $this->request( 'GET', "/templates/{$id}" );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( $id, $get->get_data()['id'] );

		$update = $this->request( 'PUT', "/templates/{$id}", $this->valid_template( array( 'name' => 'Renamed' ) ) );
		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( 'Renamed', $update->get_data()['template']['name'] );
		$this->assertSame( 'Renamed', EmailTemplates::get( $id )['name'] );

		$delete = $this->request( 'DELETE', "/templates/{$id}" );
		$this->assertSame( 200, $delete->get_status() );
		$this->assertNull( EmailTemplates::get( $id ) );
	}

	public function test_getting_a_missing_template_is_404(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->request( 'GET', '/templates/t_doesnotexist' );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_a_template_still_bound_to_a_lifecycle_slot_cannot_be_deleted(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = EmailTemplates::save( EmailTemplates::validate( $this->valid_template( array( 'slot' => EmailTemplates::SLOT_WELCOME ) ) )['template'] );

		$response = $this->request( 'DELETE', "/templates/{$id}" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertNotNull( EmailTemplates::get( $id ), 'Refused deletes must not delete.' );
	}

	public function test_duplicating_gives_a_fresh_id_and_a_copy_name(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id       = EmailTemplates::save( EmailTemplates::validate( $this->valid_template() )['template'] );
		$response = $this->request( 'POST', "/templates/{$id}/duplicate" );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotSame( $id, $data['template']['id'] );
		$this->assertStringContainsString( 'Copy of', $data['template']['name'] );
	}

	public function test_the_schema_lists_every_block_type_with_defaults_fields_and_hidden_attrs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->request( 'GET', '/templates/schema' );
		$schema   = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		foreach ( array( 'heading', 'text', 'button', 'image', 'product_grid', 'columns', 'divider', 'spacer' ) as $type ) {
			$this->assertArrayHasKey( $type, $schema['types'] );
			$this->assertArrayHasKey( 'defaults', $schema['types'][ $type ] );
			$this->assertIsArray( $schema['types'][ $type ]['fields'] );
			$this->assertIsArray( $schema['types'][ $type ]['hidden'] );

			// Every default attribute is either a real field, a common field
			// (pt/pb/bg), structural (children), or explicitly declared hidden.
			$field_keys  = array_column( $schema['types'][ $type ]['fields'], 'key' );
			$common_keys = array_column( $schema['common_fields'], 'key' );

			foreach ( array_keys( $schema['types'][ $type ]['defaults'] ) as $default_key ) {
				$this->assertTrue(
					'children' === $default_key
						|| in_array( $default_key, $field_keys, true )
						|| in_array( $default_key, $common_keys, true )
						|| in_array( $default_key, $schema['types'][ $type ]['hidden'], true ),
					"{$type}.{$default_key} has no field and is not declared hidden."
				);
			}
		}

		$this->assertArrayHasKey( 'max_blocks', $schema['limits'] );
		$this->assertArrayHasKey( 'helvetica', $schema['fonts'] );
	}

	public function test_preview_renders_with_the_preview_flag_and_the_marketing_footer(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );
		wp_set_current_user( $admin_id );

		$response = $this->request( 'POST', '/templates/preview', $this->valid_template( array( 'subject' => 'Hi {first_name}' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-pw-block', $data['html'], 'Preview must carry the canvas-click marker.' );
		$this->assertStringContainsString( 'Unsubscribe', $data['html'], 'A marketing template preview carries the same footer a real send would.' );
		$this->assertStringNotContainsString( '{first_name}', $data['subject'] );
		$this->assertNotEmpty( $data['text'] );
	}

	public function test_test_send_goes_to_the_typed_address_and_is_logged_as_a_test(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );
		wp_set_current_user( $admin_id );

		$response = $this->request( 'POST', '/templates/test-send', array_merge( $this->valid_template(), array( 'to' => 'someone-else@example.com' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'someone-else@example.com', $data['to'] );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'someone-else@example.com', $sent->to[0][0] );

		$rows = MessageLog::query( array( 'user_id' => $admin_id ) )['rows'];
		$this->assertNotEmpty( $rows );
		$this->assertSame( MessageLog::KIND_TEST, $rows[0]['kind'] );
	}

	public function test_test_send_defaults_to_the_admins_own_address_when_none_is_typed(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );
		wp_set_current_user( $admin_id );

		$response = $this->request( 'POST', '/templates/test-send', $this->valid_template() );

		$this->assertSame( 'admin@example.com', $response->get_data()['to'] );
	}
}
