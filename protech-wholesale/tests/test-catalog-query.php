<?php
/**
 * Query-level catalog filtering (CatalogQuery): wholesale-only products
 * never reach a retail visitor's product queries, products with no
 * wholesale price are excluded for wholesale customers under the "hide"
 * setting, admin queries are untouched, and the flag backfill works.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\ProductFields;
use ProtechWholesale\Settings;

/**
 * Class Test_Catalog_Query
 */
class Test_Catalog_Query extends WP_UnitTestCase {

	/**
	 * @return int[] Published product IDs as the current user's storefront queries see them.
	 */
	private function visible_product_ids(): array {
		return array_map(
			'intval',
			wc_get_products(
				array(
					'status' => 'publish',
					'type'   => array( 'simple', 'variable' ),
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
	}

	public function test_wholesale_only_products_are_excluded_for_guests_and_retail_but_not_wholesale(): void {
		$public         = Protech_Test_Factory::simple_product( '5.50' );
		$wholesale_only = Protech_Test_Factory::simple_product( '5.50' );
		update_post_meta( $wholesale_only->get_id(), ProductFields::META_WHOLESALE_ONLY, 'yes' );

		wp_set_current_user( 0 );
		$this->assertContains( $public->get_id(), $this->visible_product_ids() );
		$this->assertNotContains( $wholesale_only->get_id(), $this->visible_product_ids() );

		wp_set_current_user( Protech_Test_Factory::retail_customer() );
		$this->assertNotContains( $wholesale_only->get_id(), $this->visible_product_ids() );

		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		$this->assertContains( $public->get_id(), $this->visible_product_ids() );
		$this->assertContains( $wholesale_only->get_id(), $this->visible_product_ids() );
	}

	public function test_products_without_a_wholesale_price_are_hidden_from_wholesale_customers_under_hide(): void {
		$priced   = Protech_Test_Factory::simple_product( '5.50' );
		$unpriced = Protech_Test_Factory::simple_product();
		$variable = Protech_Test_Factory::variable_product( array( 'blue', 'red' ), array( 'red' => '5.50' ) );

		update_option( Settings::OPT_EMPTY_PRICE_BEHAVIOR, 'hide' );
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		$ids = $this->visible_product_ids();
		$this->assertContains( $priced->get_id(), $ids );
		$this->assertContains( $variable['parent']->get_id(), $ids, 'A variable product priced per variation stays visible.' );
		$this->assertNotContains( $unpriced->get_id(), $ids );

		// Retail visitors still see the unpriced (retail) product.
		wp_set_current_user( 0 );
		$this->assertContains( $unpriced->get_id(), $this->visible_product_ids() );

		// And the "fallback" setting shows it to wholesale customers too.
		update_option( Settings::OPT_EMPTY_PRICE_BEHAVIOR, 'fallback' );
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		$this->assertContains( $unpriced->get_id(), $this->visible_product_ids() );
	}

	public function test_admin_queries_are_not_filtered(): void {
		$wholesale_only = Protech_Test_Factory::simple_product( '5.50' );
		update_post_meta( $wholesale_only->get_id(), ProductFields::META_WHOLESALE_ONLY, 'yes' );

		wp_set_current_user( 0 );
		set_current_screen( 'edit-product' );

		try {
			$this->assertContains( $wholesale_only->get_id(), $this->visible_product_ids() );
		} finally {
			set_current_screen( 'front' );
		}
	}

	public function test_an_existing_or_group_in_the_query_is_nested_rather_than_joined(): void {
		$wholesale_only = Protech_Test_Factory::simple_product( '5.50' );
		update_post_meta( $wholesale_only->get_id(), ProductFields::META_WHOLESALE_ONLY, 'yes' );
		update_post_meta( $wholesale_only->get_id(), '_protech_test_marker', 'a' );

		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_protech_test_marker',
						'value' => 'a',
					),
					array(
						'key'   => '_protech_test_marker',
						'value' => 'b',
					),
				),
			)
		);

		// Matches the OR group, but is wholesale-only: must still be excluded.
		$this->assertNotContains( $wholesale_only->get_id(), array_map( 'intval', $query->posts ) );
	}

	public function test_backfill_flags_every_product(): void {
		$priced   = Protech_Test_Factory::simple_product( '5.50' );
		$unpriced = Protech_Test_Factory::simple_product();
		delete_post_meta( $priced->get_id(), ProductFields::META_HAS_WHOLESALE_PRICE );
		delete_post_meta( $unpriced->get_id(), ProductFields::META_HAS_WHOLESALE_PRICE );

		// Even when run as a wholesale customer, the backfill sees everything.
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		update_option( Settings::OPT_EMPTY_PRICE_BEHAVIOR, 'hide' );

		$count = ProductFields::backfill_has_wholesale_price_flags();

		$this->assertGreaterThanOrEqual( 2, $count );
		$this->assertSame( 'yes', get_post_meta( $priced->get_id(), ProductFields::META_HAS_WHOLESALE_PRICE, true ) );
		$this->assertSame( 'no', get_post_meta( $unpriced->get_id(), ProductFields::META_HAS_WHOLESALE_PRICE, true ) );
	}
}
