<?php
/**
 * MessageProviders: "Automatic" picks Brevo when it's configured, falling
 * back to the site's own mailer for email (SMS has nothing to fall back
 * to); a provider chosen explicitly on Settings is used regardless; and
 * MessageTransport keeps sending through whichever one is picked without
 * changing its own return shape.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageProviders;
use ProtechWholesale\MessageTransport;
use ProtechWholesale\MessagingSettings;

/**
 * Class Test_Message_Providers
 */
class Test_Message_Providers extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( MessagingSettings::OPT_BREVO_API_KEY );
		delete_option( 'sib_api_key_v3' );
		delete_option( MessagingSettings::OPT_EMAIL_PROVIDER );
		delete_option( MessagingSettings::OPT_SMS_PROVIDER );
		reset_phpmailer_instance();
	}

	public function test_auto_picks_the_site_mailer_for_email_when_brevo_is_not_configured(): void {
		$provider = MessageProviders::email();

		$this->assertSame( 'wc_mailer', $provider->id() );
	}

	public function test_auto_picks_brevo_for_email_once_it_is_configured(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'a-real-looking-key' );

		$provider = MessageProviders::email();

		$this->assertSame( 'brevo', $provider->id() );
	}

	public function test_auto_has_no_sms_provider_when_brevo_is_not_configured(): void {
		$this->assertNull( MessageProviders::sms() );
	}

	public function test_auto_picks_brevo_for_sms_once_it_is_configured(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'a-real-looking-key' );

		$provider = MessageProviders::sms();

		$this->assertNotNull( $provider );
		$this->assertSame( 'brevo', $provider->id() );
	}

	public function test_a_forced_choice_wins_even_when_brevo_is_configured(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'a-real-looking-key' );
		update_option( MessagingSettings::OPT_EMAIL_PROVIDER, 'wc_mailer' );

		$this->assertSame( 'wc_mailer', MessageProviders::email()->id() );
	}

	public function test_wc_mailer_never_offers_itself_for_sms(): void {
		$this->assertArrayNotHasKey( 'wc_mailer', MessageProviders::choices( 'sms' ) );
		$this->assertArrayHasKey( 'wc_mailer', MessageProviders::choices( 'email' ) );
	}

	public function test_dispatch_sends_through_the_site_mailer_and_keeps_its_result_shape(): void {
		$result = MessageTransport::dispatch( 'ada@example.com', 'Hello', '<p>Hi</p>', 'Hi' );

		$this->assertSame( 'sent', $result['status'] );
		$this->assertSame( 'wc_mailer', $result['provider'] );
		$this->assertArrayHasKey( 'provider_id', $result );
		$this->assertArrayHasKey( 'retryable', $result );
	}

	public function test_send_sms_with_no_configured_provider_fails_with_sms_unavailable(): void {
		$result = MessageTransport::send_sms( 0, '+15555550100', 'Hi', MessageLog::CATEGORY_TRANSACTIONAL );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'sms_unavailable', $result['reason'] );
	}

	public function test_a_provider_added_by_filter_is_offered_for_the_channel_it_supports(): void {
		$fake = new class() implements \ProtechWholesale\MessageProvider {
			public function id(): string { return 'fake'; }
			public function label(): string { return 'Fake'; }
			public function supports( string $channel ): bool { return 'sms' === $channel; }
			public function is_configured(): bool { return true; }
			public function send_email( array $message ): array { return array( 'ok' => false, 'provider_id' => '', 'error' => '', 'retryable' => false ); }
			public function send_sms( array $message ): array { return array( 'ok' => true, 'provider_id' => 'fake-1', 'error' => '', 'retryable' => false ); }
			public function is_email_blacklisted( string $email ): ?bool { return null; }
			public function is_sms_blacklisted( string $email ): ?bool { return null; }
			public function verify(): array { return array( 'ok' => true, 'error' => '' ); }
		};

		$add = static function ( array $providers ) use ( $fake ): array {
			$providers[] = $fake;
			return $providers;
		};

		add_filter( 'protech_wholesale_message_providers', $add );

		try {
			$this->assertSame( 'fake', MessageProviders::sms()->id() );

			update_option( MessagingSettings::OPT_SMS_PROVIDER, 'fake' );
			$result = MessageTransport::send_sms( 0, '+15555550100', 'Hi', MessageLog::CATEGORY_TRANSACTIONAL );
			$this->assertSame( 'sent', $result['status'] );
			$this->assertSame( 'fake', $result['provider'] );
			$this->assertSame( 'fake-1', $result['provider_id'] );
		} finally {
			remove_filter( 'protech_wholesale_message_providers', $add );
		}
	}
}
