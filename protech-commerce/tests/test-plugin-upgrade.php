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

	public function test_upgrading_calls_install_table_unconditionally_not_only_at_version_3(): void {
		$calls = 0;
		$count = static function () use ( &$calls ): void {
			++$calls;
		};

		add_action( 'protech_wholesale_message_log_table_installed', $count );

		// Version 6 skips every numbered step in maybe_upgrade() (none of the
		// version_compare() blocks for steps 2 through 6 apply going from 6 to
		// 7), so if install_table() still runs, it can only be the new
		// unconditional call at the top, not the version-3-specific one.
		update_option( Plugin::OPT_DB_VERSION, '6' );
		delete_transient( 'protech_wholesale_upgrading' );

		Plugin::instance()->maybe_upgrade();

		remove_action( 'protech_wholesale_message_log_table_installed', $count );

		$this->assertSame( 1, $calls, 'install_table() should run once as the unconditional safety net, even when no numbered step applies.' );
		$this->assertTrue( MessageLog::table_exists() );
		$this->assertSame( Plugin::DB_VERSION, get_option( Plugin::OPT_DB_VERSION ) );
	}

	public function test_upgrading_does_nothing_when_already_at_the_current_version(): void {
		update_option( Plugin::OPT_DB_VERSION, Plugin::DB_VERSION );

		$calls = 0;
		$count = static function () use ( &$calls ): void {
			++$calls;
		};

		add_action( 'protech_wholesale_message_log_table_installed', $count );
		Plugin::instance()->maybe_upgrade();
		remove_action( 'protech_wholesale_message_log_table_installed', $count );

		$this->assertSame( 0, $calls, 'Already at the current version, maybe_upgrade() should return immediately.' );
	}
}
