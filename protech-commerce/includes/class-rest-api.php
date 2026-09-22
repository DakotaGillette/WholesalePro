<?php
/**
 * The protech/v1 REST namespace: registers every route controller this
 * plugin exposes under it. One namespace, one permission check
 * (manage_woocommerce, the same capability every Messaging admin screen
 * already requires), so a future controller only has to register its
 * routes, not repeat the auth story.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestApi
 */
class RestApi {

	public const NAMESPACE = 'protech/v1';

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		( new RestTemplates() )->register_routes();
	}

	/** Every route in this namespace needs the same capability the Messaging admin screens do. */
	public static function permission_admin(): bool {
		return current_user_can( 'manage_woocommerce' );
	}
}
