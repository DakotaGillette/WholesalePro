<?php
/**
 * Messaging is its own top-level admin section (2.1.0): one sub-menu page
 * per view, one URL builder, and old Wholesale-tab URLs that still land in
 * the right place.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessagingTab;

/**
 * Class Test_Messaging_Menu
 */
class Test_Messaging_Menu extends WP_UnitTestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_the_landing_view_owns_the_top_level_slug_and_the_rest_hang_off_it(): void {
		$this->assertSame( 'protech-messaging', MessagingTab::page_slug( 'automations' ) );
		$this->assertSame( 'protech-messaging-compose', MessagingTab::page_slug( 'compose' ) );
		$this->assertSame( 'protech-messaging-log', MessagingTab::page_slug( 'log' ) );
	}

	public function test_urls_point_at_the_new_section_and_keep_their_arguments(): void {
		$url = MessagingTab::url( 'compose', array( 'ids' => 5 ) );

		$this->assertStringContainsString( 'admin.php?', $url );
		$this->assertStringContainsString( 'page=protech-messaging-compose', $url );
		$this->assertStringContainsString( 'ids=5', $url );
		$this->assertStringNotContainsString( 'protech-wholesale', $url );
		$this->assertStringNotContainsString( 'tab=messaging', $url );
	}

	public function test_the_menu_has_a_top_level_item_and_one_sub_page_per_view(): void {
		global $menu, $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu    = array();
		$submenu = array();

		( new MessagingTab() )->register_menu();

		$this->assertContains( 'protech-messaging', array_column( $menu, 2 ) );

		$slugs = array_column( $submenu['protech-messaging'] ?? array(), 2 );

		$this->assertSame(
			array( 'protech-messaging', 'protech-messaging-compose', 'protech-messaging-templates', 'protech-messaging-contacts', 'protech-messaging-forms', 'protech-messaging-log', 'protech-messaging-compliance', 'protech-messaging-settings' ),
			$slugs
		);
	}

	private function follow_redirect( callable $fn ): string {
		$location = '';

		$capture = static function ( $target ) use ( &$location ) {
			$location = (string) $target;
			throw new RuntimeException( 'redirect' );
		};

		add_filter( 'wp_redirect', $capture );

		try {
			$fn();
		} catch ( RuntimeException $e ) {
			// Expected: the redirect short-circuits exit.
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		return $location;
	}

	public function test_an_old_wholesale_tab_url_redirects_to_the_same_view_with_its_arguments(): void {
		$_GET = array(
			'page'    => 'protech-wholesale',
			'tab'     => 'messaging',
			'view'    => 'compose',
			'ids'     => '7',
		);

		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );

		$this->assertStringContainsString( 'page=protech-messaging-compose', $location );
		$this->assertStringContainsString( 'ids=7', $location );
		$this->assertStringNotContainsString( 'tab=messaging', $location );
	}

	public function test_an_old_url_with_no_view_lands_on_automations_and_an_unknown_view_falls_back(): void {
		$_GET     = array( 'page' => 'protech-wholesale', 'tab' => 'messaging' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );
		$this->assertStringContainsString( 'page=protech-messaging', $location );
		$this->assertStringNotContainsString( 'protech-messaging-', $location );

		$_GET     = array( 'page' => 'protech-wholesale', 'tab' => 'messaging', 'view' => 'nonsense' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );
		$this->assertStringNotContainsString( 'nonsense', $location );
	}

	public function test_other_wholesale_tabs_are_never_redirected(): void {
		$_GET     = array( 'page' => 'protech-wholesale', 'tab' => 'customers' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );

		$this->assertSame( '', $location );
	}

	/**
	 * The 13 admin-post actions Messaging registered before class-messaging-tab.php
	 * was split into AutomationsScreen, ComposeScreen, LogScreen, ComplianceScreen
	 * and MessagingSettingsScreen (2.9.0), a pure move, so every one of them must
	 * still be registered by something, whichever class it now lives on.
	 */
	public function test_every_admin_post_action_is_still_registered_after_the_split(): void {
		$actions = array(
			'protech_save_automation',
			'protech_preview_automation',
			'protech_send_test_automation',
			'protech_toggle_automation',
			'protech_delete_automation',
			'protech_run_automations_now',
			'protech_message_customers',
			'protech_review_message',
			'protech_edit_message',
			'protech_send_message',
			'protech_send_test_message',
			'protech_export_consent',
			'protech_test_brevo_connection',
		);

		foreach ( $actions as $action ) {
			$this->assertNotFalse( has_action( 'admin_post_' . $action ), "admin_post_{$action} is not registered." );
		}
	}

	/**
	 * A form that has a hidden `action` field and a button whose `formaction` only adds `?action=` to
	 * the address is not doing what it looks like: PHP lets the POST value beat the query string, so
	 * the button runs the form's own action (a real send, say) instead. A button must carry its action
	 * as its own name and value.
	 */
	public function test_no_button_relies_on_a_query_string_action(): void {
		foreach ( glob( PROTECH_WHOLESALE_DIR . 'includes/*.php' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				"/formaction=[^>]*add_query_arg\\(\\s*'action'/s",
				(string) file_get_contents( $file ),
				basename( $file ) . " has a button whose action would lose to the form's hidden action field."
			);
		}
	}
}
