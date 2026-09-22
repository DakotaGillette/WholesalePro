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

	/**
	 * A DROP or CREATE TABLE issued on $wpdb's own connection does not error,
	 * but this MySQL 8 test container does not make it visible to a
	 * follow-up SHOW TABLES on that *same* connection within the WP test
	 * suite's per-test transaction (confirmed directly in CI: the DROP
	 * returns true with an empty last_error, yet MessageLog::table_exists()
	 * still reports it present). A fresh connection sees the real,
	 * already-committed state, since DDL auto-commits regardless of what the
	 * issuing connection's own view of it is.
	 */
	private function table_really_exists( string $table ): bool {
		$mysqli = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$result = $mysqli->query( 'SHOW TABLES LIKE \'' . $mysqli->real_escape_string( $table ) . '\'' );
		$exists = $result instanceof mysqli_result && $result->num_rows > 0;
		$mysqli->close();

		return $exists;
	}

	public function test_upgrading_recreates_a_missing_message_log_table(): void {
		global $wpdb;

		$table = MessageLog::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$mysqli  = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$matches = array();
		$result  = $mysqli->query( "SHOW TABLES LIKE '%protech%'" );
		while ( $result instanceof mysqli_result && ( $row = $result->fetch_row() ) ) {
			$matches[] = $row[0];
		}
		$mysqli->close();

		$this->assertFalse(
			$this->table_really_exists( $table ),
			sprintf(
				'DB_HOST="%s" DB_NAME="%s" table="%s" (len %d, hex %s); tables matching %%protech%%: %s',
				DB_HOST,
				DB_NAME,
				$table,
				strlen( $table ),
				bin2hex( $table ),
				$matches ? implode( ', ', $matches ) : '(none)'
			)
		);

		// Simulate a site still recorded at an older version, the ordinary
		// condition under which maybe_upgrade() actually does anything.
		update_option( Plugin::OPT_DB_VERSION, '6' );
		delete_transient( 'protech_wholesale_upgrading' );

		Plugin::instance()->maybe_upgrade();

		$this->assertTrue( $this->table_really_exists( $table ) );
		$this->assertSame( Plugin::DB_VERSION, get_option( Plugin::OPT_DB_VERSION ) );
	}
}
