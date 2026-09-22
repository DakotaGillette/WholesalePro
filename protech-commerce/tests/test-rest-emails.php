<?php
/**
 * The protech/v1 routes behind the stepped new-email flow (3.9.0): every one
 * needs manage_woocommerce; a draft is created from a gallery pick, saved as
 * it is designed, and only a draft can be changed, deleted or sent; and the
 * Send step's estimate counts exactly what the old review screen counted.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Audience;
use ProtechWholesale\Campaigns;
use ProtechWholesale\ComposeScreen;
use ProtechWholesale\EmailDesigns;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\RestApi;

/**
 * Class Test_Rest_Emails
 */
class Test_Rest_Emails extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( EmailTemplates::OPTION );
		delete_option( Campaigns::OPTION );
		reset_phpmailer_instance();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function request( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestApi::NAMESPACE . $route );

		if ( ! empty( $body ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		return rest_get_server()->dispatch( $request );
	}

	/** @return array<string, mixed> The created email's payload. */
	private function create_from_starter( string $key = 'sale_announcement', array $extra = array() ): array {
		$response = $this->request( 'POST', '/emails', array_merge( array( 'source' => 'starter', 'key' => $key ), $extra ) );

		$this->assertSame( 201, $response->get_status() );

		return $response->get_data();
	}

	public function test_every_route_requires_manage_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach (
			array(
				array( 'GET', '/emails/gallery' ),
				array( 'GET', '/emails/thumbnail' ),
				array( 'POST', '/emails' ),
				array( 'GET', '/emails/c_1_abcdef' ),
				array( 'PUT', '/emails/c_1_abcdef' ),
				array( 'DELETE', '/emails/c_1_abcdef' ),
				array( 'POST', '/emails/c_1_abcdef/send' ),
				array( 'POST', '/audience/estimate' ),
				array( 'GET', '/products' ),
			) as [ $method, $route ]
		) {
			$this->assertSame( 403, $this->request( $method, $route )->get_status(), "{$method} {$route} should require manage_woocommerce." );
		}
	}

	public function test_the_gallery_offers_only_starters_for_emails_you_send_yourself(): void {
		$this->admin();

		$data       = $this->request( 'GET', '/emails/gallery' )->get_data();
		$categories = array_unique( array_column( $data['starters'], 'category' ) );

		$this->assertNotEmpty( $data['starters'] );
		$this->assertEmpty( array_diff( $categories, array( 'promotions', 'newsletter', 'blank' ) ) );
		$this->assertNotContains( 'welcome', array_column( $data['starters'], 'key' ) );
	}

	public function test_a_thumbnail_renders_a_starter_and_refuses_an_unknown_source(): void {
		$this->admin();

		$ok = $this->request( 'GET', '/emails/thumbnail', array(), array( 'source' => 'starter:sale_announcement' ) );

		$this->assertSame( 200, $ok->get_status() );
		$this->assertStringContainsString( '<html', strtolower( $ok->get_data()['html'] ) );
		$this->assertSame( 404, $this->request( 'GET', '/emails/thumbnail', array(), array( 'source' => 'starter:nope' ) )->get_status() );
	}

	public function test_creating_a_draft_copies_the_starter_and_keeps_a_preset_audience(): void {
		$this->admin();
		$customer = Protech_Test_Factory::wholesale_customer();

		$data = $this->create_from_starter( 'sale_announcement', array( 'audience' => array( 'type' => Audience::TYPE_SELECTED, 'user_ids' => array( $customer ) ) ) );

		$this->assertSame( Campaigns::STATUS_DRAFT, $data['email']['status'] );
		$this->assertSame( Audience::TYPE_SELECTED, $data['email']['audience']['type'] );
		$this->assertNotEmpty( $data['design']['blocks'] );
		$this->assertNotNull( EmailDesigns::get( $data['email']['id'] ) );
		$this->assertSame( 404, $this->request( 'POST', '/emails', array( 'source' => 'starter', 'key' => 'nope' ) )->get_status() );
	}

	public function test_saving_a_draft_always_stores_it_and_reports_problems(): void {
		$this->admin();
		$id = $this->create_from_starter()['email']['id'];

		$response = $this->request(
			'PUT',
			'/emails/' . $id,
			array(
				'name'            => 'Renamed',
				'service_message' => true,
				'design'          => array( 'subject' => 'Hi {not_a_tag}' ),
			)
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['errors'] );
		$this->assertSame( 'Renamed', $data['email']['name'] );
		$this->assertTrue( $data['email']['service_message'] );
		$this->assertSame( 'Hi {not_a_tag}', EmailDesigns::get( $id )['subject'] );
		$this->assertSame( EmailTemplates::KIND_TRANSACTIONAL, EmailDesigns::get( $id )['kind'] );
	}

	public function test_only_a_draft_can_be_changed_or_deleted_and_sending_needs_a_valid_email(): void {
		$this->admin();
		$id = $this->create_from_starter()['email']['id'];

		$this->request( 'PUT', '/emails/' . $id, array( 'design' => array( 'subject' => '' ) ) );
		$refused = $this->request( 'POST', '/emails/' . $id . '/send', array( 'when' => 'now' ) );

		$this->assertSame( 422, $refused->get_status() );
		$this->assertNotEmpty( $refused->get_data()['errors'] );

		$this->request( 'PUT', '/emails/' . $id, array( 'design' => array( 'subject' => 'A real subject' ) ) );
		$sent = $this->request( 'POST', '/emails/' . $id . '/send', array( 'when' => 'now' ) );

		$this->assertSame( 200, $sent->get_status() );
		$this->assertStringContainsString( 'rule_id=campaign', $sent->get_data()['log_url'] );
		$this->assertSame( 409, $this->request( 'PUT', '/emails/' . $id, array( 'name' => 'Too late' ) )->get_status() );
		$this->assertSame( 409, $this->request( 'DELETE', '/emails/' . $id )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/emails/c_1_abcdef' )->get_status() );
	}

	public function test_deleting_a_draft(): void {
		$this->admin();
		$id = $this->create_from_starter()['email']['id'];

		$this->assertSame( 200, $this->request( 'DELETE', '/emails/' . $id )->get_status() );
		$this->assertNull( Campaigns::get( $id ) );
	}

	public function test_the_estimate_matches_what_the_review_screen_counts(): void {
		$this->admin();
		Protech_Test_Factory::wholesale_customer();
		Protech_Test_Factory::wholesale_customer();

		$audience = array( 'type' => Audience::TYPE_ALL, 'scope' => Audience::SCOPE_WHOLESALE );
		$estimate = $this->request( 'POST', '/audience/estimate', array( 'audience' => $audience ) )->get_data();

		$review = ( new ReflectionMethod( ComposeScreen::class, 'build_review' ) );
		$review->setAccessible( true );
		$counted = $review->invoke(
			null,
			array(
				'channel'  => 'email',
				'audience' => $audience,
				'email'    => array( 'subject' => 'Hi', 'body' => 'Body' ),
			)
		);

		$this->assertSame( $counted['audience_total'], $estimate['total'] );
		$this->assertSame( $counted['sent_to']['email'], $estimate['sent_to'] );
		$this->assertSame( 'All wholesale customers', $estimate['label'] );
	}

	public function test_product_search_finds_by_name(): void {
		$this->admin();

		$product = new WC_Product_Simple();
		$product->set_name( 'Findable sleeve pack' );
		$product->set_status( 'publish' );
		$product->save();

		$found = $this->request( 'GET', '/products', array(), array( 'search' => 'Findable' ) )->get_data();

		$this->assertContains( $product->get_id(), array_column( $found, 'id' ) );
		$this->assertSame( array(), $this->request( 'GET', '/products', array(), array( 'search' => '' ) )->get_data() );
	}
}
