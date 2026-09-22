<?php
/**
 * uninstall.php deliberately does not require the plugin (see its own
 * docblock) — it hardcodes literal option/meta-key strings instead, which
 * only stays correct if every new option and meta key this plugin
 * introduces is added there by hand. This reflects the real constants
 * (including a form-plugin adapter's private FIELD_KEYS) and asserts
 * each one's literal string actually appears in uninstall.php, so a
 * forgotten addition fails CI instead of silently leaking data on a
 * "purge on uninstall."
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\Automations;
use ProtechWholesale\Campaigns;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\Settings;
use ProtechWholesale\SmsConsent;
use ProtechWholesale\Tiers;
use ProtechWholesale\Unsubscribe;

/**
 * Class Test_Uninstall_Lists
 */
class Test_Uninstall_Lists extends WP_UnitTestCase {

	private static string $uninstall_source = '';

	public function set_up(): void {
		parent::set_up();

		if ( '' === self::$uninstall_source ) {
			self::$uninstall_source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		}
	}

	private function assert_literal_present( string $literal, string $context ): void {
		$this->assertStringContainsString(
			"'{$literal}'",
			self::$uninstall_source,
			"uninstall.php is missing the literal '{$literal}' ({$context}) — add it to the option or user-meta key list."
		);
	}

	public function test_every_settings_option_is_listed(): void {
		foreach ( array_keys( Settings::get_defaults() ) as $option ) {
			$this->assert_literal_present( $option, 'Settings::get_defaults()' );
		}
	}

	public function test_every_messaging_settings_option_is_listed(): void {
		foreach ( array_keys( MessagingSettings::get_defaults() ) as $option ) {
			$this->assert_literal_present( $option, 'MessagingSettings::get_defaults()' );
		}
	}

	public function test_other_known_options_are_listed(): void {
		foreach (
			array(
				Tiers::OPT_TIER_SETTINGS,
				\ProtechWholesale\Plugin::OPT_DB_VERSION,
				Automations::OPTION,
				Campaigns::OPTION,
				\ProtechWholesale\EmailTemplates::OPTION,
				\ProtechWholesale\SignupForms::OPTION,
				\ProtechWholesale\Flows::OPTION,
				\ProtechWholesale\EmailDesigns::OPTION_PREFIX, // Deleted by prefix, one option per email.
			) as $option
		) {
			$this->assert_literal_present( $option, 'a feature class option constant' );
		}
	}

	public function test_every_user_meta_key_is_listed(): void {
		foreach (
			array(
				Approval::META_PRICE_OVERRIDES,
				Approval::META_APP_STATUS,
				Approval::META_APP_REJECT_REASON,
				Approval::META_APPROVED_AT,
				Tiers::META_USER_TIER,
				SmsConsent::META_PHONE,
				SmsConsent::META_SMS_TRANSACTIONAL,
				SmsConsent::META_SMS_MARKETING,
				SmsConsent::META_EMAIL_MARKETING,
				SmsConsent::META_CONSENT_LOG,
				Unsubscribe::META_TOKEN,
				\ProtechWholesale\WelcomeEmail::META_SENT_AT,
			) as $meta_key
		) {
			$this->assert_literal_present( $meta_key, 'a feature class user-meta constant' );
		}
	}

	public function test_every_application_form_field_key_is_listed(): void {
		$reflection = new ReflectionClass( \ProtechWholesale\ApplicationForm::class );
		$field_keys = $reflection->getReflectionConstant( 'FIELD_KEYS' )->getValue();

		foreach ( $field_keys as $key ) {
			$this->assert_literal_present( '_protech_wholesale_app_' . $key, 'ApplicationForm::FIELD_KEYS' );
		}
	}

	public function test_the_message_log_table_is_dropped(): void {
		$this->assertStringContainsString( 'protech_wholesale_messages', self::$uninstall_source );
		$this->assertStringContainsString( 'DROP TABLE', self::$uninstall_source );
	}

	public function test_the_contacts_tables_are_dropped(): void {
		$this->assertStringContainsString( 'protech_wholesale_contacts', self::$uninstall_source );
		$this->assertStringContainsString( 'protech_wholesale_contact_consent_log', self::$uninstall_source );
		$this->assertStringContainsString( 'protech_wholesale_contact_tags', self::$uninstall_source );
	}

	public function test_the_flow_runs_table_is_dropped(): void {
		$this->assertStringContainsString( 'protech_wholesale_flow_runs', self::$uninstall_source );
	}

	public function test_action_scheduler_hooks_are_unscheduled(): void {
		$this->assertStringContainsString( 'as_unschedule_all_actions', self::$uninstall_source );
		$this->assertStringContainsString( \ProtechWholesale\AutomationRunner::HOOK_DAILY, self::$uninstall_source );
		$this->assertStringContainsString( \ProtechWholesale\AutomationRunner::HOOK_FLOW_WAKE, self::$uninstall_source );
	}
}
