<?php
/**
 * Email templates: sending a template as an email, starting from a starter,
 * and saying where a template is used. The editor itself is a client-side
 * app (editor-src/), covered by test-email-editor-page.php and Vitest, not
 * by markup assertions here.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Automations;
use ProtechWholesale\EmailComposer;
use ProtechWholesale\EmailRenderer;
use ProtechWholesale\EmailStarters;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;

/**
 * Class Test_Email_Composer
 */
class Test_Email_Composer extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( EmailTemplates::OPTION );
		delete_option( Automations::OPTION );
		reset_phpmailer_instance();
	}

	/** @return array<string, mixed> */
	private function template_with( array $blocks ): array {
		return EmailTemplates::validate( array( 'name' => 'Editor test', 'kind' => 'marketing', 'subject' => 'Hi', 'blocks' => $blocks ) )['template'];
	}

	public function test_starting_from_a_starter_creates_a_named_unbound_copy(): void {
		$id = EmailTemplates::create_from_starter( 'winback' );

		$this->assertTrue( EmailTemplates::exists( $id ) );
		$this->assertSame( '', EmailTemplates::get( $id )['slot'] );
		$this->assertSame( EmailStarters::all()['winback']['name'], EmailTemplates::get( $id )['name'] );
		$this->assertSame( '', EmailTemplates::create_from_starter( 'nope' ) );
	}

	public function test_a_template_email_is_sent_whole_with_the_footer_a_marketing_email_needs(): void {
		$user_id  = Protech_Test_Factory::retail_customer();
		$template = $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Body for {first_name}' ) ) ) );
		$context  = EmailRenderer::context( $user_id, true );

		$result = MessageTransport::send_template_email( $user_id, 'buyer@example.com', 'Subject line', $template, $context, MessageLog::CATEGORY_MARKETING, array( 'test' ) );

		$this->assertSame( 'sent', $result['status'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'Subject line', $mail->subject );
		$this->assertStringContainsString( 'Unsubscribe', $mail->body );
		$this->assertStringContainsString( 'Body for', $mail->body );
	}

	public function test_a_template_email_needs_a_real_address(): void {
		$template = $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) );
		$result   = MessageTransport::send_template_email( 0, 'not-an-address', 'S', $template, EmailRenderer::context( 0, true ), MessageLog::CATEGORY_TRANSACTIONAL );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'invalid_email', $result['reason'] );
	}

	public function test_a_template_in_use_says_where(): void {
		$id = EmailTemplates::save( $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) ) );

		$this->assertSame( '', EmailComposer::used_by( $id, '' ) );
		$this->assertNotSame( '', EmailComposer::used_by( $id, EmailTemplates::SLOT_WELCOME ), 'A bound lifecycle email counts as use.' );
	}
}
