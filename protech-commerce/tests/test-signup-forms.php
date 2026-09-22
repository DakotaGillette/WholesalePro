<?php
/**
 * Signup forms: storage/validation, the shortcode, and double opt-in
 * end to end through Contacts (a submission creates an unconfirmed
 * contact; only a valid token subscribes it and logs the consent).
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Contacts;
use ProtechWholesale\MessageLog;
use ProtechWholesale\SignupForms;

/**
 * Class Test_Signup_Forms
 */
class Test_Signup_Forms extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SignupForms::OPTION );
	}

	private function make_form( array $overrides = array() ): array {
		$result = SignupForms::validate( array_merge( array( 'name' => 'Newsletter' ), $overrides ) );
		$this->assertSame( array(), $result['errors'] );

		$id = SignupForms::save( $result['form'] );

		return SignupForms::get( $id );
	}

	public function test_a_form_needs_a_name_and_non_empty_consent_wording(): void {
		$missing_name = SignupForms::validate( array( 'name' => '', 'consent_text' => 'I agree.' ) );
		$this->assertNotEmpty( $missing_name['errors'] );

		$missing_consent = SignupForms::validate( array( 'name' => 'A form', 'consent_text' => '' ) );
		$this->assertNotEmpty( $missing_consent['errors'] );

		$valid = SignupForms::validate( array( 'name' => 'A form', 'consent_text' => 'I agree.' ) );
		$this->assertSame( array(), $valid['errors'] );
	}

	public function test_save_and_delete_round_trip(): void {
		$form = $this->make_form();

		$this->assertNotSame( '', $form['id'] );
		$this->assertSame( 'Newsletter', $form['name'] );
		$this->assertArrayHasKey( $form['id'], SignupForms::all() );

		SignupForms::delete( $form['id'] );

		$this->assertNull( SignupForms::get( $form['id'] ) );
	}

	public function test_the_shortcode_renders_the_form_with_its_own_consent_wording(): void {
		$form = $this->make_form( array( 'consent_text' => 'Yes, email me deals.' ) );

		$forms  = new SignupForms();
		$html   = $forms->shortcode( array( 'id' => $form['id'] ) );

		$this->assertStringContainsString( 'Yes, email me deals.', $html );
		$this->assertStringContainsString( SignupForms::SUBMIT_ACTION, $html );
		$this->assertStringContainsString( 'name="protech_hp"', $html, 'The honeypot field must be present for a bot to fall into.' );
	}

	public function test_an_unknown_form_id_renders_nothing_for_a_visitor(): void {
		$forms = new SignupForms();

		$this->assertSame( '', $forms->shortcode( array( 'id' => 'f_missing' ) ) );
	}

	public function test_a_submission_starts_double_opt_in_as_unconfirmed(): void {
		$id = Contacts::start_confirmation( 'newsub@example.com', array( 'first_name' => 'Nova' ) );
		$contact = Contacts::get( $id );

		$this->assertSame( Contacts::STATUS_UNCONFIRMED, $contact['status'] );
		$this->assertSame( Contacts::SOURCE_SIGNUP_FORM, $contact['source'] );
		$this->assertSame( 'Nova', $contact['first_name'] );
	}

	public function test_an_already_subscribed_contact_is_not_reset_to_unconfirmed(): void {
		$id = Contacts::start_confirmation( 'already@example.com' );
		Contacts::confirm( $id, Contacts::confirmation_token( $id ), 'test' );

		$again = Contacts::start_confirmation( 'already@example.com' );

		$this->assertSame( $id, $again );
		$this->assertSame( Contacts::STATUS_SUBSCRIBED, Contacts::get( $id )['status'] );
	}

	public function test_confirming_with_a_bad_token_does_nothing(): void {
		$id = Contacts::start_confirmation( 'wrongtoken@example.com' );

		$this->assertSame( 0, Contacts::confirm( $id, 'not-the-real-token', 'test' ) );
		$this->assertSame( Contacts::STATUS_UNCONFIRMED, Contacts::get( $id )['status'] );
		$this->assertCount( 0, Contacts::consent_log_for( $id ) );
	}

	public function test_confirming_with_the_real_token_subscribes_and_logs_consent(): void {
		$id    = Contacts::start_confirmation( 'realtoken@example.com' );
		$token = Contacts::confirmation_token( $id );

		$confirmed = Contacts::confirm( $id, $token, 'Confirmed via the double opt-in email link.' );

		$this->assertSame( $id, $confirmed );
		$this->assertSame( Contacts::STATUS_SUBSCRIBED, Contacts::get( $id )['status'] );

		$log = Contacts::consent_log_for( $id );
		$this->assertCount( 1, $log );
		$this->assertSame( Contacts::SOURCE_SIGNUP_FORM, $log[0]['source'] );
	}

	public function test_a_previously_unsubscribed_contact_can_reconfirm(): void {
		$id = Contacts::start_confirmation( 'winback@example.com' );
		Contacts::confirm( $id, Contacts::confirmation_token( $id ), 'test' );
		Contacts::record_manual_unsubscribe( $id, 'test unsub', 0 );

		$this->assertSame( Contacts::STATUS_UNSUBSCRIBED, Contacts::get( $id )['status'] );

		$again = Contacts::start_confirmation( 'winback@example.com' );

		$this->assertSame( $id, $again, 'Still one row for the same email.' );
		$this->assertSame( Contacts::STATUS_UNCONFIRMED, Contacts::get( $id )['status'] );
	}

	public function test_the_forms_admin_post_actions_and_shortcode_are_registered(): void {
		global $shortcode_tags;

		$this->assertArrayHasKey( 'protech_signup', $shortcode_tags );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . SignupForms::SUBMIT_ACTION ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . SignupForms::CONFIRM_ACTION ) );
	}
}
