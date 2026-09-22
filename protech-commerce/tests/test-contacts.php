<?php
/**
 * The contacts directory: one row per email, kept in sync from a linked
 * WordPress account or, for a guest, from checkout billing details; order
 * stats refreshed on status change; a manual unsubscribe that actually
 * reaches SmsConsent's real gate for a linked account.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Contacts;
use ProtechWholesale\ContactsScreen;
use ProtechWholesale\Roles;
use ProtechWholesale\SmsConsent;

/**
 * Class Test_Contacts
 */
class Test_Contacts extends WP_UnitTestCase {

	public function test_a_wp_user_gets_exactly_one_contact_kept_in_sync(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'ada@example.com', 'first_name' => 'Ada', 'role' => 'customer' ) );

		$id_a = Contacts::for_user( $user_id );
		$id_b = Contacts::for_user( $user_id );

		$this->assertSame( $id_a, $id_b, 'Syncing the same user twice must not create a second contact.' );

		$contact = Contacts::get( $id_a );
		$this->assertSame( 'ada@example.com', $contact['email'] );
		$this->assertSame( 'Ada', $contact['first_name'] );
		$this->assertSame( Contacts::SOURCE_WP_USER, $contact['source'] );
		$this->assertSame( Contacts::STATUS_SUBSCRIBED, $contact['status'] );
	}

	public function test_opting_out_by_user_meta_is_reflected_on_the_next_sync(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'opt@example.com', 'role' => 'customer' ) );
		Contacts::for_user( $user_id );

		SmsConsent::record( $user_id, array( 'email_marketing' => false ), SmsConsent::SOURCE_MY_ACCOUNT );
		$id = Contacts::for_user( $user_id );

		$this->assertSame( Contacts::STATUS_UNSUBSCRIBED, Contacts::get( $id )['status'] );
	}

	public function test_a_guest_order_creates_a_transactional_only_contact(): void {
		$product = Protech_Test_Factory::simple_product();
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Grace' );
		$order->set_billing_email( 'grace@example.com' );
		$order->calculate_totals();
		$order->save();

		Contacts::sync_from_order( $order );

		$contact = Contacts::get_by_email( 'grace@example.com' );

		$this->assertNotNull( $contact );
		$this->assertSame( 0, (int) $contact['user_id'] );
		$this->assertSame( Contacts::SOURCE_GUEST_CHECKOUT, $contact['source'] );
		$this->assertSame( Contacts::STATUS_TRANSACTIONAL_ONLY, $contact['status'] );
	}

	public function test_a_logged_in_checkout_is_not_captured_as_a_guest(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.com', 'role' => 'customer' ) );
		$product = Protech_Test_Factory::simple_product();
		$order   = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->add_product( $product, 1 );
		$order->set_billing_email( 'member@example.com' );
		$order->calculate_totals();
		$order->save();

		Contacts::sync_from_order( $order );

		// sync_from_order() alone must not have created anything for a logged-in checkout:
		// that path is the user hooks' job, so a member never gets a second, guest-sourced row.
		$this->assertNull( Contacts::get_by_email( 'member@example.com' ) );
	}

	public function test_a_returning_guests_status_is_not_reset_by_a_later_order(): void {
		$product = Protech_Test_Factory::simple_product();

		$first = wc_create_order();
		$first->add_product( $product, 1 );
		$first->set_billing_email( 'repeat@example.com' );
		$first->calculate_totals();
		$first->save();
		Contacts::sync_from_order( $first );

		$id = Contacts::get_by_email( 'repeat@example.com' )['id'];
		Contacts::record_manual_unsubscribe( (int) $id, 'test', 0 );

		$second = wc_create_order();
		$second->add_product( $product, 1 );
		$second->set_billing_email( 'repeat@example.com' );
		$second->calculate_totals();
		$second->save();
		Contacts::sync_from_order( $second );

		$this->assertSame( Contacts::STATUS_UNSUBSCRIBED, Contacts::get_by_email( 'repeat@example.com' )['status'] );
	}

	public function test_order_stats_refresh_on_processing_for_a_member_and_a_guest(): void {
		$product = Protech_Test_Factory::simple_product();

		$user_id      = self::factory()->user->create( array( 'user_email' => 'buyer@example.com', 'role' => 'customer' ) );
		$member_order = Protech_Test_Factory::order_for( $user_id, $product, 1, 'pending' );
		$member_order->set_status( 'processing' );
		$member_order->save();

		Contacts::refresh_order_stats( $member_order->get_id(), 'pending', 'processing', $member_order );

		$member_contact = Contacts::get_by_email( 'buyer@example.com' );
		$this->assertSame( 1, (int) $member_contact['order_count'] );
		$this->assertGreaterThan( 0, (float) $member_contact['lifetime_value'] );

		$guest_order = wc_create_order();
		$guest_order->add_product( $product, 2 );
		$guest_order->set_billing_email( 'guestbuyer@example.com' );
		$guest_order->calculate_totals();
		$guest_order->set_status( 'processing' );
		$guest_order->save();

		Contacts::refresh_order_stats( $guest_order->get_id(), 'pending', 'processing', $guest_order );

		$guest_contact = Contacts::get_by_email( 'guestbuyer@example.com' );
		$this->assertNotNull( $guest_contact, 'refresh_order_stats() must create the guest contact if sync_from_order() has not run yet.' );
		$this->assertSame( 1, (int) $guest_contact['order_count'] );
	}

	public function test_a_manual_unsubscribe_on_a_linked_user_reaches_the_real_consent_gate(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'unsub@example.com', 'role' => 'customer' ) );
		$id      = Contacts::for_user( $user_id );

		Contacts::record_manual_unsubscribe( $id, 'Asked by phone.', 0 );

		$this->assertSame( 'no', SmsConsent::state( $user_id )['email_marketing'], 'The real gate every send checks must actually change.' );
		$this->assertSame( Contacts::STATUS_UNSUBSCRIBED, Contacts::get( $id )['status'] );
		$this->assertCount( 1, Contacts::consent_log_for( $id ) );
	}

	public function test_backfill_covers_customer_and_wholesale_roles_and_is_idempotent(): void {
		self::factory()->user->create( array( 'user_email' => 'retail@example.com', 'role' => 'customer' ) );
		self::factory()->user->create( array( 'user_email' => 'wholesale@example.com', 'role' => Roles::CUSTOMER ) );
		self::factory()->user->create( array( 'user_email' => 'pending@example.com', 'role' => Roles::PENDING ) );
		self::factory()->user->create( array( 'user_email' => 'admin@example.com', 'role' => 'administrator' ) );

		$created = Contacts::backfill();

		$this->assertSame( 3, $created );
		$this->assertNotNull( Contacts::get_by_email( 'retail@example.com' ) );
		$this->assertNotNull( Contacts::get_by_email( 'wholesale@example.com' ) );
		$this->assertNotNull( Contacts::get_by_email( 'pending@example.com' ) );
		$this->assertNull( Contacts::get_by_email( 'admin@example.com' ), 'A plain administrator with no customer role is not backfilled.' );

		$this->assertSame( 0, Contacts::backfill(), 'Running it again creates nothing new.' );
	}

	/** Plugin::init() already registered this screen's hooks for the whole test run; registering again here would duplicate them for every later test. */
	public function test_the_contacts_screens_admin_post_actions_are_registered(): void {
		$this->assertNotFalse( has_action( 'admin_post_' . ContactsScreen::UNSUBSCRIBE_ACTION ) );
		$this->assertNotFalse( has_action( 'admin_post_' . ContactsScreen::EXPORT_ACTION ) );
	}

	public function test_search_and_status_filters_narrow_the_query(): void {
		self::factory()->user->create( array( 'user_email' => 'findme@example.com', 'first_name' => 'Findme', 'role' => 'customer' ) );
		self::factory()->user->create( array( 'user_email' => 'other@example.com', 'role' => 'customer' ) );
		Contacts::backfill();

		$result = Contacts::query( array( 'search' => 'Findme' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'findme@example.com', $result['rows'][0]['email'] );

		$subscribed = Contacts::query( array( 'status' => Contacts::STATUS_SUBSCRIBED ) );
		$this->assertSame( 2, $subscribed['total'] );
	}
}
