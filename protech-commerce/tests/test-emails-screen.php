<?php
/**
 * The Emails screen (2.7.0): the standard automations that ship switched off,
 * duplicating a rule, the plain sentence for when a rule fires, and the
 * lifecycle and sent lists.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Audience;
use ProtechWholesale\Automations;
use ProtechWholesale\Campaigns;
use ProtechWholesale\EmailsScreen;
use ProtechWholesale\EmailTemplates;

/**
 * Class Test_Emails_Screen
 */
class Test_Emails_Screen extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( Automations::OPTION );
		delete_option( Campaigns::OPTION );
		delete_option( EmailTemplates::OPTION );
	}

	private function html( callable $draw ): string {
		ob_start();
		$draw();

		return (string) ob_get_clean();
	}

	public function test_the_four_standard_automations_are_created_switched_off(): void {
		EmailTemplates::seed_starters();

		$this->assertSame( 4, Automations::seed_standard() );

		$rules = Automations::all();

		$this->assertCount( 4, $rules );

		foreach ( $rules as $rule ) {
			$this->assertFalse( (bool) $rule['enabled'], $rule['name'] . ' must ship switched off.' );
		}

		$triggers = array_column( $rules, 'trigger' );

		foreach ( Automations::TRIGGERS as $trigger ) {
			$this->assertContains( $trigger, $triggers );
		}
	}

	public function test_the_reminder_and_win_back_use_their_designed_templates(): void {
		EmailTemplates::seed_starters();
		Automations::seed_standard();

		foreach ( Automations::all() as $rule ) {
			if ( Automations::TRIGGER_REORDER_REMINDER === $rule['trigger'] || Automations::TRIGGER_WINBACK === $rule['trigger'] ) {
				$this->assertTrue( EmailTemplates::exists( (string) $rule['email']['template_id'] ), $rule['name'] . ' points at a template.' );
			} else {
				$this->assertSame( '', (string) ( $rule['email']['template_id'] ?? '' ), 'The order-based ones use order details, which templates do not have.' );
				$this->assertNotSame( '', trim( (string) $rule['email']['body'] ) );
			}
		}
	}

	public function test_the_standard_automations_are_only_created_into_an_empty_list_and_only_once(): void {
		$this->assertSame( 4, Automations::seed_standard() );
		$this->assertSame( 0, Automations::seed_standard() );

		delete_option( Automations::OPTION );
		Automations::save( Automations::defaults( Automations::TRIGGER_WINBACK ) );

		$this->assertSame( 0, Automations::seed_standard(), 'A store with rules of its own is left alone.' );
	}

	public function test_a_duplicate_is_a_switched_off_copy_with_its_own_id(): void {
		$rule                 = Automations::presets()[ Automations::TRIGGER_WINBACK ];
		$rule['enabled']      = true;
		$rule['last_run_at']  = time();
		$id                   = Automations::save( $rule );

		$copy_id = Automations::duplicate( $id );
		$copy    = Automations::get( $copy_id );

		$this->assertNotSame( $id, $copy_id );
		$this->assertSame( 'Copy of ' . $rule['name'], $copy['name'] );
		$this->assertFalse( (bool) $copy['enabled'] );
		$this->assertArrayNotHasKey( 'last_run_at', $copy );
		$this->assertSame( $rule['email']['body'], $copy['email']['body'] );
		$this->assertTrue( (bool) Automations::get( $id )['enabled'], 'The original is untouched.' );
		$this->assertSame( '', Automations::duplicate( 'r_missing' ) );
	}

	public function test_every_rule_type_says_when_it_fires_in_a_sentence(): void {
		foreach ( Automations::presets() as $rule ) {
			$this->assertNotSame( '', Automations::describe( $rule ), $rule['trigger'] . ' has no description.' );
		}

		$this->assertStringContainsString( '30 days', Automations::describe( Automations::defaults( Automations::TRIGGER_REORDER_REMINDER ) ) );
	}

	public function test_the_lifecycle_list_offers_to_design_each_email_until_one_is_bound(): void {
		$html = $this->html( array( EmailsScreen::class, 'render_lifecycle' ) );

		$this->assertSame( 4, substr_count( $html, 'Design this email' ) );
		$this->assertStringNotContainsString( 'Use built-in wording', $html );

		$id = EmailTemplates::save( EmailTemplates::validate( array( 'name' => 'Mine', 'slot' => EmailTemplates::SLOT_WELCOME, 'blocks' => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) ) )['template'] );

		$html = $this->html( array( EmailsScreen::class, 'render_lifecycle' ) );

		$this->assertSame( 3, substr_count( $html, 'Design this email' ) );
		$this->assertSame( 1, substr_count( $html, 'Use built-in wording' ) );
		$this->assertStringContainsString( 'Mine', $html );
		$this->assertStringContainsString( $id, $html, 'It links to the template.' );
	}

	public function test_the_sent_list_shows_past_messages_with_a_way_to_send_again(): void {
		$this->assertStringContainsString( 'Nothing has been sent', $this->html( array( EmailsScreen::class, 'render_sent' ) ) );

		$created = Campaigns::create(
			array(
				'name'     => 'Spring restock',
				'channel'  => 'email',
				'audience' => array( 'type' => Audience::TYPE_ALL, 'scope' => Audience::SCOPE_RETAIL ),
				'email'    => array( 'subject' => 'Hi', 'body' => 'Body' ),
			),
			1
		);

		Campaigns::launch( $created['campaign'] );

		$html = $this->html( array( EmailsScreen::class, 'render_sent' ) );

		$this->assertStringContainsString( 'Spring restock', $html );
		$this->assertStringContainsString( 'All retail customers', $html );
		$this->assertStringContainsString( 'Duplicate and edit', $html );
		$this->assertStringContainsString( 'protech_duplicate_campaign', $html );
	}
}
