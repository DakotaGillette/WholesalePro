<?php
/**
 * Covers the application intake: the Fluent Forms adapter's label mapping
 * against a fixture shaped like the live form (two-column containers, a
 * composite Name field, a full-sentence radio answer, a Terms element
 * with no label), the form-ID gate, and — most importantly — that a public
 * submission can never change what an existing account is allowed to do.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\ApplicationForm;
use ProtechWholesale\Approval;
use ProtechWholesale\Roles;
use ProtechWholesale\Settings;

/**
 * Class Test_Application_Form
 */
class Test_Application_Form extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPT_APPLICATION_SOURCE, ApplicationForm::SOURCE_FLUENT_FORMS );
		update_option( Settings::OPT_APPLICATION_FORM_ID, '4' );
	}

	/**
	 * A Fluent Forms Form model stand-in: form_fields is the JSON schema,
	 * with the live form's real labels and its two-column layout rows.
	 */
	private function fluent_form( int $id = 4 ): object {
		$field = static fn( string $name, string $label, string $element = 'input_text' ): array => array(
			'element'    => $element,
			'attributes' => array( 'name' => $name ),
			'settings'   => array( 'label' => $label ),
		);

		$schema = array(
			'fields' => array(
				$field( 'names', 'Name', 'input_name' ),
				array(
					'element' => 'container',
					'columns' => array(
						array( 'fields' => array( $field( 'title', 'Position / Title' ) ) ),
						array( 'fields' => array( $field( 'phone', 'Business Phone Number' ) ) ),
					),
				),
				array(
					'element' => 'container',
					'columns' => array(
						array( 'fields' => array( $field( 'email', 'Email Address', 'input_email' ) ) ),
						array( 'fields' => array( $field( 'store', 'Business Name' ) ) ),
					),
				),
				$field( 'business_type', 'Business Type', 'select' ),
				$field( 'address', 'Physical Store Address', 'address' ),
				$field( 'website', 'Website URL', 'input_url' ),
				$field( 'channels', 'Where do you primarily sell?', 'input_checkbox' ),
				$field( 'tcgs', 'Which TCG games do you carry?', 'input_checkbox' ),
				$field( 'lgs', "Is your business a 'play store' Local game store (LGS) that hosts TCG events, tournaments, or play space?", 'input_radio' ),
				$field( 'spend', 'Estimated monthly spend on sleeves', 'select' ),
				array(
					'element'    => 'terms_and_condition',
					'attributes' => array( 'name' => 'terms' ),
					'settings'   => array( 'tnc_html' => '<p>I confirm the information above is accurate.</p>' ),
				),
			),
		);

		return (object) array(
			'id'          => $id,
			'form_fields' => wp_json_encode( $schema ),
		);
	}

	/**
	 * @return array<string, mixed> Submitted values keyed by field name.
	 */
	private function submission( string $email, string $hosts_events = 'Yes – We are a brick-and-mortar LGS that hosts events' ): array {
		return array(
			'names'         => array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			),
			'title'         => 'Owner',
			'phone'         => '555-0100',
			'email'         => $email,
			'store'         => 'Analytical Engine Games',
			'business_type' => 'Retail store',
			'address'       => array(
				'address_line_1' => '1 Engine Way',
				'city'           => 'London',
				'state'          => 'LDN',
				'zip'            => 'SW1',
				'country'        => 'UK',
			),
			'website'       => 'https://example.com',
			'channels'      => array( 'In store', 'Online' ),
			'tcgs'          => array( 'Magic', 'Pokémon' ),
			'lgs'           => $hosts_events,
			'spend'         => '$500 – $1,000',
			'terms'         => 'on',
		);
	}

	public function test_fluent_forms_submission_creates_a_pending_applicant_with_mapped_fields(): void {
		( new ApplicationForm() )->handle_fluent_forms( 1, $this->submission( 'ada@example.com' ), $this->fluent_form() );

		$user = get_user_by( 'email', 'ada@example.com' );

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertTrue( Roles::is_wholesale_pending( $user->ID ) );
		$this->assertSame( Approval::STATUS_PENDING, get_user_meta( $user->ID, Approval::META_APP_STATUS, true ) );

		$this->assertSame( 'Ada Lovelace', get_user_meta( $user->ID, '_protech_wholesale_app_name', true ) );
		$this->assertSame( 'Owner', get_user_meta( $user->ID, '_protech_wholesale_app_title', true ) );
		$this->assertSame( '555-0100', get_user_meta( $user->ID, '_protech_wholesale_app_phone', true ) );
		$this->assertSame( 'Analytical Engine Games', get_user_meta( $user->ID, '_protech_wholesale_app_store_name', true ) );
		$this->assertSame( 'Retail store', get_user_meta( $user->ID, '_protech_wholesale_app_business_type', true ) );
		// The bare synonym "address" would have matched "Email Address" first.
		$this->assertSame( '1 Engine Way, London, LDN, SW1, UK', get_user_meta( $user->ID, '_protech_wholesale_app_address', true ) );
		$this->assertSame( 'In store, Online', get_user_meta( $user->ID, '_protech_wholesale_app_sales_channels', true ) );
		$this->assertSame( 'Magic, Pokémon', get_user_meta( $user->ID, '_protech_wholesale_app_tcgs_carried', true ) );
		$this->assertSame( '$500 – $1,000', get_user_meta( $user->ID, '_protech_wholesale_app_estimated_monthly_spend', true ) );
		$this->assertTrue( (bool) get_user_meta( $user->ID, '_protech_wholesale_app_hosts_events', true ) );
		$this->assertTrue( (bool) get_user_meta( $user->ID, '_protech_wholesale_app_accuracy_confirmation', true ) );
	}

	public function test_a_leading_no_answer_reads_as_not_hosting_events(): void {
		( new ApplicationForm() )->handle_fluent_forms(
			1,
			$this->submission( 'online@example.com', 'No – We are primarily online-only' ),
			$this->fluent_form()
		);

		$user = get_user_by( 'email', 'online@example.com' );

		$this->assertFalse( (bool) get_user_meta( $user->ID, '_protech_wholesale_app_hosts_events', true ) );
	}

	public function test_submissions_from_a_different_form_id_are_ignored(): void {
		( new ApplicationForm() )->handle_fluent_forms( 1, $this->submission( 'newsletter@example.com' ), $this->fluent_form( 7 ) );

		$this->assertFalse( get_user_by( 'email', 'newsletter@example.com' ) );
	}

	public function test_submission_with_an_administrators_email_never_changes_their_roles(): void {
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner@example.com',
			)
		);

		( new ApplicationForm() )->handle_fluent_forms( 1, $this->submission( 'owner@example.com' ), $this->fluent_form() );

		$admin = get_userdata( $admin_id );
		$this->assertSame( array( 'administrator' ), array_values( $admin->roles ) );
		$this->assertTrue( user_can( $admin_id, 'manage_options' ) );
		$this->assertFalse( Roles::is_wholesale_pending( $admin_id ) );

		// The application itself is still recorded for manual review.
		$this->assertSame( Approval::STATUS_PENDING, get_user_meta( $admin_id, Approval::META_APP_STATUS, true ) );
	}

	public function test_submission_from_an_existing_retail_customer_adds_pending_without_dropping_customer(): void {
		$customer_id = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'shopper@example.com',
			)
		);

		( new ApplicationForm() )->handle_fluent_forms( 1, $this->submission( 'shopper@example.com' ), $this->fluent_form() );

		$roles = get_userdata( $customer_id )->roles;
		$this->assertContains( 'customer', $roles );
		$this->assertContains( Roles::PENDING, $roles );
	}

	public function test_resubmission_by_an_approved_wholesale_customer_is_ignored(): void {
		$customer_id = self::factory()->user->create(
			array(
				'role'       => Roles::CUSTOMER,
				'user_email' => 'approved@example.com',
			)
		);
		update_user_meta( $customer_id, Approval::META_APP_STATUS, Approval::STATUS_APPROVED );

		( new ApplicationForm() )->handle_fluent_forms( 1, $this->submission( 'approved@example.com' ), $this->fluent_form() );

		$this->assertTrue( Roles::is_wholesale_customer( $customer_id ) );
		$this->assertSame( Approval::STATUS_APPROVED, get_user_meta( $customer_id, Approval::META_APP_STATUS, true ) );
	}
}
