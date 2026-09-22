<?php
/**
 * Emails written in the stepped flow (3.9.0): each one owns a copy of its
 * design, stored outside the template library; drafts keep half-finished
 * work; only sent emails are trimmed; and delivery sends the email's own
 * design.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Audience;
use ProtechWholesale\Automations;
use ProtechWholesale\Campaigns;
use ProtechWholesale\EmailDesigns;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;

/**
 * Class Test_Email_Designs
 */
class Test_Email_Designs extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( EmailTemplates::OPTION );
		delete_option( Campaigns::OPTION );
		reset_phpmailer_instance();
	}

	/** @return array<string, mixed> */
	private function design( string $subject = 'Hello {first_name}' ): array {
		return array(
			'name'    => 'Spring sale',
			'subject' => $subject,
			'blocks'  => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Our own design body' ) ) ),
		);
	}

	/** @return array<string, mixed> */
	private function draft( string $subject = 'Hello {first_name}', array $audience = array( 'type' => 'all' ) ): array {
		return Campaigns::create_draft( $this->design( $subject ), $audience, 1 )['campaign'];
	}

	public function test_a_copy_from_a_starter_has_fresh_block_ids_and_nothing_tying_it_back(): void {
		$first  = EmailDesigns::from_starter( 'sale_announcement' );
		$second = EmailDesigns::from_starter( 'sale_announcement' );

		$this->assertNotNull( $first );
		$this->assertSame( '', $first['id'] );
		$this->assertSame( '', $first['slot'] );
		$this->assertNotSame( $first['blocks'][0]['id'], $second['blocks'][0]['id'] );
		$this->assertNull( EmailDesigns::from_starter( 'no_such_starter' ) );
	}

	public function test_a_draft_design_never_enters_the_template_library(): void {
		$before = count( EmailTemplates::all() );
		$email  = $this->draft();

		$this->assertSame( $before, count( EmailTemplates::all() ) );
		$this->assertNotNull( EmailDesigns::get( $email['id'] ) );
		$this->assertSame( Campaigns::STATUS_DRAFT, Campaigns::status( $email ) );
		$this->assertTrue( Campaigns::has_own_design( $email ) );
	}

	public function test_a_partial_save_keeps_the_rest_of_the_design(): void {
		$email = $this->draft();

		EmailDesigns::save( $email['id'], array( 'style' => array( 'brand' => '#ff0000' ) ) );
		EmailDesigns::save( $email['id'], array( 'subject' => 'Changed' ) );

		$design = EmailDesigns::get( $email['id'] );

		$this->assertSame( 'Changed', $design['subject'] );
		$this->assertSame( '#ff0000', $design['style']['brand'] );
		$this->assertCount( 1, $design['blocks'] );
	}

	public function test_a_draft_with_problems_is_still_saved_and_reports_them(): void {
		$email = $this->draft();
		$saved = EmailDesigns::save( $email['id'], array( 'subject' => 'Hi {not_a_tag}' ) );

		$this->assertNotEmpty( $saved['errors'] );
		$this->assertSame( 'Hi {not_a_tag}', EmailDesigns::get( $email['id'] )['subject'] );
	}

	public function test_an_email_saved_before_3_9_counts_as_sent(): void {
		$this->assertSame( Campaigns::STATUS_SENT, Campaigns::status( array( 'id' => 'c_1_abcdef' ) ) );
		$this->assertSame( Campaigns::STATUS_DRAFT, Campaigns::status( array( 'status' => 'draft' ) ) );
	}

	public function test_sending_is_refused_without_a_subject_or_a_design(): void {
		$no_subject = $this->draft( '' );

		$this->assertNotEmpty( Campaigns::validate_for_send( $no_subject ) );
		$this->assertFalse( Campaigns::send_now( $no_subject['id'] )['ok'] );

		$no_design = $this->draft();
		EmailDesigns::delete( $no_design['id'] );

		$this->assertNotEmpty( Campaigns::validate_for_send( $no_design ) );
	}

	public function test_sending_a_draft_marks_it_sent_and_it_cannot_be_sent_or_deleted_again(): void {
		$email  = $this->draft();
		$result = Campaigns::send_now( $email['id'] );

		$this->assertTrue( $result['ok'] );

		$sent = Campaigns::get( $email['id'] );

		$this->assertSame( Campaigns::STATUS_SENT, Campaigns::status( $sent ) );
		$this->assertGreaterThan( 0, $sent['sent_at'] );
		$this->assertFalse( Campaigns::send_now( $email['id'] )['ok'] );
		$this->assertFalse( Campaigns::delete_draft( $email['id'] ) );
		$this->assertSame( array( $email['id'] ), array_column( Campaigns::recent_sent(), 'id' ) );
	}

	public function test_deleting_a_draft_removes_its_design(): void {
		$email = $this->draft();

		$this->assertTrue( Campaigns::delete_draft( $email['id'] ) );
		$this->assertNull( Campaigns::get( $email['id'] ) );
		$this->assertNull( EmailDesigns::get( $email['id'] ) );
	}

	public function test_trimming_drops_only_the_oldest_sent_emails_and_their_designs(): void {
		$draft  = $this->draft();
		$oldest = $this->draft();

		Campaigns::launch( Campaigns::get( $oldest['id'] ) );

		for ( $i = 0; $i < 100; $i++ ) {
			$created = Campaigns::create(
				array(
					'channel'  => 'email',
					'audience' => array( 'type' => Audience::TYPE_ALL ),
					'email'    => array( 'subject' => 'Hi', 'body' => 'Body' ),
				),
				1
			);
			Campaigns::launch( $created['campaign'] );
		}

		$this->assertNull( Campaigns::get( $oldest['id'] ) );
		$this->assertNull( EmailDesigns::get( $oldest['id'] ) );
		$this->assertNotNull( Campaigns::get( $draft['id'] ) );
		$this->assertNotNull( EmailDesigns::get( $draft['id'] ) );
		$this->assertCount( 100, Campaigns::recent_sent( 500 ) );
	}

	public function test_delivery_sends_the_emails_own_design(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$email   = $this->draft( 'Own subject for {first_name}' );
		$content = Automations::content_for( 'campaign:' . $email['id'] );

		$this->assertIsArray( $content['email']['design'] );

		$result = MessageTransport::send_content_email( $user_id, 'buyer@example.com', $content['email'], MessageLog::CATEGORY_MARKETING );
		$mail   = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'sent', $result['status'] );
		$this->assertStringContainsString( 'Our own design body', $mail->body );
		$this->assertStringStartsWith( 'Own subject for', $mail->subject );
	}

	public function test_delivery_of_an_email_whose_design_is_gone_fails_with_a_reason(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$email   = $this->draft();

		EmailDesigns::delete( $email['id'] );

		$content = Automations::content_for( 'campaign:' . $email['id'] );
		$result  = MessageTransport::send_content_email( $user_id, 'buyer@example.com', $content['email'], MessageLog::CATEGORY_MARKETING );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'template_missing', $result['reason'] );
	}

	public function test_a_copy_of_an_older_email_uses_the_library_template_it_pointed_at(): void {
		$template_id = EmailTemplates::save(
			EmailTemplates::validate(
				array(
					'name'    => 'Library one',
					'subject' => 'From the library',
					'blocks'  => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Library body' ) ) ),
				)
			)['template']
		);

		$created = Campaigns::create(
			array(
				'channel'  => 'email',
				'audience' => array( 'type' => Audience::TYPE_ALL ),
				'email'    => array( 'template_id' => $template_id ),
			),
			1
		);
		Campaigns::launch( $created['campaign'] );

		$copy = EmailDesigns::from_campaign( $created['campaign']['id'] );

		$this->assertNotNull( $copy );
		$this->assertSame( 'From the library', $copy['subject'] );
		$this->assertSame( '', $copy['id'] );
	}
}
