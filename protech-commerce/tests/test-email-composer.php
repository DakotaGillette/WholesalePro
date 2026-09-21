<?php
/**
 * The template editor: the markup the script depends on (field names and
 * their renumbering suffixes, block templates, the submit buttons that carry
 * the action), sending a template as an email, starting from a starter, and
 * saying where a template is used.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Automations;
use ProtechWholesale\EmailBlocks;
use ProtechWholesale\EmailComposer;
use ProtechWholesale\EmailEditor;
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

	private function render_editor( array $template, string $id = '' ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		EmailEditor::render( $template, $id );

		return (string) ob_get_clean();
	}

	public function test_every_field_is_named_by_position_and_carries_its_renumbering_suffix(): void {
		$html = $this->render_editor(
			$this->template_with(
				array(
					array( 'type' => 'heading', 'attrs' => array( 'text' => 'Hello' ) ),
					array( 'type' => 'button', 'attrs' => array( 'label' => 'Shop', 'url' => '{shop_url}' ) ),
				)
			)
		);

		$this->assertStringContainsString( 'name="blocks[0][attrs][text]" data-name-suffix="[attrs][text]"', $html );
		$this->assertStringContainsString( 'name="blocks[1][attrs][label]" data-name-suffix="[attrs][label]"', $html );
		$this->assertStringContainsString( 'name="blocks[1][type]" data-name-suffix="[type]"', $html );
	}

	public function test_a_checkbox_posts_a_zero_when_unticked(): void {
		$html = $this->render_editor( $this->template_with( array( array( 'type' => 'button', 'attrs' => array( 'label' => 'Go', 'full_width' => false ) ) ) ) );

		$this->assertMatchesRegularExpression( '/<input type="hidden" name="blocks\[0\]\[attrs\]\[full_width\]"[^>]*value="0"/', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="blocks\[0\]\[attrs\]\[full_width\]"[^>]*value="1"/', $html );
	}

	public function test_columns_print_three_named_lists_and_hide_the_third_for_two(): void {
		$template = $this->template_with(
			array(
				array(
					'type'     => 'columns',
					'attrs'    => array( 'count' => 2 ),
					'children' => array( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Left' ) ) ), array(), array() ),
				),
			)
		);

		$html = $this->render_editor( $template );

		$this->assertSame( 3, substr_count( $html, 'class="protech-column"' ) );
		$this->assertStringContainsString( 'name="blocks[0][children][0][0][attrs][html]" data-name-suffix="[attrs][html]"', $html );
		$this->assertMatchesRegularExpression( '/data-column="2" hidden/', $html );
	}

	public function test_a_block_template_is_printed_for_every_type(): void {
		$html = $this->render_editor( $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) ) );

		foreach ( array_keys( EmailBlocks::types() ) as $type ) {
			$this->assertStringContainsString( 'id="protech-block-tpl-' . $type . '"', $html );
		}
	}

	public function test_a_column_menu_never_offers_columns(): void {
		$html   = $this->render_editor( $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) ) );
		$start  = (int) strpos( $html, 'id="protech-block-tpl-columns"' );
		$column = substr( $html, $start, (int) strpos( $html, '</script>', $start ) - $start );

		$this->assertStringContainsString( 'protech-add-in-list', $column );
		$this->assertStringNotContainsString( '<option value="columns">', $column );
	}

	public function test_the_buttons_carry_the_action_so_a_preview_is_never_a_save(): void {
		$html = $this->render_editor( $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => 'x' ) ) ) ) );

		$this->assertStringContainsString( 'id="protech-refresh-preview" name="action" value="' . EmailComposer::DRAFT_PREVIEW_ACTION . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . EmailComposer::SEND_TEST_ACTION . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . EmailComposer::SAVE_ACTION . '" class="button button-primary', $html );
	}

	public function test_typed_markup_is_escaped_in_the_editor(): void {
		$html = $this->render_editor( $this->template_with( array( array( 'type' => 'text', 'attrs' => array( 'html' => '<strong>Bold</strong>' ) ) ) ) );

		$this->assertStringContainsString( '&lt;strong&gt;Bold&lt;/strong&gt;', $html );
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
