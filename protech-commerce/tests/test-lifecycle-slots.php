<?php
/**
 * The emails the shop sends on its own (welcome, application received,
 * approved, rejected) can be sent from a template instead of the built-in
 * wording (2.6.0): binding one, the tags only those emails have, and the
 * fallback that keeps an unbound email exactly as it was.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailEditor;
use ProtechWholesale\EmailRenderer;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\Emails;
use ProtechWholesale\MergeTags;
use ProtechWholesale\Roles;
use ProtechWholesale\WelcomeEmail;

/**
 * Class Test_Lifecycle_Slots
 */
class Test_Lifecycle_Slots extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( EmailTemplates::OPTION );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_filter( 'allow_password_reset', '__return_false' );
		parent::tear_down();
	}

	/** Saves a template with one text block and binds it to $slot. */
	private function bound( string $slot, string $subject, string $text ): string {
		$id = EmailTemplates::save(
			EmailTemplates::validate(
				array(
					'name'    => 'Bound ' . $slot,
					'subject' => $subject,
					'slot'    => $slot,
					'blocks'  => array( array( 'type' => 'text', 'attrs' => array( 'html' => $text ) ) ),
				)
			)['template']
		);

		return $id;
	}

	private function applicant(): int {
		return self::factory()->user->create( array( 'role' => Roles::PENDING, 'user_email' => 'ada@example.com' ) );
	}

	public function test_a_slot_is_read_only_when_the_form_sends_it_and_makes_the_template_a_service_email(): void {
		$input = array( 'name' => 'Welcome', 'kind' => 'marketing', 'blocks' => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) );

		$bound = EmailTemplates::validate( array_merge( $input, array( 'slot' => EmailTemplates::SLOT_WELCOME ) ) )['template'];

		$this->assertSame( EmailTemplates::SLOT_WELCOME, $bound['slot'] );
		$this->assertSame( EmailTemplates::KIND_TRANSACTIONAL, $bound['kind'], 'A lifecycle email is a service email.' );

		$id = EmailTemplates::save( $bound );

		$without = EmailTemplates::validate( array_merge( $input, array( 'id' => $id ) ) )['template'];
		$this->assertSame( EmailTemplates::SLOT_WELCOME, $without['slot'], 'A form without the field leaves the binding alone.' );

		$cleared = EmailTemplates::validate( array_merge( $input, array( 'id' => $id, 'slot' => '' ) ) )['template'];
		$this->assertSame( '', $cleared['slot'] );

		$bogus = EmailTemplates::validate( array_merge( $input, array( 'slot' => 'not_a_slot' ) ) )['template'];
		$this->assertSame( '', $bogus['slot'] );
	}

	public function test_binding_a_second_template_takes_the_slot_from_the_first(): void {
		$first  = $this->bound( EmailTemplates::SLOT_APPLICATION_RECEIVED, 'One', 'One' );
		$second = $this->bound( EmailTemplates::SLOT_APPLICATION_RECEIVED, 'Two', 'Two' );

		$this->assertSame( '', EmailTemplates::get( $first )['slot'] );
		$this->assertSame( $second, EmailTemplates::for_slot( EmailTemplates::SLOT_APPLICATION_RECEIVED )['id'] );
	}

	public function test_a_bound_received_email_is_the_template_not_the_built_in_one(): void {
		$this->bound( EmailTemplates::SLOT_APPLICATION_RECEIVED, 'Got it, {first_name}', 'Template says thanks' );

		Emails::send_applicant_received( $this->applicant() );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'ada@example.com', $mail->to[0][0] );
		$this->assertStringStartsWith( 'Got it,', $mail->subject );
		$this->assertStringContainsString( 'Template says thanks', $mail->body );
		$this->assertStringNotContainsString( '1–3 business days', $mail->body, 'The built-in wording is not also sent.' );
		$this->assertStringNotContainsString( 'Unsubscribe', $mail->body, 'A lifecycle email carries no marketing footer.' );
	}

	public function test_an_unbound_received_email_is_still_the_built_in_one(): void {
		Emails::send_applicant_received( $this->applicant() );

		$this->assertStringContainsString( '1–3 business days', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_a_bound_approval_email_carries_a_working_set_password_link(): void {
		$this->bound( EmailTemplates::SLOT_APPLICATION_APPROVED, 'Approved', '<a href="{set_password_url}">Set it</a>' );

		$user_id = $this->applicant();

		Emails::send_approved( $user_id );

		$body = html_entity_decode( tests_retrieve_phpmailer_instance()->get_sent()->body, ENT_QUOTES | ENT_HTML5 );

		preg_match( '/[?&]key=([^&"]+)&id=(\d+)/', $body, $matches );

		$this->assertNotEmpty( $matches, 'Expected a key/id reset link.' );
		$this->assertSame( $user_id, (int) $matches[2] );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( rawurldecode( $matches[1] ), get_userdata( $user_id )->user_login ) );
	}

	public function test_the_set_password_link_falls_back_to_my_account_when_a_key_cannot_be_made(): void {
		add_filter( 'allow_password_reset', '__return_false' );

		$this->bound( EmailTemplates::SLOT_APPLICATION_APPROVED, 'Approved', '<a href="{set_password_url}">Set it</a>' );

		Emails::send_approved( $this->applicant() );

		$body = html_entity_decode( tests_retrieve_phpmailer_instance()->get_sent()->body, ENT_QUOTES | ENT_HTML5 );

		$this->assertStringNotContainsString( 'key=', $body );
		$this->assertStringContainsString( 'href="' . wc_get_page_permalink( 'myaccount' ) . '"', $body, 'The button still goes somewhere useful.' );
	}

	public function test_a_bound_rejection_email_carries_the_reason_only_when_there_is_one(): void {
		$this->bound( EmailTemplates::SLOT_APPLICATION_REJECTED, 'Update', "Sorry.\n\n{application_reject_reason}\n\nReply to talk." );

		Emails::send_rejected( $this->applicant(), 'Outside our area' );

		$this->assertStringContainsString( 'Reason: Outside our area', tests_retrieve_phpmailer_instance()->get_sent()->body );

		reset_phpmailer_instance();
		Emails::send_rejected( $this->applicant(), '' );

		$body = tests_retrieve_phpmailer_instance()->get_sent()->body;

		$this->assertStringNotContainsString( 'Reason:', $body );
		$this->assertStringContainsString( 'Reply to talk.', $body );
	}

	public function test_a_bound_welcome_email_is_the_template_and_still_stamps_the_customer(): void {
		$this->bound( EmailTemplates::SLOT_WELCOME, 'Welcome aboard', 'Template welcome for {first_name}' );

		$user_id = Protech_Test_Factory::wholesale_customer();
		$result  = WelcomeEmail::send( $user_id );

		$this->assertTrue( $result['ok'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'Welcome aboard', $mail->subject );
		$this->assertStringContainsString( 'Template welcome for', $mail->body );
		$this->assertGreaterThan( 0, (int) get_user_meta( $user_id, WelcomeEmail::META_SENT_AT, true ) );
	}

	public function test_the_new_tags_are_offered_and_never_render_empty_outside_their_own_email(): void {
		$this->assertArrayHasKey( 'set_password_url', MergeTags::all( '' ) );
		$this->assertArrayHasKey( 'application_reject_reason', MergeTags::all( '' ) );

		$context = MergeTags::context_for_customer( Protech_Test_Factory::wholesale_customer() );

		$this->assertNotSame( '', $context['set_password_url'], 'Anywhere else it is the ordinary password-reset page.' );
		$this->assertSame( '', $context['application_reject_reason'] );
		$this->assertStringNotContainsString( 'key=', $context['set_password_url'], 'Building a context never creates a reset key.' );
	}

	public function test_a_preview_shows_where_a_rejection_reason_would_go(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$this->assertStringContainsString( 'Reason:', EmailRenderer::context( $user_id, true )['application_reject_reason'] );
		$this->assertSame( '', EmailRenderer::context( $user_id, false )['application_reject_reason'] );
	}

	public function test_the_approved_and_rejected_starters_are_added_once_to_an_existing_library(): void {
		EmailTemplates::seed_starters(); // A fresh library gets every starter.

		$seeded = array_column( EmailTemplates::all(), 'seeded' );

		$this->assertContains( 'application_approved', $seeded );
		$this->assertContains( 'application_rejected', $seeded );

		foreach ( EmailTemplates::all() as $id => $template ) {
			if ( in_array( $template['seeded'], array( 'application_approved', 'application_rejected' ), true ) ) {
				EmailTemplates::delete( (string) $id );
			}
		}

		$this->assertSame( 2, EmailTemplates::seed_starters( array( 'application_approved', 'application_rejected' ) ) );
		$this->assertSame( 0, EmailTemplates::seed_starters( array( 'application_approved', 'application_rejected' ) ), 'Seeding is once per starter.' );
		$this->assertNull( EmailTemplates::for_slot( EmailTemplates::SLOT_APPLICATION_APPROVED ), 'A starter is never bound on its own.' );
	}

	public function test_the_editor_offers_the_slot_and_shows_the_current_one(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = $this->bound( EmailTemplates::SLOT_APPLICATION_REJECTED, 'Update', 'x' );

		ob_start();
		EmailEditor::render( EmailTemplates::get( $id ), $id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select name="slot">', $html );
		$this->assertMatchesRegularExpression( '/<option value="application_rejected" selected=\'selected\'>/', $html );
	}
}
