<?php
/**
 * Where an approved wholesale customer lands after logging in: the
 * "After a wholesale login, go to" setting, falling back to the shop.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MyAccount;
use ProtechWholesale\Settings;

/**
 * Class Test_Login_Landing
 */
class Test_Login_Landing extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPT_LOGIN_LANDING_URL );
		parent::tear_down();
	}

	public function test_empty_setting_lands_on_the_shop(): void {
		update_option( Settings::OPT_LOGIN_LANDING_URL, '' );

		$this->assertSame( wc_get_page_permalink( 'shop' ), Settings::login_landing_url() );
	}

	public function test_a_url_on_this_site_is_used_as_is(): void {
		$url = home_url( '/product/protech-premium-matte-sleeves/' );
		update_option( Settings::OPT_LOGIN_LANDING_URL, $url );

		$this->assertSame( $url, Settings::login_landing_url() );
	}

	public function test_an_offsite_url_falls_back_to_the_shop(): void {
		update_option( Settings::OPT_LOGIN_LANDING_URL, 'https://example.org/somewhere' );

		$this->assertSame( wc_get_page_permalink( 'shop' ), Settings::login_landing_url() );
	}

	public function test_wholesale_customers_are_redirected_to_the_landing_url_on_login(): void {
		$url = home_url( '/product/protech-premium-matte-sleeves/' );
		update_option( Settings::OPT_LOGIN_LANDING_URL, $url );

		$customer = get_user_by( 'id', Protech_Test_Factory::wholesale_customer() );
		$retail   = get_user_by( 'id', Protech_Test_Factory::retail_customer() );

		$my_account = new MyAccount();

		$this->assertSame( $url, $my_account->login_redirect( home_url( '/my-account/' ), '', $customer ) );
		$this->assertSame( home_url( '/my-account/' ), $my_account->login_redirect( home_url( '/my-account/' ), '', $retail ) );
	}
}
