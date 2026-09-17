<?php
/**
 * In-memory WooCommerce session for PHPUnit. WC_Session_Handler wants to
 * set a cookie the first time the cart gets contents; under PHPUnit the
 * headers are long gone, and with WP_DEBUG on that becomes an E_USER_NOTICE
 * (which the suite promotes to a failure). WooCommerce's own test suite
 * swaps in exactly this kind of mock; it isn't included in release zips,
 * which is what wp-env installs.
 *
 * @package ProtechWholesale
 */

/**
 * Class Protech_Mock_Session_Handler
 */
class Protech_Mock_Session_Handler extends WC_Session_Handler {

	public function init() {
		$this->_customer_id = '1';
		$this->_data        = array();
	}

	public function init_session_cookie() {}

	public function set_customer_session_cookie( $set ) {}

	public function save_data( $old_session_key = 0 ) {}

	public function destroy_session() {
		$this->_data  = array();
		$this->_dirty = false;
	}

	public function forget_session() {
		$this->_data  = array();
		$this->_dirty = false;
	}

	public function get_session( $customer_id, $default = false ) {
		return $default;
	}

	public function delete_session( $customer_id ) {}

	public function update_session_timestamp( $customer_id, $timestamp ) {}

	public function cleanup_sessions() {}
}
