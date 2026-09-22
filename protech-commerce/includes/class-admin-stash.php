<?php
/**
 * A brief per-admin transient that carries validation errors and dry-run
 * results across the redirect every admin-post handler in Messaging ends
 * with (render_page() has already echoed the page's <h1> by the time a
 * view's body renders, so nothing downstream can send its own redirect
 * header). Shared by every Messaging screen, keyed by name and the
 * current admin, so 'compose_form' and 'automation_form' never collide
 * even though both are written by the same admin.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminStash
 */
class AdminStash {

	private const TTL = 5 * MINUTE_IN_SECONDS;

	private static function key( string $name ): string {
		return 'protech_wholesale_msg_stash_' . $name . '_' . get_current_user_id();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function stash( string $name, array $data ): void {
		set_transient( self::key( $name ), $data, self::TTL );
	}

	/**
	 * Reads and clears: a stash only ever survives the one redirect it was
	 * written for.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function unstash( string $name ): ?array {
		$key  = self::key( $name );
		$data = get_transient( $key );
		delete_transient( $key );

		return is_array( $data ) ? $data : null;
	}
}
