<?php
/**
 * Runs once on plugin activation: roles, default options, the /wholesale page.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 */
class Activator {

	public static function activate(): void {
		Roles::create_roles();
		self::create_default_options();
		self::create_portal_page();
		MessageLog::install_table();

		if ( MessagingSettings::enabled() ) {
			AutomationRunner::schedule_daily();
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		// Deliberately do not remove roles/options/pages on deactivation —
		// only on uninstall, and only if the "purge" setting is enabled.
		AutomationRunner::unschedule_all();
		flush_rewrite_rules();
	}

	private static function create_default_options(): void {
		foreach ( array_merge( Settings::get_defaults(), MessagingSettings::get_defaults() ) as $option => $value ) {
			if ( false === get_option( $option, false ) ) {
				add_option( $option, $value );
			}
		}
	}

	/**
	 * Create the /wholesale landing page if it doesn't already exist.
	 */
	private static function create_portal_page(): void {
		$existing = get_page_by_path( 'wholesale' );

		if ( $existing instanceof \WP_Post ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Wholesale', 'protech-wholesale' ),
				'post_name'    => 'wholesale',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[protech_wholesale_portal]',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			wc_get_logger()->error(
				sprintf( 'Failed to create /wholesale page: %s', $page_id->get_error_message() ),
				array( 'source' => 'protech-wholesale' )
			);
		}
	}
}
