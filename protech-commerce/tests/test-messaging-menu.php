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
		$this->assertSame( 'protech-messaging', MessagingTab::page_slug( 'home' ) );
		$this->assertSame( 'protech-messaging-emails', MessagingTab::page_slug( 'emails' ) );
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
			array( 'protech-messaging', 'protech-messaging-emails', 'protech-messaging-flows', 'protech-messaging-forms', 'protech-messaging-contacts', 'protech-messaging-log', 'protech-messaging-settings' ),
			$slugs
		);
	}

	/**
	 * Compose and the template library/editor are real pages (so their URLs,
	 * and the editor's asset gating on the templates screen id, keep working)
	 * but are registered under a parent no menu has, so they never show in
	 * the sidebar, and they highlight Emails while open.
	 */
	public function test_hidden_pages_are_registered_off_the_sidebar_and_highlight_emails(): void {
		global $menu, $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu    = array();
		$submenu = array();

		$tab = new MessagingTab();
		$tab->register_menu();

		$hidden = array_column( $submenu[ MessagingTab::HIDDEN_PARENT ] ?? array(), 2 );
		$this->assertSame( array( 'protech-messaging-compose', 'protech-messaging-templates' ), $hidden );
		$this->assertNotContains( 'protech-messaging-compose', array_column( $submenu['protech-messaging'], 2 ) );
		$this->assertNotContains( MessagingTab::HIDDEN_PARENT, array_column( $menu, 2 ) );

		$_GET = array( 'page' => 'protech-messaging-compose' );
		$this->assertSame( 'protech-messaging', $tab->highlight_parent( MessagingTab::HIDDEN_PARENT ) );
		$this->assertSame( 'protech-messaging-emails', $tab->highlight_submenu( null ) );

		// get_admin_page_parent() runs after the filter and resolves $plugin_page against $submenu,
		// so it must now land on Messaging rather than the hidden parent.
		$this->assertSame( 'protech-messaging-emails', $GLOBALS['plugin_page'] );
		$GLOBALS['pagenow'] = 'admin.php';
		$this->assertSame( 'protech-messaging', get_admin_page_parent() );
		unset( $GLOBALS['plugin_page'] );

		$_GET = array( 'page' => 'protech-messaging-log' );
		$this->assertSame( 'protech-messaging', $tab->highlight_parent( 'protech-messaging' ) );
		$this->assertNull( $tab->highlight_submenu( null ) );
	}

	public function test_the_retired_compliance_page_redirects_to_its_settings_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET     = array( 'page' => 'protech-messaging-compliance' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_retired_page' ) );

		$this->assertStringContainsString( 'page=protech-messaging-settings', $location );
		$this->assertStringContainsString( 'tab=compliance', $location );

		$_GET     = array( 'page' => 'some-other-plugin' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_retired_page' ) );
		$this->assertSame( '', $location );
	}

	/**
	 * Every settings field lands on exactly one tab, so none disappears from
	 * the screen and none is saved (a checkbox reset to "no") by two tabs.
	 */
	public function test_every_settings_field_is_on_exactly_one_tab(): void {
		$all  = ( new \ProtechWholesale\MessagingSettings() )->get_fields();
		$seen = array();

		foreach ( array_keys( \ProtechWholesale\MessagingSettingsScreen::tabs() ) as $tab ) {
			foreach ( \ProtechWholesale\MessagingSettingsScreen::fields_for_tab( $all, $tab ) as $field ) {
				$seen[] = ( $field['type'] ?? '' ) . ':' . ( $field['id'] ?? '' );
			}
		}

		$expected = array_map( static fn( array $field ): string => ( $field['type'] ?? '' ) . ':' . ( $field['id'] ?? '' ), $all );

		sort( $seen );
		sort( $expected );
		$this->assertSame( $expected, $seen );
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

	public function test_an_old_url_with_no_view_lands_on_emails_and_an_unknown_view_falls_back(): void {
		$_GET     = array( 'page' => 'protech-wholesale', 'tab' => 'messaging' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );
		$this->assertStringContainsString( 'page=protech-messaging-emails', $location );

		$_GET     = array( 'page' => 'protech-wholesale', 'tab' => 'messaging', 'view' => 'automations' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );
		$this->assertStringContainsString( 'page=protech-messaging-emails', $location );

		$_GET     = array( 'page' => 'protech-wholesale', 'tab' => 'messaging', 'view' => 'compliance' );
		$location = $this->follow_redirect( array( new MessagingTab(), 'redirect_legacy_url' ) );
		$this->assertStringContainsString( 'page=protech-messaging-settings', $location );
		$this->assertStringContainsString( 'tab=compliance', $location );

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
	 * The admin-post actions Messaging registers across its screens: the 13 from
	 * before class-messaging-tab.php was split into AutomationsScreen,
	 * ComposeScreen, LogScreen, ComplianceScreen and MessagingSettingsScreen
	 * (2.9.0), minus the five retired with the old rule editor (3.6.0, replaced
	 * by flows) plus FlowsScreen's own four. "Run automations now" moved to
	 * HomeScreen in 3.7.0.
	 */
	public function test_every_admin_post_action_is_still_registered_after_the_split(): void {
		$actions = array(
			'protech_run_automations_now',
			'protech_save_flow',
			'protech_toggle_flow',
			'protech_delete_flow',
			'protech_duplicate_flow',
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
