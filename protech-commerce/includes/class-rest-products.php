<?php
/**
 * protech/v1/products?search= (3.9.0): a small product search for the new
 * email flow's "Bought this product" audience, so the Send step can offer
 * a type-to-find picker instead of asking for a product ID.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestProducts
 */
class RestProducts {

	public function register_routes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( RestApi::class, 'permission_admin' ),
			)
		);
	}

	/**
	 * Up to 20 published products matching the search, or the one asked for
	 * by `include` (to show the name of an already chosen product).
	 *
	 * @return array<int, array{id: int, name: string, sku: string}>
	 */
	public function search( \WP_REST_Request $request ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$include = absint( $request->get_param( 'include' ) );
		$args    = array(
			'limit'   => 20,
			'status'  => 'publish',
			'orderby' => 'title',
			'order'   => 'ASC',
		);

		if ( $include > 0 ) {
			$args['include'] = array( $include );
		} else {
			$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

			if ( '' === $search ) {
				return array();
			}

			$args['s'] = $search;
		}

		$out = array();

		foreach ( wc_get_products( $args ) as $product ) {
			if ( $product instanceof \WC_Product ) {
				$out[] = array(
					'id'   => $product->get_id(),
					'name' => wp_strip_all_tags( $product->get_name() ),
					'sku'  => (string) $product->get_sku(),
				);
			}
		}

		return $out;
	}
}
