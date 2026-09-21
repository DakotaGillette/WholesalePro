<?php
/**
 * BrevoClient: key resolution (this plugin's own setting, else the
 * Brevo WordPress plugin's own sib_api_key_v3 option), request shaping,
 * and error/retry classification. Brevo itself is never contacted —
 * every call is answered by a canned response through pre_http_request,
 * the same pattern test-updater.php uses for GitHub.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\BrevoClient;
use ProtechWholesale\MessagingSettings;

/**
 * Class Test_Brevo_Client
 */
class Test_Brevo_Client extends WP_UnitTestCase {

	/** @var array|WP_Error|null */
	private $canned = null;

	/** @var array<int, array{url: string, args: array}> */
	private array $requested = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( MessagingSettings::OPT_BREVO_API_KEY );
		delete_option( 'sib_api_key_v3' );
		$this->requested = array();
		add_filter( 'pre_http_request', array( $this, 'answer_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * @param false|array|WP_Error $preempt
	 * @param array                $args
	 * @param string               $url
	 * @return false|array|WP_Error
	 */
	public function answer_http( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api.brevo.com' ) ) {
			return $preempt;
		}

		$this->requested[] = array( 'url' => $url, 'args' => $args );

		return $this->canned;
	}

	private function json( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
		);
	}

	public function test_is_configured_prefers_this_plugins_own_key(): void {
		$this->assertFalse( BrevoClient::is_configured() );

		update_option( 'sib_api_key_v3', 'sib-key' );
		$this->assertTrue( BrevoClient::is_configured() );

		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'own-key' );
		$this->assertSame( 'own-key', MessagingSettings::brevo_api_key() );
	}

	public function test_send_email_posts_the_expected_payload_and_auth_header(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = $this->json( 201, array( 'messageId' => 'msg-1' ) );

		$result = ( new BrevoClient() )->send_email(
			array(
				'sender'      => array( 'name' => 'Store', 'email' => 'store@example.com' ),
				'to'          => array( array( 'email' => 'customer@example.com' ) ),
				'subject'     => 'Hi',
				'htmlContent' => '<p>Hi</p>',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'msg-1', $result['data']['messageId'] );
		$this->assertCount( 1, $this->requested );
		$this->assertStringContainsString( '/smtp/email', $this->requested[0]['url'] );
		$this->assertSame( 'test-key', $this->requested[0]['args']['headers']['api-key'] );

		$body = json_decode( $this->requested[0]['args']['body'], true );
		$this->assertSame( 'customer@example.com', $body['to'][0]['email'] );
	}

	public function test_a_4xx_error_surfaces_brevos_message_and_is_not_retryable(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = $this->json( 400, array( 'code' => 'invalid_parameter', 'message' => 'Sender not valid' ) );

		$result = ( new BrevoClient() )->send_email( array( 'sender' => array(), 'to' => array(), 'subject' => '', 'htmlContent' => '' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Sender not valid', $result['error'] );
		$this->assertFalse( $result['retryable'] );
	}

	public function test_a_429_or_5xx_response_is_retryable(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = $this->json( 429, array( 'message' => 'Too many requests' ) );

		$result = ( new BrevoClient() )->send_sms( '', '+15555550100', 'hi', 'transactional' );

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['retryable'] );
	}

	public function test_a_network_failure_is_retryable_and_never_throws(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$result = ( new BrevoClient() )->get_account();

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertStringContainsString( 'timed out', $result['error'] );
	}

	public function test_no_api_key_fails_without_a_network_request(): void {
		$result = ( new BrevoClient() )->send_sms( '', '+15555550100', 'hi', 'transactional' );

		$this->assertFalse( $result['ok'] );
		$this->assertCount( 0, $this->requested );
	}

	public function test_blacklist_lookup_is_cached_and_a_404_means_not_blacklisted(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = $this->json( 404, array( 'message' => 'Contact does not exist' ) );

		$client = new BrevoClient();
		$this->assertFalse( $client->is_sms_blacklisted( 'nobody@example.com' ) );
		$this->assertFalse( $client->is_sms_blacklisted( 'nobody@example.com' ) );
		$this->assertCount( 1, $this->requested, 'The second lookup should be served from the transient cache.' );
	}

	public function test_a_blacklisted_contact_is_reported_true(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = $this->json( 200, array( 'email' => 'stopped@example.com', 'smsBlacklisted' => true ) );

		$this->assertTrue( ( new BrevoClient() )->is_sms_blacklisted( 'stopped@example.com' ) );
	}

	public function test_an_unreachable_brevo_reports_blacklist_status_as_unknown(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		$this->canned = new WP_Error( 'http_request_failed', 'timed out' );

		$this->assertNull( ( new BrevoClient() )->is_sms_blacklisted( 'someone@example.com' ) );
	}
}
