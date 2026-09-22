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

	public function test_a_product_grid_stacks_on_a_phone(): void {
		// Three products across 3 columns: one full row, no padding cells needed.
		$three = array_map( static fn( $p ) => $p->get_id(), array( Protech_Test_Factory::simple_product( '5.50' ), Protech_Test_Factory::simple_product( '6.00' ), Protech_Test_Factory::simple_product( '7.00' ) ) );
		$full  = EmailRenderer::render( $this->template( array( $this->block( 'product_grid', array( 'product_ids' => $three, 'columns' => 3 ) ) ) ), $this->context() )['html'];

		// The document's only responsive rule targets .pw-col: without it on
		// every cell (the last row's blank padding cells included), a grid
		// narrower than the column count never stacks on a phone.
		$this->assertSame( 3, substr_count( $full, 'class="pw-col"' ), 'All 3 product cells must carry pw-col.' );

		$two  = array_map( static fn( $p ) => $p->get_id(), array( Protech_Test_Factory::simple_product( '5.50' ), Protech_Test_Factory::simple_product( '6.00' ) ) );
		$short = EmailRenderer::render( $this->template( array( $this->block( 'product_grid', array( 'product_ids' => $two, 'columns' => 3 ) ) ) ), $this->context() )['html'];

		$this->assertSame( 3, substr_count( $short, 'class="pw-col"' ), 'The 2 product cells plus the 1 blank padding cell must all carry pw-col.' );
	}

	public function test_the_document_is_a_fluid_card_that_fits_a_phone(): void {
		$html = EmailRenderer::render( $this->template( array( $this->block( 'heading' ) ) ), $this->context() )['html'];

		$this->assertStringContainsString( 'width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;', $html );
		$this->assertStringContainsString( '<!--[if mso]>', $html, 'Outlook on Windows gets a fixed-width table around the card.' );
		$this->assertStringNotContainsString( 'border-collapse', $html, 'It would remove the cellspacing the swatch grids depend on.' );
	}

	public function test_the_quantity_diagram_carries_its_own_font(): void {
		$html = EmailRenderer::render( $this->template( array( $this->block( 'explainer_quantities' ) ) ), $this->context( array( '_wholesale' => true ) ) )['html'];

		$this->assertStringContainsString( 'padding:20px 22px;font-family:Helvetica,Arial,sans-serif;', $html );
	}

	public function test_the_newest_products_never_include_ones_hidden_from_the_catalog(): void {
		$visible = Protech_Test_Factory::simple_product( '5.50' );
		$hidden  = Protech_Test_Factory::simple_product( '5.50' );
		$hidden->set_catalog_visibility( 'hidden' );
		$hidden->save();

		$ids = array_map(
			static fn( $p ): int => $p->get_id(),
			EmailBlocks::grid_products( array( 'mode' => 'newest', 'limit' => 12 ), $this->context() )
		);

		$this->assertContains( $visible->get_id(), $ids );
		$this->assertNotContains( $hidden->get_id(), $ids );

		$picked = array_map(
			static fn( $p ): int => $p->get_id(),
			EmailBlocks::grid_products( array( 'mode' => 'picked', 'product_ids' => array( $hidden->get_id() ) ), $this->context() )
		);

		$this->assertContains( $hidden->get_id(), $picked, 'A product picked by hand is always allowed.' );
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

	public function test_a_template_that_never_touches_the_new_design_keys_renders_exactly_as_before(): void {
		$template = $this->template( array( $this->block( 'heading', array( 'text' => 'Hi' ) ), $this->block( 'text', array( 'html' => 'Body' ) ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringNotContainsString( 'fonts.googleapis.com', $html, 'No web font chosen, so nothing is fetched.' );
		$this->assertStringNotContainsString( 'class="pw-row"', $html, 'Mobile padding left at the historical default, so no override class.' );
		$this->assertStringNotContainsString( '.pw-row{', $html, 'and no media-query rule for it either.' );
	}

	public function test_the_heading_font_matches_the_body_font_until_one_is_chosen(): void {
		$template = $this->template( array( $this->block( 'heading', array( 'text' => 'Hi' ) ) ), array( 'style' => array( 'font' => 'georgia' ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringContainsString( '<h2 style="margin:0;padding:0;font-family:Georgia', $html );

		$distinct = $this->template( array( $this->block( 'heading', array( 'text' => 'Hi' ) ) ), array( 'style' => array( 'font' => 'georgia', 'heading_font' => 'inter' ) ) );
		$html     = EmailRenderer::render( $distinct, $this->context() )['html'];

		// esc_attr() turns the quotes in the font stack into entities; a browser decodes them back before CSS ever sees them.
		$this->assertStringContainsString( 'font-family:&#039;Inter&#039;,Helvetica,Arial,sans-serif', $html );
	}

	public function test_a_web_font_ships_with_its_fallback_stack_and_is_hidden_from_outlook(): void {
		$template = $this->template( array( $this->block( 'heading', array( 'text' => 'Hi' ) ) ), array( 'style' => array( 'heading_font' => 'roboto' ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertMatchesRegularExpression( '#<!--\[if !mso]><!--><link rel="stylesheet" href="[^"]*fonts\.googleapis\.com[^"]*family=Roboto[^"]*"\s*/><!--<!\[endif]-->#', $html );
		$this->assertStringContainsString( 'font-family:&#039;Roboto&#039;,Helvetica,Arial,sans-serif', $html, 'A fallback stack follows the web font, for Outlook and a slow load alike.' );
	}

	public function test_mobile_padding_only_changes_the_media_query_when_set(): void {
		$template = $this->template( array( $this->block( 'text', array( 'html' => 'Body' ) ) ), array( 'style' => array( 'mobile_padding' => 12 ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringContainsString( 'class="pw-row"', $html );
		$this->assertStringContainsString( '.pw-row{padding-left:12px !important;padding-right:12px !important;}', $html );
	}

	public function test_a_product_grid_can_show_only_products_on_sale(): void {
		$on_sale = Protech_Test_Factory::simple_product();
		$on_sale->set_sale_price( '15.00' );
		$on_sale->save();

		Protech_Test_Factory::simple_product(); // Not on sale: $20.00 regular, no sale price.

		delete_transient( 'wc_products_onsale' ); // wc_get_product_ids_on_sale() caches; force a fresh read of what was just saved.

		$html = EmailRenderer::render( $this->template( array( $this->block( 'product_grid', array( 'mode' => 'on_sale', 'columns' => 1 ) ) ) ), $this->context() )['html'];

		$this->assertStringContainsString( '$15.00', $html );
		$this->assertStringNotContainsString( '$20.00', $html, 'The regular-priced product is not on sale, so it is excluded.' );
	}

	public function test_social_links_render_icons_only_for_filled_networks(): void {
		$html = EmailRenderer::render( $this->template( array( $this->block( 'social', array( 'facebook' => 'https://facebook.com/example', 'instagram' => '' ) ) ) ), $this->context() )['html'];

		$this->assertStringContainsString( 'href="https://facebook.com/example"', $html );
		$this->assertStringNotContainsString( '>IG<', $html, 'No Instagram URL, so no Instagram icon.' );
	}

	public function test_a_video_block_links_its_thumbnail_and_falls_back_without_one(): void {
		$with_link = EmailRenderer::render( $this->template( array( $this->block( 'video', array( 'url' => 'https://example.com/watch', 'alt' => 'Our latest video' ) ) ) ), $this->context() )['html'];

		$this->assertStringContainsString( 'href="https://example.com/watch"', $with_link );
		$this->assertStringContainsString( 'Watch the video', $with_link, 'No thumbnail picture set, so the text fallback panel shows.' );

		$without_url = EmailRenderer::render( $this->template( array( $this->block( 'video' ) ) ), $this->context() )['html'];
		$this->assertStringNotContainsString( 'Watch the video', $without_url, 'No link at all, so the block renders nothing.' );
	}

	public function test_the_html_block_is_refused_without_unfiltered_html_and_kept_verbatim_with_it(): void {
		$raw = array(
			'name'    => 'HTML block test',
			'kind'    => 'marketing',
			'subject' => 'Hi',
			'blocks'  => array( array( 'type' => 'html', 'attrs' => array( 'code' => '<div class="x">Hi <script>alert(1)</script></div>' ) ) ),
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = EmailTemplates::validate( $raw );
		$this->assertSame( array(), $result['template']['blocks'], 'A subscriber has no unfiltered_html, so the block is dropped.' );
		$this->assertNotEmpty( $result['errors'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$admin_result = EmailTemplates::validate( $raw );
		$this->assertSame( array(), $admin_result['errors'] );

		$html = EmailRenderer::render( $admin_result['template'], $this->context() )['html'];
		$this->assertStringContainsString( '<div class="x">Hi <script>alert(1)</script></div>', $html, 'Kept exactly as typed: this block is never run through inline_allowed().' );
	}

	public function test_hide_on_adds_the_matching_class_and_visible_to_gates_the_whole_block(): void {
		$hidden = EmailRenderer::render( $this->template( array( $this->block( 'text', array( 'html' => 'Body', 'hide_on' => 'mobile' ) ) ) ), $this->context() )['html'];
		$this->assertStringContainsString( 'class="pw-hide-mobile"', $hidden );

		$wholesale_only = $this->template( array( $this->block( 'text', array( 'html' => 'Wholesale only text', 'visible_to' => 'wholesale' ) ) ) );

		$this->assertStringContainsString( 'Wholesale only text', EmailRenderer::render( $wholesale_only, $this->context( array( '_wholesale' => true ) ) )['html'] );
		$this->assertStringNotContainsString( 'Wholesale only text', EmailRenderer::render( $wholesale_only, $this->context() )['html'] );
		$this->assertStringNotContainsString( 'Wholesale only text', EmailRenderer::render( $wholesale_only, $this->context() )['text'], 'The plain-text part must respect visible_to too.' );

		// A preview always shows everything, matching the existing explainer-block behavior.
		$preview = EmailRenderer::render( $wholesale_only, $this->context( array( '_preview' => true ) ) )['html'];
		$this->assertStringContainsString( 'Wholesale only text', $preview );
	}

	public function test_link_color_falls_back_from_the_template_to_the_site_setting_to_the_brand_color(): void {
		update_option( 'protech_wholesale_msg_email_link_color', '#00aa00' );

		$template = $this->template( array( $this->block( 'text', array( 'html' => 'See <a href="{shop_url}">the shop</a>.' ) ) ) );
		$html     = EmailRenderer::render( $template, $this->context() )['html'];
		$this->assertStringContainsString( '<a style="color:#00aa00;"', $html, 'No template link color, so the site setting applies.' );

		$own  = $this->template( array( $this->block( 'text', array( 'html' => 'See <a href="{shop_url}">the shop</a>.' ) ) ), array( 'style' => array( 'link_color' => '#0000aa' ) ) );
		$html = EmailRenderer::render( $own, $this->context() )['html'];
		$this->assertStringContainsString( '<a style="color:#0000aa;"', $html, 'The template\'s own link color wins over the site setting.' );
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

	/**
	 * The editor's canvas reads data-pw-block to know which card a click belongs to. It must
	 * exist, in order, in a preview, and never exist in a real send — the same guarantee the
	 * mso- guard below pins for kses, now pinned for this marker too.
	 */
	public function test_blocks_are_numbered_for_the_canvas_only_in_a_preview(): void {
		$template = $this->template(
			array(
				$this->block( 'heading', array( 'text' => 'One' ) ),
				$this->block( 'spacer' ), // Renders without going through EmailBlocks::row().
				$this->block( 'heading', array( 'text' => 'Three' ) ),
			)
		);

		$preview = EmailRenderer::render( $template, $this->context( array( '_preview' => true ) ) )['html'];

		$this->assertStringContainsString( 'data-pw-block="0"', $preview );
		$this->assertStringContainsString( 'data-pw-block="1"', $preview );
		$this->assertStringContainsString( 'data-pw-block="2"', $preview );
		$this->assertGreaterThan(
			strpos( $preview, 'data-pw-block="0"' ),
			strpos( $preview, 'data-pw-block="1"' ),
			'The markers appear in block order, so the editor can map a click back to the right card.'
		);

		$sent = EmailRenderer::render( $template, $this->context() )['html'];

		$this->assertStringNotContainsString( 'data-pw-block', $sent, 'Outside a preview, this never appears — it must never reach a real send.' );
	}

	public function test_a_block_that_renders_nothing_carries_no_marker(): void {
		// A heading with nothing typed: EmailBlocks::render() returns '' for it.
		$html = EmailRenderer::render( $this->template( array( $this->block( 'heading', array( 'text' => '' ) ) ) ), $this->context( array( '_preview' => true ) ) )['html'];

		$this->assertStringNotContainsString( 'data-pw-block', $html );
	}
}
