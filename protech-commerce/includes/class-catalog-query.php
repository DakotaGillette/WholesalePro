<?php
/**
 * Query-level catalog filtering. Two rules:
 *  - "Wholesale only" products are excluded from every product query for
 *    anyone who isn't an approved wholesale customer.
 *  - With the "hide" empty-price setting, products with no wholesale price
 *    at all are excluded from product-only queries for wholesale customers
 *    (via the parent-level flag ProductFields keeps in sync, since a
 *    variable product's prices live on its variations).
 *
 * Listings only: a query for one specific product's page is left alone,
 * so a direct link still resolves (see filter_product_query()).
 *
 * Applied on pre_get_posts, so it covers the shop/category/search
 * archives, [products] shortcodes, wc_get_products(), and the Store API's
 * /products endpoint behind the Blocks product grids — unlike the
 * woocommerce_product_is_visible filter (Pricing::filter_catalog_visibility(),
 * kept as a belt-and-braces fallback), which only hides at template level,
 * leaving pagination holes and wrong result counts, and never runs for
 * the Store API at all.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CatalogQuery
 */
class CatalogQuery {

	private static bool $suspended = false;

	public function register_hooks(): void {
		// After WC_Query::product_query() (priority 10) has turned the
		// shop/taxonomy archives into product queries.
		add_action( 'pre_get_posts', array( $this, 'filter_product_query' ), 20 );
	}

	/**
	 * Runs $callback with this filtering switched off — for backfills and
	 * anything else that must see the whole catalog regardless of who is
	 * logged in. pre_get_posts fires even for suppress_filters queries, so
	 * a flag is the only reliable way to opt out.
	 *
	 * @return mixed Whatever $callback returns.
	 */
	public static function without_filtering( callable $callback ) {
		$previous        = self::$suspended;
		self::$suspended = true;

		try {
			return $callback();
		} finally {
			self::$suspended = $previous;
		}
	}

	public function filter_product_query( \WP_Query $query ): void {
		if ( self::$suspended || is_admin() ) {
			return;
		}

		// Authenticated REST (wc/v3 for staff and connected apps) is a
		// management context, not a storefront.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_products' ) ) {
			return;
		}

		// A single product's own page is not a catalog listing. Filtering it
		// turned every wholesale-only product into a 404 for anyone who isn't
		// a wholesale customer — including staff previewing it — instead of
		// the page Pricing::filter_price_html() is written for (the product,
		// with an "approved wholesale accounts only" notice in place of the
		// price and no way to buy it). Likewise a wholesale customer opening
		// a product with no wholesale price by its URL gets the retail-only
		// note, not a 404. Found on staging with the Vendor Starter Kit.
		if ( $query->is_singular() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		$is_products_only = 'product' === $post_type
			|| ( is_array( $post_type ) && array( 'product' ) === array_values( array_unique( $post_type ) ) );

		// A plain site search (post_type '' / 'any') includes products too.
		// Other untyped queries (the blog index, feeds of posts) don't, so
		// they're left alone rather than paying for a postmeta join.
		$includes_products = $is_products_only
			|| 'any' === $post_type
			|| ( is_array( $post_type ) && in_array( 'product', $post_type, true ) )
			|| ( ( '' === $post_type || null === $post_type ) && $query->is_search() );

		if ( ! $includes_products ) {
			return;
		}

		if ( ! Roles::is_wholesale_customer() ) {
			// Safe on a mixed-post-type query: posts/pages have no such meta
			// and pass the NOT EXISTS branch.
			$clause = array(
				'relation' => 'OR',
				array(
					'key'     => ProductFields::META_WHOLESALE_ONLY,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => ProductFields::META_WHOLESALE_ONLY,
					'value'   => 'yes',
					'compare' => '!=',
				),
			);
		} elseif ( $is_products_only && 'hide' === Settings::empty_price_behavior() ) {
			$clause = array(
				'key'   => ProductFields::META_HAS_WHOLESALE_PRICE,
				'value' => 'yes',
			);
		} else {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();

		// An existing top-level OR group must be nested, or our clause
		// would just become one more alternative in it.
		if ( isset( $meta_query['relation'] ) && 'OR' === strtoupper( (string) $meta_query['relation'] ) ) {
			$meta_query = array( $meta_query );
		}

		$meta_query[] = $clause;

		$query->set( 'meta_query', $meta_query );
	}
}
