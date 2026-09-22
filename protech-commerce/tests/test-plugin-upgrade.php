<?php
/**
 * Plugin::maybe_upgrade(): the message log table is recreated whenever an
 * upgrade runs, not only the one time (version 3) that originally created
 * it, a defence against the table having been dropped or lost since.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;
use ProtechWholesale\Plugin;

/**
 * Class Test_Plugin_Upgrade
 */
class Test_Plugin_Upgrade extends WP_UnitTestCase {

	public function test_upgrading_recreates_a_missing_message_log_table(): void {
		global $wpdb;

		$table = MessageLog::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$this->assertFalse( MessageLog::table_exists() );

		// Simulate a site still recorded at an older version, the ordinary
		// condition under which maybe_upgrade() actually does anything.
		update_option( Plugin::OPT_DB_VERSION, '6' );
		delete_transient( 'protech_wholesale_upgrading' );

		Plugin::instance()->maybe_upgrade();

		$this->assertTrue( MessageLog::table_exists() );
		$this->assertSame( Plugin::DB_VERSION, get_option( Plugin::OPT_DB_VERSION ) );
	}
}
