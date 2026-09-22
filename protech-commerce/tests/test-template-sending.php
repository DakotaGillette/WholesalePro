<?php
/**
 * Templates on the send path (2.4.0): a rule or campaign can point at an email
 * template instead of a typed body; the one sender resolves it the same way for
 * real sends, test sends and the review screen; and the review screen reports
 * who would receive a message and who is left out.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Audience;
use ProtechWholesale\Automations;
use ProtechWholesale\Campaigns;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;
use ProtechWholesale\ComposeScreen;
use ProtechWholesale\SmsConsent;

/**
 * Class Test_Template_Sending
 */
class Test_Template_Sending extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( EmailTemplates::OPTION );
		reset_phpmailer_instance();
	}

	private function template( string $subject = 'From the template, {first_name}', string $text = 'Template body for {first_name}' ): string {
		return EmailTemplates::save(
			EmailTemplates::validate(
				array(
					'name'    => 'Sending test',
					'kind'    => 'marketing',
					'subject' => $subject,
					'blocks'  => array( array( 'type' => 'text', 'attrs' => array( 'html' => $text ) ) ),
				)
			)['template']
		);
	}

	private function campaign_input( array $email ): array {
		return array(
			'channel'  => 'email',
			'audience' => array( 'type' => Audience::TYPE_ALL ),
			'email'    => $email,
		);
	}

	public function test_a_campaign_with_a_template_needs_no_typed_body(): void {
		$result = Campaigns::create( $this->campaign_input( array( 'template_id' => $this->template() ) ), 1 );

		$this->assertSame( array(), $result['errors'] );
		$this->assertNotSame( '', $result['campaign']['email']['template_id'] );
	}

	public function test_a_campaign_pointing_at_a_missing_template_is_refused(): void {
		$result = Campaigns::create( $this->campaign_input( array( 'template_id' => 't_gone', 'body' => '' ) ), 1 );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( '', $result['campaign']['email']['template_id'] );
	}

	public function test_a_rule_with_a_template_needs_no_typed_body_but_a_missing_one_is_refused(): void {
		$base = array(
			'trigger' => Automations::TRIGGER_REORDER_REMINDER,
			'name'    => 'Templated rule',
			'channel' => 'email',
			'params'  => array( 'days' => 30 ),
		);

		$ok = Automations::validate( array_merge( $base, array( 'email' => array( 'template_id' => $this->template() ) ) ) );

		$this->assertSame( array(), $ok['errors'] );
		$this->assertNotSame( '', $ok['rule']['email']['template_id'] );

		$gone = Automations::validate( array_merge( $base, array( 'email' => array( 'template_id' => 't_gone' ) ) ) );

		$this->assertNotEmpty( $gone['errors'] );
		$this->assertSame( '', $gone['rule']['email']['template_id'] );
	}

	public function test_a_template_email_is_sent_with_its_own_subject_and_the_marketing_footer(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$result = MessageTransport::send_content_email( $user_id, 'buyer@example.com', array( 'template_id' => $this->template() ), MessageLog::CATEGORY_MARKETING );

		$this->assertSame( 'sent', $result['status'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertStringContainsString( 'Template body for', $mail->body );
		$this->assertStringContainsString( 'Unsubscribe', $mail->body );
		$this->assertStringStartsWith( 'From the template,', $mail->subject );
		$this->assertStringNotContainsString( '{first_name}', $mail->subject );
	}

	public function test_a_typed_subject_beats_the_templates(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		MessageTransport::send_content_email( $user_id, 'buyer@example.com', array( 'template_id' => $this->template(), 'subject' => 'Typed subject' ), MessageLog::CATEGORY_MARKETING );

		$this->assertSame( 'Typed subject', tests_retrieve_phpmailer_instance()->get_sent()->subject );
	}

	public function test_a_deleted_template_falls_back_to_the_typed_body_or_fails_with_a_reason(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$fallback = MessageTransport::send_content_email( $user_id, 'buyer@example.com', array( 'template_id' => 't_gone', 'subject' => 'Hi', 'heading' => 'Hi', 'body' => 'Typed body' ), MessageLog::CATEGORY_MARKETING );

		$this->assertSame( 'sent', $fallback['status'] );
		$this->assertStringContainsString( 'Typed body', tests_retrieve_phpmailer_instance()->get_sent()->body );

		$lost = MessageTransport::send_content_email( $user_id, 'buyer@example.com', array( 'template_id' => 't_gone' ), MessageLog::CATEGORY_MARKETING );

		$this->assertSame( 'failed', $lost['status'] );
		$this->assertSame( 'template_missing', $lost['reason'] );
	}

	public function test_an_email_without_a_template_is_sent_exactly_as_before(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$result = MessageTransport::send_content_email( $user_id, 'buyer@example.com', array( 'subject' => 'Plain {first_name}', 'heading' => 'Heading', 'body' => 'Plain body' ), MessageLog::CATEGORY_MARKETING );

		$this->assertSame( 'sent', $result['status'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertStringContainsString( 'Plain body', $mail->body );
	}

	public function test_a_test_send_of_a_templated_campaign_uses_the_template(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );

		$result = Campaigns::send_test( array( 'channel' => 'email', 'email' => array( 'template_id' => $this->template() ) ), $admin_id );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'Template body for', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_the_review_shows_the_email_without_sending_it(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$preview = MessageTransport::content_email_preview( $user_id, array( 'template_id' => $this->template() ), MessageLog::CATEGORY_MARKETING );

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'Template body for', $preview['html'] );
		$this->assertStringContainsString( 'Unsubscribe', $preview['html'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'Nothing was sent.' );

		$this->assertNull( MessageTransport::content_email_preview( $user_id, array( 'template_id' => 't_gone' ), MessageLog::CATEGORY_MARKETING ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function build_review( array $input ): array {
		$method = new ReflectionMethod( ComposeScreen::class, 'build_review' );
		$method->setAccessible( true );

		return $method->invoke( null, $input );
	}

	public function test_the_review_counts_who_gets_it_and_why_others_do_not(): void {
		$in  = Protech_Test_Factory::wholesale_customer();
		$out = Protech_Test_Factory::wholesale_customer();

		update_user_meta( $out, SmsConsent::META_EMAIL_MARKETING, 'no' );

		$review = $this->build_review(
			array(
				'channel'  => 'email',
				'audience' => array( 'type' => Audience::TYPE_SELECTED, 'user_ids' => array( $in, $out ) ),
				'email'    => array( 'template_id' => $this->template() ),
			)
		);

		$this->assertSame( array(), $review['errors'] );
		$this->assertSame( 2, $review['audience_total'] );
		$this->assertSame( 1, $review['sent_to']['email'] );
		$this->assertSame( 1, array_sum( $review['reasons'] ), 'The unsubscribed customer is left out, with a reason.' );
	}

	public function test_the_review_reports_an_unsendable_message_as_errors(): void {
		$review = $this->build_review( array( 'channel' => 'email', 'audience' => array( 'type' => Audience::TYPE_ALL ), 'email' => array( 'subject' => 'Hi', 'body' => '' ) ) );

		$this->assertNotEmpty( $review['errors'] );
	}

	public function test_the_review_form_carries_the_earlier_input_forward_and_leaves_out_its_own_fields(): void {
		$method = new ReflectionMethod( ComposeScreen::class, 'hidden_fields' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			null,
			array(
				'action'                          => 'protech_send_message',
				'protech_wholesale_compose_nonce' => 'abc',
				'preview_email'                   => 'me@example.com',
				'channel'                         => 'email',
				'audience'                        => array( 'type' => 'tier', 'tiers' => array( 'gold' ) ),
				'email'                           => array( 'subject' => 'A "quoted" subject' ),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="channel" value="email"', $html );
		$this->assertStringContainsString( 'name="audience[tiers][0]" value="gold"', $html );
		$this->assertStringContainsString( 'value="A &quot;quoted&quot; subject"', $html );
		$this->assertStringNotContainsString( 'name="action"', $html );
		$this->assertStringNotContainsString( 'nonce', $html );
		$this->assertStringNotContainsString( 'preview_email', $html );
	}
}
