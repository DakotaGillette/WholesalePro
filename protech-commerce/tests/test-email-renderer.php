<?php
/**
 * Rendering a template into an email: a full HTML document with the blocks in
 * order, merge tags filled and escaped, the footer the caller supplies,
 * wholesale-only blocks hidden from retail readers, and a real plain-text
 * alternative. Includes the guard that composed HTML is never passed through
 * wp_kses_post().
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailBlocks;
use ProtechWholesale\EmailRenderer;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\Settings;

/**
 * Class Test_Email_Renderer
 */
class Test_Email_Renderer extends WP_UnitTestCase {

	/**
	 * A cleaned template built the way the editor would, from raw blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $extra
	 * @return array<string, mixed>
	 */
	private function template( array $blocks, array $extra = array() ): array {
		$input = array_merge(
			array(
				'name'    => 'Test',
				'kind'    => 'marketing',
				'subject' => 'Hello {first_name}',
				'blocks'  => $blocks,
			),
			$extra
		);

		return EmailTemplates::validate( $input )['template'];
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function context( array $extra = array() ): array {
		return array_merge(
			array(
				'first_name'  => 'Ada',
				'email'       => 'ada@example.com',
				'shop_url'    => 'https://example.com/shop/',
				'orders_url'  => 'https://example.com/orders/',
				'_user_id'    => 0,
				'_wholesale'  => false,
				'_preview'    => false,
			),
			$extra
		);
	}

	private function block( string $type, array $attrs = array(), array $children = array() ): array {
		$block = array(
			'type'  => $type,
			'attrs' => $attrs,
		);

		if ( array() !== $children ) {
			$block['children'] = $children;
		}

		return $block;
	}

	public function test_a_template_renders_as_a_full_table_based_document(): void {
		$out = EmailRenderer::render( $this->template( array( $this->block( 'heading', array( 'text' => 'Hi' ) ) ) ), $this->context() );

		$this->assertStringStartsWith( '<!DOCTYPE', $out['html'] );
		$this->assertStringContainsString( '<table', $out['html'] );
		$this->assertStringContainsString( 'role="presentation"', $out['html'] );
		$this->assertStringContainsString( '</html>', $out['html'] );
		$this->assertStringNotContainsString( 'template_header', $out['html'], 'Not wrapped in WooCommerce\'s email header.' );
	}

	public function test_the_finished_html_is_never_reduced_by_kses(): void {
		// The regression guard: wp_kses_post() deletes declarations outside its
		// whitelist. If anyone reintroduces it into the render path, mso-hide
		// disappears from the preheader and this fails.
		$template = $this->template( array( $this->block( 'heading', array( 'text' => 'Hi' ) ) ), array( 'preheader' => 'Preview text' ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringContainsString( 'mso-hide:all', $html );
		$this->assertNotSame( $html, wp_kses_post( $html ), 'Precondition: kses really would strip something from this document.' );
	}

	public function test_merge_tags_are_filled_and_escaped(): void {
		$template = $this->template(
			array(
				$this->block( 'heading', array( 'text' => 'Hi {first_name}' ) ),
				$this->block( 'button', array( 'label' => 'Shop', 'url' => '{shop_url}' ) ),
			)
		);

		$html = EmailRenderer::render( $template, $this->context( array( 'first_name' => '<b>Ada</b> & Co' ) ) )['html'];

		$this->assertStringContainsString( 'Hi &lt;b&gt;Ada&lt;/b&gt; &amp; Co', $html );
		$this->assertStringNotContainsString( '<b>Ada', $html );
		$this->assertStringContainsString( 'href="https://example.com/shop/"', $html );
	}

	public function test_an_unknown_tag_renders_as_nothing(): void {
		$html = EmailRenderer::render( $this->template( array( $this->block( 'heading', array( 'text' => 'Hi {nope}!' ) ) ) ), $this->context() )['html'];

		$this->assertStringContainsString( '>Hi !<', $html );
		$this->assertStringNotContainsString( '{nope}', $html );
	}

	public function test_the_subject_is_filled_from_the_template(): void {
		$this->assertSame( 'Hello Ada', EmailRenderer::subject( $this->template( array( $this->block( 'spacer' ) ) ), $this->context() ) );
	}

	public function test_text_splits_paragraphs_and_drops_script(): void {
		$template = $this->template( array( $this->block( 'text', array( 'html' => "One\n\nTwo <script>alert(1)</script><strong>bold</strong>" ) ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertSame( 2, substr_count( $html, '<p style="margin:0 0 14px;' ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
	}

	public function test_links_in_text_carry_the_brand_color(): void {
		$template = $this->template( array( $this->block( 'text', array( 'html' => 'See <a href="{shop_url}">the shop</a>.' ) ) ), array( 'style' => array( 'brand' => '#aa0000' ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringContainsString( '<a style="color:#aa0000;" href="https://example.com/shop/">the shop</a>', $html );
	}

	public function test_a_marketing_footer_supplied_by_the_caller_appears_in_html_and_text(): void {
		$footer = '<p>You are receiving this. <a href="https://example.com/u">Unsubscribe</a><br />1 Main St</p>';
		$out    = EmailRenderer::render( $this->template( array( $this->block( 'heading' ) ) ), $this->context(), array( 'footer_html' => $footer ) );

		$this->assertStringContainsString( 'Unsubscribe', $out['html'] );
		$this->assertStringContainsString( 'Unsubscribe', $out['text'] );
		$this->assertStringContainsString( '1 Main St', $out['text'] );
	}

	public function test_without_a_footer_there_is_no_unsubscribe_link(): void {
		$out = EmailRenderer::render( $this->template( array( $this->block( 'heading' ) ), array( 'kind' => 'transactional' ) ), $this->context() );

		$this->assertStringNotContainsString( 'Unsubscribe', $out['html'] );
	}

	public function test_the_plain_text_alternative_is_real_text(): void {
		$template = $this->template(
			array(
				$this->block( 'heading', array( 'text' => 'Big news' ) ),
				$this->block( 'text', array( 'html' => 'Read <a href="https://example.com/x">more</a>.' ) ),
				$this->block( 'button', array( 'label' => 'Shop', 'url' => '{shop_url}' ) ),
			)
		);
		$text = EmailRenderer::render( $template, $this->context() )['text'];

		$this->assertStringContainsString( 'BIG NEWS', $text );
		$this->assertStringContainsString( 'more (https://example.com/x)', $text );
		$this->assertStringContainsString( 'Shop: https://example.com/shop/', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	public function test_wholesale_explainers_are_hidden_from_retail_and_shown_to_wholesale(): void {
		$template = $this->template( array( $this->block( 'explainer_quantities' ), $this->block( 'explainer_ladder' ) ) );

		$retail = EmailRenderer::render( $template, $this->context() );
		$this->assertStringNotContainsString( 'displays = 1 case', $retail['html'] );
		$this->assertStringNotContainsString( 'The more you order', $retail['html'] );

		$wholesale = EmailRenderer::render( $template, $this->context( array( '_wholesale' => true ) ) );
		$this->assertStringContainsString( '8 displays = 1 case.', $wholesale['html'] );
		$this->assertStringContainsString( 'The more you order, the more you save', $wholesale['html'] );
		$this->assertStringContainsString( '8 displays = 1 case', $wholesale['text'] );

		$preview = EmailRenderer::render( $template, $this->context( array( '_preview' => true ) ) );
		$this->assertStringContainsString( '8 displays = 1 case.', $preview['html'], 'A preview shows them so the admin can see the block.' );
	}

	public function test_the_explainers_draw_from_the_store_settings(): void {
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '12' );

		$html = EmailRenderer::render( $this->template( array( $this->block( 'explainer_quantities' ) ) ), $this->context( array( '_wholesale' => true ) ) )['html'];

		$this->assertStringContainsString( '12 displays = 1 case.', $html );
	}

	public function test_a_product_grid_shows_the_wholesale_price_to_wholesale_and_the_shop_price_to_retail(): void {
		$product  = Protech_Test_Factory::simple_product( '5.50' ); // Shop price 20.00.
		$customer = Protech_Test_Factory::wholesale_customer();
		$template = $this->template( array( $this->block( 'product_grid', array( 'product_ids' => array( $product->get_id() ), 'columns' => 1 ) ) ) );

		$wholesale = EmailRenderer::render( $template, $this->context( array( '_user_id' => $customer, '_wholesale' => true ) ) )['html'];
		$this->assertStringContainsString( '$5.50 per pack', $wholesale );
		$this->assertStringNotContainsString( '&#36;', $wholesale, 'A real dollar sign, never the entity.' );

		$retail = EmailRenderer::render( $template, $this->context() )['html'];
		$this->assertStringContainsString( '$20.00', $retail );
		$this->assertStringNotContainsString( 'per pack', $retail );
	}

	public function test_an_empty_product_grid_renders_nothing(): void {
		$html = EmailRenderer::render( $this->template( array( $this->block( 'product_grid', array( 'product_ids' => array( 999999 ) ) ), $this->block( 'heading', array( 'text' => 'After' ) ) ) ), $this->context() )['html'];

		$this->assertStringNotContainsString( 'protech-grid', $html );
		$this->assertStringContainsString( 'After', $html );
	}

	public function test_columns_render_side_by_side_and_only_one_level_deep(): void {
		$columns = $this->block(
			'columns',
			array( 'count' => 2 ),
			array(
				array( $this->block( 'heading', array( 'text' => 'Left' ) ) ),
				array(
					$this->block( 'heading', array( 'text' => 'Right' ) ),
					$this->block( 'columns', array( 'count' => 2 ), array( array( $this->block( 'heading', array( 'text' => 'Nested' ) ) ), array() ) ),
				),
			)
		);

		$html = EmailRenderer::render( $this->template( array( $columns ) ), $this->context() )['html'];

		$this->assertSame( 2, substr_count( $html, 'class="pw-col"' ) );
		$this->assertStringContainsString( 'Left', $html );
		$this->assertStringContainsString( 'Right', $html );
		$this->assertStringNotContainsString( 'Nested', $html, 'Columns inside columns are dropped when saving.' );
		$this->assertStringContainsString( '@media', $html, 'The one media query that stacks the columns on a phone.' );
	}

	public function test_an_image_with_a_url_renders_and_one_with_nothing_is_skipped(): void {
		$with = EmailRenderer::render( $this->template( array( $this->block( 'image', array( 'url' => 'https://example.com/a.jpg', 'alt' => 'A' ) ) ) ), $this->context() )['html'];
		$this->assertStringContainsString( '<img src="https://example.com/a.jpg"', $with );

		$without = EmailRenderer::render( $this->template( array( $this->block( 'image' ), $this->block( 'heading', array( 'text' => 'Kept' ) ) ) ), $this->context() )['html'];
		$this->assertStringNotContainsString( '<img', $without );
		$this->assertStringContainsString( 'Kept', $without );
	}

	public function test_the_width_and_brand_come_from_the_site_settings_unless_the_template_sets_them(): void {
		update_option( 'protech_wholesale_msg_email_width', '520' );
		update_option( 'protech_wholesale_msg_email_brand_color', '#112233' );

		$template = $this->template( array( $this->block( 'button', array( 'label' => 'Go', 'url' => '{shop_url}' ) ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringContainsString( 'width:520px', $html );
		$this->assertStringContainsString( 'background:#112233', $html );

		$own  = $this->template( array( $this->block( 'button', array( 'label' => 'Go', 'url' => '{shop_url}' ) ) ), array( 'style' => array( 'width' => 640, 'brand' => '#445566' ) ) );
		$html = EmailRenderer::render( $own, $this->context() )['html'];

		$this->assertStringContainsString( 'width:640px', $html );
		$this->assertStringContainsString( 'background:#445566', $html );
	}

	public function test_the_header_shows_the_store_name_when_there_is_no_logo(): void {
		update_option( 'blogname', 'Protech Test Store' );

		$html = EmailRenderer::render( $this->template( array( $this->block( 'spacer' ) ) ), $this->context() )['html'];

		$this->assertStringContainsString( 'Protech Test Store', $html );
	}

	public function test_a_hand_written_block_with_missing_attributes_still_renders(): void {
		$rows = EmailBlocks::render( array( 'type' => 'heading', 'attrs' => array( 'text' => 'Bare' ) ), $this->context(), EmailRenderer::style( array() ) );

		$this->assertStringContainsString( 'Bare', $rows );
		$this->assertSame( '', EmailBlocks::render( array( 'type' => 'no_such_block' ), $this->context(), EmailRenderer::style( array() ) ) );
	}
}
