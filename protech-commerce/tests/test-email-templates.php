<?php
/**
 * The template library: validating what an editor submits, storing and
 * copying templates, binding one to a lifecycle email, seeding the starters
 * once and never again, and the settings the design inherits from.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailComposer;
use ProtechWholesale\EmailRenderer;
use ProtechWholesale\EmailStarters;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MergeTags;
use ProtechWholesale\MessagingSettings;

/**
 * Class Test_Email_Templates
 */
class Test_Email_Templates extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// The plugin seeds the starters when it first loads; every test here starts from an empty library.
		delete_option( EmailTemplates::OPTION );
	}

	/** @return array<string, mixed> */
	private function input( array $extra = array() ): array {
		return array_merge(
			array(
				'name'    => 'Spring restock',
				'kind'    => 'marketing',
				'subject' => 'Time to restock, {first_name}',
				'blocks'  => array(
					array( 'type' => 'heading', 'attrs' => array( 'text' => 'Hello {first_name}' ) ),
					array( 'type' => 'text', 'attrs' => array( 'html' => 'Body' ) ),
				),
			),
			$extra
		);
	}

	private function save( array $extra = array() ): string {
		return EmailTemplates::save( EmailTemplates::validate( $this->input( $extra ) )['template'] );
	}

	public function test_a_good_template_validates_without_errors(): void {
		$result = EmailTemplates::validate( $this->input() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'Spring restock', $result['template']['name'] );
		$this->assertCount( 2, $result['template']['blocks'] );
		$this->assertMatchesRegularExpression( '/^b_[a-z0-9]{4,12}$/', $result['template']['blocks'][0]['id'] );
	}

	public function test_validation_reports_a_missing_name_no_blocks_and_unknown_tags(): void {
		$result = EmailTemplates::validate( array( 'name' => '', 'blocks' => array() ) );

		$this->assertContains( 'Give the template a name.', $result['errors'] );
		$this->assertContains( 'Add at least one block.', $result['errors'] );

		$tags = EmailTemplates::validate( $this->input( array( 'subject' => 'Hi {nope}' ) ) );
		$this->assertStringContainsString( '{nope}', implode( ' ', $tags['errors'] ) );

		$order_only = EmailTemplates::validate( $this->input( array( 'subject' => 'Track {tracking_url}' ) ) );
		$this->assertStringContainsString( '{tracking_url}', implode( ' ', $order_only['errors'] ), 'Order-only tags are not available in a template.' );
	}

	public function test_an_unknown_block_type_is_dropped_and_reported(): void {
		$result = EmailTemplates::validate( $this->input( array( 'blocks' => array( array( 'type' => 'heading' ), array( 'type' => 'carousel' ) ) ) ) );

		$this->assertCount( 1, $result['template']['blocks'] );
		$this->assertStringContainsString( 'dropped', implode( ' ', $result['errors'] ) );
	}

	public function test_attributes_are_clamped_and_colors_validated(): void {
		$result = EmailTemplates::validate(
			$this->input(
				array(
					'blocks' => array(
						array( 'type' => 'spacer', 'attrs' => array( 'height' => 9999, 'bg' => 'red;background:url(x)' ) ),
						array( 'type' => 'button', 'attrs' => array( 'label' => 'Go', 'url' => 'javascript:alert(1)', 'bg_color' => '#12' ) ),
					),
				)
			)
		);

		$blocks = $result['template']['blocks'];

		$this->assertSame( 120, $blocks[0]['attrs']['height'] );
		$this->assertSame( '', $blocks[0]['attrs']['bg'], 'Not a hex color, so gone.' );
		$this->assertSame( '', $blocks[1]['attrs']['url'], 'A javascript: link is refused.' );
		$this->assertSame( '', $blocks[1]['attrs']['bg_color'] );
	}

	public function test_a_merge_tag_alone_is_kept_as_a_link_target(): void {
		$result = EmailTemplates::validate( $this->input( array( 'blocks' => array( array( 'type' => 'button', 'attrs' => array( 'label' => 'Shop', 'url' => '{shop_url}' ) ) ) ) ) );

		$this->assertSame( '{shop_url}', $result['template']['blocks'][0]['attrs']['url'] );
	}

	public function test_saving_mints_an_id_and_keeps_the_creation_time_on_the_next_save(): void {
		$id = $this->save();

		$this->assertMatchesRegularExpression( '/^t_[a-z0-9]{6,12}$/', $id );
		$this->assertTrue( EmailTemplates::exists( $id ) );

		$first = EmailTemplates::get( $id );
		$this->assertGreaterThan( 0, $first['created_at'] );

		$again = EmailTemplates::validate( $this->input( array( 'id' => $id, 'name' => 'Renamed' ) ) )['template'];
		EmailTemplates::save( $again );

		$second = EmailTemplates::get( $id );
		$this->assertSame( 'Renamed', $second['name'] );
		$this->assertSame( $first['created_at'], $second['created_at'] );
	}

	public function test_a_submission_without_style_header_or_footer_keeps_what_the_template_has(): void {
		$id = EmailTemplates::save( EmailTemplates::validate( $this->input( array( 'style' => array( 'brand' => '#aa0000' ), 'header' => array( 'show_logo' => '' ), 'footer' => array( 'text' => 'Kept', 'show_address' => '1' ) ) ) )['template'] );

		$stored = EmailTemplates::get( $id );
		$this->assertSame( '#aa0000', $stored['style']['brand'] );
		$this->assertFalse( $stored['header']['show_logo'] );

		$updated = EmailTemplates::validate( array( 'id' => $id, 'name' => 'Same', 'blocks' => $this->input()['blocks'] ) )['template'];

		$this->assertSame( '#aa0000', $updated['style']['brand'] );
		$this->assertFalse( $updated['header']['show_logo'] );
		$this->assertSame( 'Kept', $updated['footer']['text'] );
	}

	public function test_one_template_holds_a_lifecycle_slot_at_a_time(): void {
		$a = $this->save( array( 'name' => 'A' ) );
		$b = $this->save( array( 'name' => 'B' ) );

		EmailTemplates::bind_slot( $a, EmailTemplates::SLOT_WELCOME );
		$this->assertSame( $a, EmailTemplates::for_slot( EmailTemplates::SLOT_WELCOME )['id'] );

		EmailTemplates::bind_slot( $b, EmailTemplates::SLOT_WELCOME );
		$this->assertSame( $b, EmailTemplates::for_slot( EmailTemplates::SLOT_WELCOME )['id'] );
		$this->assertSame( '', EmailTemplates::get( $a )['slot'], 'Binding a second template releases the first.' );

		EmailTemplates::bind_slot( $b, '' );
		$this->assertNull( EmailTemplates::for_slot( EmailTemplates::SLOT_WELCOME ) );

		EmailTemplates::bind_slot( $b, 'not_a_slot' );
		$this->assertSame( '', EmailTemplates::get( $b )['slot'], 'An unknown slot is ignored.' );
	}

	public function test_a_copy_has_its_own_ids_a_new_name_and_no_slot(): void {
		$id = $this->save();
		EmailTemplates::bind_slot( $id, EmailTemplates::SLOT_WELCOME );

		$copy_id = EmailTemplates::duplicate( $id );
		$copy    = EmailTemplates::get( $copy_id );
		$orig    = EmailTemplates::get( $id );

		$this->assertNotSame( $id, $copy_id );
		$this->assertSame( 'Copy of Spring restock', $copy['name'] );
		$this->assertSame( '', $copy['slot'] );
		$this->assertSame( $id, EmailTemplates::for_slot( EmailTemplates::SLOT_WELCOME )['id'], 'The original keeps the slot.' );
		$this->assertNotSame( $orig['blocks'][0]['id'], $copy['blocks'][0]['id'] );
		$this->assertSame( $orig['blocks'][0]['attrs'], $copy['blocks'][0]['attrs'] );
		$this->assertSame( '', EmailTemplates::duplicate( 't_missing' ) );
	}

	public function test_deleting_removes_a_template(): void {
		$id = $this->save();
		EmailTemplates::delete( $id );

		$this->assertFalse( EmailTemplates::exists( $id ) );
		$this->assertSame( array(), EmailTemplates::choices() );
	}

	public function test_the_merge_tag_source_covers_the_subject_and_every_block(): void {
		$id     = $this->save();
		$source = EmailTemplates::merge_tag_source( $id );

		$this->assertStringContainsString( 'Time to restock, {first_name}', $source );
		$this->assertStringContainsString( 'Hello {first_name}', $source );
		$this->assertSame( '', EmailTemplates::merge_tag_source( 't_missing' ) );
	}

	public function test_the_starters_are_seeded_once_and_never_again(): void {
		$expected = count( EmailStarters::all() );

		$this->assertSame( $expected, EmailTemplates::seed_starters() );
		$this->assertCount( $expected, EmailTemplates::all() );
		$this->assertSame( 0, EmailTemplates::seed_starters(), 'A second run adds nothing.' );

		$blank = array_values( array_filter( EmailTemplates::all(), static fn( array $t ): bool => 'blank' === $t['seeded'] ) )[0];
		EmailTemplates::delete( $blank['id'] );

		$this->assertSame( 0, EmailTemplates::seed_starters(), 'A deleted starter does not come back.' );
	}

	public function test_a_later_release_can_add_named_starters_without_duplicating(): void {
		EmailTemplates::seed_starters();

		$this->assertSame( 0, EmailTemplates::seed_starters( array( 'blank' ) ), 'Already there.' );
		$this->assertSame( 0, EmailTemplates::seed_starters( array( 'no_such_starter' ) ) );

		$blank = array_values( array_filter( EmailTemplates::all(), static fn( array $t ): bool => 'blank' === $t['seeded'] ) )[0];
		EmailTemplates::delete( $blank['id'] );

		$this->assertSame( 1, EmailTemplates::seed_starters( array( 'blank' ) ), 'Named explicitly and missing, so it is created.' );
	}

	public function test_every_starter_is_valid_and_none_is_bound_to_a_lifecycle_email(): void {
		foreach ( EmailStarters::all() as $key => $starter ) {
			$result = EmailTemplates::validate( $starter );

			$this->assertSame( array(), $result['errors'], "Starter {$key} has errors." );
			$this->assertContains( $result['template']['category'], EmailTemplates::CATEGORIES, "Starter {$key} has no valid category." );
		}

		EmailTemplates::seed_starters();

		foreach ( EmailTemplates::all() as $template ) {
			$this->assertSame( '', $template['slot'], 'Seeding must never change what a customer receives.' );
		}
	}

	public function test_an_unrecognised_category_is_refused_and_a_recognised_one_is_carried_through_unedited(): void {
		$bogus = EmailTemplates::validate( $this->input( array( 'category' => 'not-a-real-category' ) ) )['template'];
		$this->assertSame( '', $bogus['category'] );

		$id = $this->save( array( 'category' => 'promotions' ) );

		$saved_again = EmailTemplates::validate( array( 'id' => $id, 'name' => 'Same', 'blocks' => $this->input()['blocks'] ) )['template'];
		$this->assertSame( 'promotions', $saved_again['category'], 'Category has no form field, so a normal save keeps it.' );
	}

	public function test_the_welcome_starter_renders_the_login_details_and_the_case_math(): void {
		EmailTemplates::seed_starters();

		$welcome  = array_values( array_filter( EmailTemplates::all(), static fn( array $t ): bool => 'welcome' === $t['seeded'] ) )[0];
		$customer = Protech_Test_Factory::wholesale_customer();
		$out      = EmailRenderer::render( $welcome, EmailRenderer::context( $customer ) );

		$this->assertStringContainsString( 'Log in to wholesale', $out['html'] );
		$this->assertStringContainsString( 'lost-password', $out['html'] );
		$this->assertStringContainsString( '8 displays = 1 case.', $out['html'] );
		$this->assertStringContainsString( 'The more you order, the more you save', $out['html'] );
		$this->assertStringContainsString( (string) get_userdata( $customer )->user_email, $out['html'], 'Their login address is filled in.' );
	}

	public function test_the_new_login_tags_are_available_everywhere_and_treated_as_links(): void {
		$this->assertArrayHasKey( 'login_url', MergeTags::all( '' ) );
		$this->assertArrayHasKey( 'lost_password_url', MergeTags::all( '' ) );

		$context = MergeTags::context_for_customer( Protech_Test_Factory::wholesale_customer() );

		$this->assertNotSame( '', $context['login_url'] );
		$this->assertStringContainsString( 'lost-password', $context['lost_password_url'] );
		$this->assertStringContainsString( '&#038;', MergeTags::fill( '{login_url}', array( 'login_url' => 'https://example.com/?a=1&b=2' ), 'html' ), 'A URL tag goes through esc_url().' );
	}

	public function test_the_design_settings_have_safe_accessors(): void {
		$this->assertSame( '#42649d', MessagingSettings::email_brand_color() );
		$this->assertSame( 600, MessagingSettings::email_width() );
		$this->assertSame( 0, MessagingSettings::email_logo_id() );
		$this->assertSame( '', MessagingSettings::email_heading_font() );
		$this->assertSame( '', MessagingSettings::email_link_color() );
		$this->assertSame( 24, MessagingSettings::email_mobile_padding() );

		update_option( MessagingSettings::OPT_EMAIL_BRAND_COLOR, 'not a color' );
		update_option( MessagingSettings::OPT_EMAIL_WIDTH, '9999' );
		update_option( MessagingSettings::OPT_EMAIL_LOGO_ID, '-5' );
		update_option( MessagingSettings::OPT_EMAIL_HEADING_FONT, 'not-a-font' );
		update_option( MessagingSettings::OPT_EMAIL_LINK_COLOR, 'not a color' );
		update_option( MessagingSettings::OPT_EMAIL_MOBILE_PADDING, '9999' );

		$this->assertSame( '#42649d', MessagingSettings::email_brand_color(), 'A bad value falls back to the default.' );
		$this->assertSame( 700, MessagingSettings::email_width(), 'Clamped to the widest supported.' );
		$this->assertSame( 0, MessagingSettings::email_logo_id() );
		$this->assertSame( '', MessagingSettings::email_heading_font(), 'Not a real font key, so "same as body".' );
		$this->assertSame( '', MessagingSettings::email_link_color(), 'Not a color, so "use the brand color".' );
		$this->assertSame( 24, MessagingSettings::email_mobile_padding(), 'Clamped to the historical default.' );

		update_option( MessagingSettings::OPT_EMAIL_HEADING_FONT, 'georgia' );
		update_option( MessagingSettings::OPT_EMAIL_LINK_COLOR, '#008000' );
		update_option( MessagingSettings::OPT_EMAIL_MOBILE_PADDING, '12' );

		$this->assertSame( 'georgia', MessagingSettings::email_heading_font() );
		$this->assertSame( '#008000', MessagingSettings::email_link_color() );
		$this->assertSame( 12, MessagingSettings::email_mobile_padding() );
	}

	public function test_the_library_list_shows_each_template_with_a_signed_preview_link(): void {
		EmailTemplates::seed_starters();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		EmailComposer::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Welcome to wholesale', $html );
		$this->assertStringContainsString( 'Restock reminder', $html );
		$this->assertStringContainsString( 'action=protech_preview_email_template', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_a_preview_carries_the_marketing_footer_and_a_service_email_does_not(): void {
		$marketing     = EmailTemplates::validate( $this->input() )['template'];
		$transactional = EmailTemplates::validate( $this->input( array( 'kind' => 'transactional' ) ) )['template'];
		$user_id       = Protech_Test_Factory::wholesale_customer();

		$this->assertStringContainsString( 'Unsubscribe', EmailComposer::preview_html( $marketing, $user_id ) );
		$this->assertStringNotContainsString( 'Unsubscribe', EmailComposer::preview_html( $transactional, $user_id ) );
	}

	public function test_the_preview_link_is_refused_without_a_valid_nonce(): void {
		$id = $this->save();

		$_GET['template_id'] = $id;
		$_REQUEST['_wpnonce'] = 'not-a-nonce';

		$this->expectException( WPDieException::class );

		( new EmailComposer() )->handle_preview();
	}

	public function tear_down(): void {
		unset( $_GET['template_id'], $_REQUEST['_wpnonce'] );
		delete_option( MessagingSettings::OPT_EMAIL_BRAND_COLOR );
		delete_option( MessagingSettings::OPT_EMAIL_WIDTH );
		delete_option( MessagingSettings::OPT_EMAIL_LOGO_ID );
		parent::tear_down();
	}
}
