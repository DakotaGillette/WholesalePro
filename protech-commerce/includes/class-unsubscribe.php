<?php
/**
 * The {unsubscribe_url} link appended to marketing emails: a per-user
 * random token (not an HMAC of a site salt) so a link keeps working
 * across a salt rotation and can be told apart from a forged one without
 * needing the customer to log in.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Unsubscribe
 */
class Unsubscribe {

	public const ACTION      = 'protech_unsubscribe';
	public const META_TOKEN  = '_protech_wholesale_unsub_token';
	public const QUERY_FLAG  = 'protech_unsubscribed';

	public function register_hooks(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The real portal page's URL when one is published (it need not be at
	 * "/wholesale": SetupChecks::portal_page_id() finds it by shortcode,
	 * not by slug), or that literal path as a last resort when no such
	 * page exists yet.
	 */
	private static function portal_url(): string {
		$page_id = SetupChecks::portal_page_id();

		return $page_id > 0 ? (string) get_permalink( $page_id ) : home_url( '/wholesale' );
	}

	private static function token_for( int $user_id ): string {
		$token = (string) get_user_meta( $user_id, self::META_TOKEN, true );

		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_user_meta( $user_id, self::META_TOKEN, $token );
		}

		return $token;
	}

	public static function url( int $user_id ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'uid'    => $user_id,
				't'      => self::token_for( $user_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function handle(): void {
		$user_id = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		$token   = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

		$valid = self::process( $user_id, $token );

		// A wholesale account lands on the wholesale page, which shows its own confirmation.
		// Anyone else (a retail customer) gets a plain confirmation instead of a wholesale login screen.
		if ( ! $valid || Roles::is_wholesale_customer( $user_id ) ) {
			wp_safe_redirect( add_query_arg( self::QUERY_FLAG, '1', self::portal_url() ) );
			exit;
		}

		wp_die(
			wp_kses_post(
				sprintf(
					/* translators: 1: site name, 2: shop link. */
					__( '<p>You have been unsubscribed from marketing email from %1$s. You will still get emails about your orders.</p><p><a href="%2$s">Back to the shop</a></p>', 'protech-wholesale' ),
					esc_html( get_bloginfo( 'name' ) ),
					esc_url( wc_get_page_permalink( 'shop' ) )
				)
			),
			esc_html__( 'Unsubscribed', 'protech-wholesale' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * The token check and consent update, split out from handle() (which
	 * always redirects) so it can be exercised directly — a real 'exit'
	 * statement can't be caught by a test the way wp_die() can.
	 *
	 * @return bool True if the token was valid and the opt-out was recorded.
	 */
	public static function process( int $user_id, string $token ): bool {
		$stored = $user_id ? (string) get_user_meta( $user_id, self::META_TOKEN, true ) : '';

		if ( ! $user_id || '' === $stored || ! hash_equals( $stored, $token ) ) {
			return false;
		}

		SmsConsent::record( $user_id, array( 'email_marketing' => false ), SmsConsent::SOURCE_UNSUBSCRIBE_LINK );

		return true;
	}
}
