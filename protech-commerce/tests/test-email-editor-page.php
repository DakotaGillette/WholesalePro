<?php
/**
 * The client-side editor's page: the mount point EmailComposer::render()
 * prints, the template/schema/nonce Plugin::enqueue_admin_assets() localizes
 * into window.protechEditor for it, that only the editor screen gets those
 * assets, and the two guarantees the JS model in editor-src/ is built on:
 * the fixture round-trips through EmailTemplates::validate() unchanged, and
 * every block attribute is either a visible field or declared hidden.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailBlocks;
use ProtechWholesale\EmailComposer;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MessagingTab;
use ProtechWholesale\Plugin;
use ProtechWholesale\RestApi;

/**
 * Class Test_Email_Editor_Page
 */
class Test_Email_Editor_Page extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( EmailTemplates::OPTION );

		wp_dequeue_script( 'protech-wholesale-editor' );
		wp_deregister_script( 'protech-wholesale-editor' );
		wp_dequeue_style( 'protech-wholesale-editor' );
		wp_deregister_style( 'protech-wholesale-editor' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		unset( $_GET['edit'], $_GET['page'], $_GET['mode'], $_GET['ids'], $_GET['email'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/** @return array<string, mixed> */
	private function localized_editor_data(): array {
		$raw = wp_scripts()->get_data( 'protech-wholesale-editor', 'data' );

		$this->assertIsString( $raw, 'The editor script has no localized data: was it enqueued?' );

		$json = rtrim( trim( substr( $raw, (int) strpos( $raw, '=' ) + 1 ) ), "; \t\n" );

		return (array) json_decode( $json, true );
	}

	public function test_the_editor_page_mounts_the_app_with_template_schema_and_nonce(): void {
		$id = EmailTemplates::save(
			EmailTemplates::validate( array( 'name' => 'Bootstrap test', 'subject' => 'Hi', 'blocks' => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) ) )['template']
		);

		set_current_screen( MessagingTab::page_slug( 'templates' ) );
		$_GET['edit'] = $id;

		ob_start();
		EmailComposer::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="protech-editor-root"', $html );
		$this->assertStringContainsString( 'data-template-id="' . $id . '"', $html );

		Plugin::instance()->enqueue_admin_assets( '' );

		$this->assertTrue( wp_script_is( 'protech-wholesale-editor', 'enqueued' ) );

		$boot = $this->localized_editor_data();

		$this->assertSame( $id, $boot['template']['id'] );
		$this->assertSame( 'Bootstrap test', $boot['template']['name'] );
		$this->assertArrayHasKey( 'heading', $boot['schema']['types'] );
		$this->assertNotEmpty( $boot['nonce'] );
		$this->assertSame( esc_url_raw( rest_url( RestApi::NAMESPACE ) ), $boot['restRoot'] );
	}

	public function test_the_built_assets_exist_and_are_enqueued_only_on_the_editor_screen(): void {
		$this->assertFileExists( PROTECH_WHOLESALE_DIR . 'assets/editor/editor.js' );
		$this->assertFileExists( PROTECH_WHOLESALE_DIR . 'assets/editor/editor.css' );

		// The template library (no `edit` in the address) is the same screen id, minus the query arg.
		set_current_screen( MessagingTab::page_slug( 'templates' ) );
		unset( $_GET['edit'] );

		Plugin::instance()->enqueue_admin_assets( '' );

		$this->assertFalse( wp_script_is( 'protech-wholesale-editor', 'enqueued' ), 'The editor bundle has no business loading on the template library.' );

		$_GET['edit'] = 'new';
		Plugin::instance()->enqueue_admin_assets( '' );

		$this->assertTrue( wp_script_is( 'protech-wholesale-editor', 'enqueued' ) );
	}

	public function test_the_new_email_flow_loads_the_app_in_email_mode_with_a_preset_audience(): void {
		$customer = Protech_Test_Factory::wholesale_customer();

		set_current_screen( MessagingTab::page_slug( 'compose' ) );
		$_GET['page'] = MessagingTab::page_slug( 'compose' );
		$_GET['ids']  = (string) $customer;

		Plugin::instance()->enqueue_admin_assets( '' );

		$this->assertTrue( wp_script_is( 'protech-wholesale-editor', 'enqueued' ) );

		$boot = $this->localized_editor_data();

		$this->assertSame( 'email', $boot['mode'] );
		$this->assertNull( $boot['emailData'] );
		$this->assertSame( 'selected', $boot['presetAudience']['type'] );
		$this->assertSame( array( $customer ), $boot['presetAudience']['user_ids'] );
		$this->assertArrayNotHasKey( 'order_number', $boot['mergeTags'], 'An email you send yourself has no order to fill order tags from.' );
		$this->assertStringContainsString( 'mode=form', $boot['urls']['textForm'] );
	}

	public function test_the_older_one_form_compose_does_not_load_the_app(): void {
		set_current_screen( MessagingTab::page_slug( 'compose' ) );
		$_GET['page'] = MessagingTab::page_slug( 'compose' );
		$_GET['mode'] = 'form';

		Plugin::instance()->enqueue_admin_assets( '' );

		$this->assertFalse( wp_script_is( 'protech-wholesale-editor', 'enqueued' ) );
	}

	public function test_the_fixture_template_validates_unchanged(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/template.json' ), true );

		$result = EmailTemplates::validate( $fixture );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( $fixture, $result['template'] );
	}

	public function test_every_default_attr_is_a_field_or_declared_hidden(): void {
		$schema      = EmailBlocks::schema();
		$common_keys = array_map( static fn( array $field ): string => (string) $field['key'], $schema['common_fields'] );

		foreach ( $schema['types'] as $type => $definition ) {
			$field_keys = array_map( static fn( array $field ): string => (string) $field['key'], $definition['fields'] );

			foreach ( array_keys( $definition['defaults'] ) as $key ) {
				// Structural (a columns block's nested lists), or covered by every block's own common fields.
				if ( 'children' === $key || in_array( $key, $common_keys, true ) ) {
					continue;
				}

				$this->assertTrue(
					in_array( $key, $field_keys, true ) || in_array( $key, $definition['hidden'], true ),
					sprintf( '%s.%s is a default attribute with no field and is not declared hidden.', $type, $key )
				);
			}
		}
	}
}
