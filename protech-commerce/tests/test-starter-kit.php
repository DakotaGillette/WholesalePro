<?php
/**
 * Starter kits (StarterKit): what a kit contains (one display of every
 * color, topped up with the filler colors to the target), how it copes
 * with an out-of-stock color, that adding it puts the REAL variations in
 * the cart at the right pack counts, that the quote prices it at the tier
 * the cart will reach, and that the kit product itself can never be bought.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Settings;
use ProtechWholesale\StarterKit;
use ProtechWholesale\VolumePricing;

/**
 * Class Test_Starter_Kit
 */
class Test_Starter_Kit extends WP_UnitTestCase {

	/** @var array{parent: WC_Product_Variable, variations: array<string, WC_Product_Variation>} */
	private array $sleeves;

	private int $kit_id;

	private int $customer_id;

	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPT_DEFAULT_CASE_SIZE, '10' );
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '8' );
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '5' );
		update_option( Settings::OPT_BULK_THRESHOLD_CASES, '16' );
		update_option( Settings::OPT_VOLUME_PRICE, '5.00' );
		update_option( Settings::OPT_BULK_PRICE, '4.50' );

		$this->sleeves = Protech_Test_Factory::variable_product(
			array( 'black', 'white', 'red' ),
			array(
				'black' => '5.50',
				'white' => '5.50',
				'red'   => '5.50',
			)
		);

		$this->customer_id = Protech_Test_Factory::wholesale_customer();

		$kit          = Protech_Test_Factory::simple_product();
		$this->kit_id = $kit->get_id();

		update_post_meta( $this->kit_id, StarterKit::META_ENABLED, 'yes' );
		update_post_meta( $this->kit_id, StarterKit::META_SOURCE, $this->sleeves['parent']->get_id() );
		update_post_meta( $this->kit_id, StarterKit::META_TARGET_DISPLAYS, 5 );
		update_post_meta(
			$this->kit_id,
			StarterKit::META_FILLERS,
			array( $this->variation( 'black' ), $this->variation( 'white' ) )
		);

		WC()->cart->empty_cart();
	}

	private function variation( string $color ): int {
		return $this->sleeves['variations'][ $color ]->get_id();
	}

	/**
	 * @return array<int, int> variation id => displays.
	 */
	private function displays_by_variation( array $composition ): array {
		$map = array();

		foreach ( $composition['lines'] as $line ) {
			$map[ $line['variation_id'] ] = $line['displays'];
		}

		return $map;
	}

	public function test_kit_is_one_of_every_color_topped_up_with_the_fillers(): void {
		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		// 3 colors, target 5: Black and White each get a second display.
		$this->assertSame(
			array(
				$this->variation( 'black' ) => 2,
				$this->variation( 'white' ) => 2,
				$this->variation( 'red' )   => 1,
			),
			$this->displays_by_variation( $composition )
		);
		$this->assertSame( 5, $composition['displays'] );
		$this->assertSame( 50, $composition['packs'] );
		$this->assertSame( array(), $composition['unavailable'] );
	}

	public function test_enough_colors_means_one_of_each_and_no_fillers(): void {
		update_post_meta( $this->kit_id, StarterKit::META_TARGET_DISPLAYS, 2 );

		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		$this->assertSame( array( 1, 1, 1 ), array_values( $this->displays_by_variation( $composition ) ) );
		$this->assertSame( 3, $composition['displays'] );
	}

	public function test_an_out_of_stock_color_is_left_out_and_covered_by_the_fillers(): void {
		$red = wc_get_product( $this->variation( 'red' ) );
		$red->set_stock_status( 'outofstock' );
		$red->save();

		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		$this->assertSame(
			array(
				$this->variation( 'black' ) => 3,
				$this->variation( 'white' ) => 2,
			),
			$this->displays_by_variation( $composition )
		);
		$this->assertSame( 5, $composition['displays'] );
		$this->assertSame( array( 'Red' ), $composition['unavailable'] );
	}

	public function test_a_color_with_no_wholesale_price_is_simply_not_in_the_kit(): void {
		delete_post_meta( $this->variation( 'red' ), \ProtechWholesale\ProductFields::META_WHOLESALE_PRICE );

		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		$this->assertArrayNotHasKey( $this->variation( 'red' ), $this->displays_by_variation( $composition ) );
		$this->assertSame( array(), $composition['unavailable'], 'Not a stock problem, so not reported as one.' );
		$this->assertSame( 5, $composition['displays'], 'The fillers still make up the target.' );
	}

	public function test_kit_without_a_variable_source_is_empty(): void {
		update_post_meta( $this->kit_id, StarterKit::META_SOURCE, 0 );

		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		$this->assertSame( array(), $composition['lines'] );
		$this->assertSame( 0, $composition['displays'] );
	}

	public function test_adding_a_kit_puts_the_real_variations_in_the_cart(): void {
		wp_set_current_user( $this->customer_id );

		$result = StarterKit::add_to_cart( $this->kit_id, 1, $this->customer_id );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['added_lines'] );
		$this->assertSame( 5, $result['added_displays'] );
		$this->assertSame( array(), $result['notes'] );

		$cart = WC()->cart->get_cart();
		$this->assertCount( 3, $cart );

		$packs_by_variation = array();
		foreach ( $cart as $item ) {
			$this->assertNotSame( $this->kit_id, (int) $item['product_id'], 'The kit product itself is never in the cart.' );
			$packs_by_variation[ (int) $item['variation_id'] ] = (int) $item['quantity'];
		}

		$this->assertSame( 20, $packs_by_variation[ $this->variation( 'black' ) ] );
		$this->assertSame( 20, $packs_by_variation[ $this->variation( 'white' ) ] );
		$this->assertSame( 10, $packs_by_variation[ $this->variation( 'red' ) ] );

		$totals = VolumePricing::get_totals_for_items( $cart, $this->customer_id );
		$this->assertSame( 5.0, $totals['displays'] );
		$this->assertSame( VolumePricing::TIER_VOLUME, VolumePricing::get_tier_for_totals( $totals['displays'], $totals['cases'] ) );

		WC()->cart->empty_cart();
	}

	public function test_two_kits_double_every_line(): void {
		wp_set_current_user( $this->customer_id );

		$result = StarterKit::add_to_cart( $this->kit_id, 2, $this->customer_id );

		$this->assertSame( 10, $result['added_displays'] );
		$this->assertSame( 100, (int) array_sum( wp_list_pluck( WC()->cart->get_cart(), 'quantity' ) ) );

		WC()->cart->empty_cart();
	}

	public function test_quote_prices_the_kit_at_the_tier_the_cart_will_reach(): void {
		wp_set_current_user( $this->customer_id );

		// A 5-display kit lands exactly on the (5-display) Volume threshold:
		// 50 packs at the $5.00 Volume price, with free shipping.
		$quote = StarterKit::get_quote( $this->kit_id, 1, $this->customer_id );

		$this->assertSame( VolumePricing::TIER_VOLUME, $quote['tier'] );
		$this->assertSame( 250.0, $quote['total'] );
		$this->assertTrue( $quote['free_shipping'] );
		$this->assertStringContainsString( '5.00', $quote['pack_price_html'] );
		$this->assertStringContainsString( '5 displays', $quote['summary'] );

		// Below the threshold it is Standard pricing: 4 displays at $5.50.
		update_post_meta( $this->kit_id, StarterKit::META_TARGET_DISPLAYS, 4 );
		$quote = StarterKit::get_quote( $this->kit_id, 1, $this->customer_id );

		$this->assertSame( VolumePricing::TIER_STANDARD, $quote['tier'] );
		$this->assertSame( 220.0, $quote['total'] );
		$this->assertFalse( $quote['free_shipping'] );
	}

	public function test_kit_product_is_never_purchasable_and_stays_visible_to_wholesale(): void {
		$kit = wc_get_product( $this->kit_id );

		$this->assertFalse( $kit->is_purchasable() );

		update_option( Settings::OPT_EMPTY_PRICE_BEHAVIOR, 'hide' );
		wp_set_current_user( $this->customer_id );

		$this->assertTrue( $kit->is_visible(), 'No wholesale price of its own, yet still in the wholesale catalog.' );

		// The query-level filter reads this flag; a kit counts as priced.
		\ProtechWholesale\ProductFields::sync_has_wholesale_price_flag( $this->kit_id );
		$this->assertSame( 'yes', get_post_meta( $this->kit_id, \ProtechWholesale\ProductFields::META_HAS_WHOLESALE_PRICE, true ) );
	}

	public function test_one_of_each_mode_ignores_the_target_and_fillers(): void {
		update_post_meta( $this->kit_id, StarterKit::META_ONE_OF_EACH, 'yes' );

		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		// Target is 5 and Black/White are configured as fillers, but "one of
		// every color" ignores both: exactly one of each of the 3 colors.
		$this->assertSame(
			array( 1, 1, 1 ),
			array_values( $this->displays_by_variation( $composition ) )
		);
		$this->assertSame( 3, $composition['displays'] );
		$this->assertSame( 3, $composition['target'], '"target" reports the real count reached, not the ignored fixed target.' );
	}

	public function test_one_of_each_mode_picks_up_a_newly_added_color_with_no_config_change(): void {
		update_post_meta( $this->kit_id, StarterKit::META_ONE_OF_EACH, 'yes' );

		// A color added to the source product after the kit was set up —
		// the whole point of this mode is that nothing on the kit itself
		// needs editing when this happens.
		$blue = new WC_Product_Variation();
		$blue->set_parent_id( $this->sleeves['parent']->get_id() );
		$blue->set_attributes( array( 'color' => 'blue' ) );
		$blue->set_regular_price( '9.99' );
		$blue->set_status( 'publish' );
		$blue->set_manage_stock( false );
		$blue->set_stock_status( 'instock' );
		$blue->save();
		update_post_meta( $blue->get_id(), \ProtechWholesale\ProductFields::META_WHOLESALE_PRICE, '5.50' );

		$composition = StarterKit::get_composition( $this->kit_id, $this->customer_id );

		$this->assertSame( 4, $composition['displays'] );
		$this->assertArrayHasKey( $blue->get_id(), $this->displays_by_variation( $composition ) );
	}

	public function test_retail_customers_cannot_add_a_kit(): void {
		$retail_id = Protech_Test_Factory::retail_customer();
		wp_set_current_user( $retail_id );

		$result = StarterKit::add_to_cart( $this->kit_id, 1, $retail_id );

		$this->assertWPError( $result );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_variable_product_is_its_own_one_of_every_color_source(): void {
		$parent_id = $this->sleeves['parent']->get_id();

		$this->assertTrue( StarterKit::is_every_color_source( $parent_id ) );
		$this->assertFalse( StarterKit::is_every_color_source( $this->kit_id ), 'A kit product is not.' );
		$this->assertFalse( StarterKit::is_every_color_source( Protech_Test_Factory::simple_product( '5.50' )->get_id() ), 'A simple product has no colors to add.' );

		$composition = StarterKit::get_composition( $parent_id, $this->customer_id );

		$this->assertSame( 3, $composition['displays'] );
		$this->assertSame( array( 1, 1, 1 ), array_values( $this->displays_by_variation( $composition ) ), 'One display of each color, no fillers.' );
	}

	public function test_every_color_picks_up_a_color_added_later(): void {
		$parent_id = $this->sleeves['parent']->get_id();

		$blue = new WC_Product_Variation();
		$blue->set_parent_id( $parent_id );
		$blue->set_attributes( array( 'color' => 'blue' ) );
		$blue->set_regular_price( '9.99' );
		$blue->set_status( 'publish' );
		$blue->set_manage_stock( false );
		$blue->set_stock_status( 'instock' );
		$blue->save();
		update_post_meta( $blue->get_id(), \ProtechWholesale\ProductFields::META_WHOLESALE_PRICE, '5.50' );

		$composition = StarterKit::get_composition( $parent_id, $this->customer_id );

		$this->assertSame( 4, $composition['displays'] );
		$this->assertArrayHasKey( $blue->get_id(), $this->displays_by_variation( $composition ) );
	}

	public function test_every_color_adds_one_display_of_each_to_the_cart(): void {
		wp_set_current_user( $this->customer_id );
		WC()->cart->empty_cart();

		$result = StarterKit::add_to_cart( $this->sleeves['parent']->get_id(), 1, $this->customer_id );

		$this->assertNotWPError( $result );
		$this->assertSame( 3, $result['added_lines'] );
		$this->assertSame( 3, $result['added_displays'] );
		$this->assertSame( 30, (int) array_sum( wp_list_pluck( WC()->cart->get_cart(), 'quantity' ) ), '3 displays x 10 packs.' );
	}

	public function test_every_color_is_refused_for_a_retail_customer_and_for_a_simple_product(): void {
		$retail_id = Protech_Test_Factory::retail_customer();
		wp_set_current_user( $retail_id );

		$this->assertWPError( StarterKit::add_to_cart( $this->sleeves['parent']->get_id(), 1, $retail_id ) );

		wp_set_current_user( $this->customer_id );

		$this->assertWPError( StarterKit::add_to_cart( Protech_Test_Factory::simple_product( '5.50' )->get_id(), 1, $this->customer_id ) );
	}

	public function test_the_button_renders_for_wholesale_on_a_variable_product_only(): void {
		global $product;

		$product = $this->sleeves['parent'];
		wp_set_current_user( $this->customer_id );

		ob_start();
		( new StarterKit() )->render_every_color();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Add one display of every color', $html );
		$this->assertStringContainsString( 'One display of each of the 3 colors: 3 displays, 30 packs.', $html );

		wp_set_current_user( Protech_Test_Factory::retail_customer() );

		ob_start();
		( new StarterKit() )->render_every_color();
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing for a retail customer.' );

		$product = null;
		wp_set_current_user( 0 );
	}

	public function test_the_button_block_says_plainly_which_color_is_out_of_stock(): void {
		global $product;

		$red = wc_get_product( $this->variation( 'red' ) );
		$red->set_stock_status( 'outofstock' );
		$red->save();

		$product = $this->sleeves['parent'];
		wp_set_current_user( $this->customer_id );

		$composition = StarterKit::get_composition( $product->get_id(), $this->customer_id );
		$this->assertCount( 1, $composition['out_colors'] );
		$this->assertSame( 2, $composition['displays'] );

		ob_start();
		( new StarterKit() )->render_every_color();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'protech-everycolor-alert', $html );
		$this->assertStringContainsString( 'Out of stock: ', $html );
		$this->assertStringContainsString( 'each of the 2 colors in stock', $html );
		$this->assertStringContainsString( 'Add one display of each color in stock', $html );
		$this->assertStringContainsString( 'class="is-out"', $html, 'The unavailable color keeps a crossed-out swatch.' );

		$product = null;
		wp_set_current_user( 0 );
	}

	public function test_the_quote_note_is_plain_text_with_a_real_dollar_sign(): void {
		$quote = StarterKit::get_quote( $this->sleeves['parent']->get_id(), 1, $this->customer_id );

		$this->assertStringContainsString( '$', $quote['note'] );
		$this->assertStringNotContainsString( '&#36;', $quote['note'], 'The script prints the note with textContent, which would show the entity literally.' );
		$this->assertStringNotContainsString( '&', $quote['pack_price_html'] );
	}
}
