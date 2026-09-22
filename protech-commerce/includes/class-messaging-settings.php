<?php
/**
 * Settings for the Messaging & automations feature (1.5.0), rendered as
 * the "Settings" view of the Messaging tab (see MessagingTab). Same
 * pattern as class-settings.php: option constants, a get_defaults() map
 * seeded on activation, WooCommerce Settings API field defs, typed
 * static getters.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessagingSettings
 */
class MessagingSettings {

	/** Master switch for the daily automation job and order-status rules. Manual sends always work. */
	public const OPT_ENABLED = 'protech_wholesale_msg_enabled';
	/** '' = use the Brevo plugin's own key (option sib_api_key_v3). */
	public const OPT_BREVO_API_KEY = 'protech_wholesale_msg_brevo_api_key';
	public const OPT_FROM_NAME  = 'protech_wholesale_msg_from_name';
	public const OPT_FROM_EMAIL = 'protech_wholesale_msg_from_email';
	public const OPT_REPLY_TO   = 'protech_wholesale_msg_reply_to';
	/** A Brevo-registered toll-free number (US/CA) or short/long code. Alphanumeric senders are not supported in the US/CA. */
	public const OPT_SMS_SENDER = 'protech_wholesale_msg_sms_sender';
	/** Prefixed onto every SMS: "{brand}: ...". */
	public const OPT_BRAND = 'protech_wholesale_msg_brand';
	public const OPT_DAILY_HOUR   = 'protech_wholesale_msg_daily_hour';
	public const OPT_QUIET_START  = 'protech_wholesale_msg_quiet_start';
	public const OPT_QUIET_END    = 'protech_wholesale_msg_quiet_end';
	public const OPT_FREQUENCY_CAP_DAYS = 'protech_wholesale_msg_frequency_cap_days';
	public const OPT_SMS_APPEND_STOP        = 'protech_wholesale_msg_sms_append_stop';
	public const OPT_SMS_OPTIN_CONFIRMATION = 'protech_wholesale_msg_sms_optin_confirmation';
	public const OPT_EMAIL_FOOTER = 'protech_wholesale_msg_email_footer';
	public const OPT_PRIVACY_URL  = 'protech_wholesale_msg_privacy_url';
	public const OPT_TERMS_URL    = 'protech_wholesale_msg_terms_url';
	public const OPT_CONSENT_WORDING = 'protech_wholesale_msg_consent_wording';
	public const OPT_LOG_RETENTION_DAYS = 'protech_wholesale_msg_log_retention_days';
	public const OPT_EMAIL_LOGO_ID      = 'protech_wholesale_msg_email_logo_id';
	public const OPT_EMAIL_BRAND_COLOR  = 'protech_wholesale_msg_email_brand_color';
	public const OPT_EMAIL_FOOTER_TEXT  = 'protech_wholesale_msg_email_footer_text';
	public const OPT_EMAIL_WIDTH        = 'protech_wholesale_msg_email_width';
	/** '' = headings use the same font as the body. */
	public const OPT_EMAIL_HEADING_FONT = 'protech_wholesale_msg_email_heading_font';
	/** '' = links use the brand color. */
	public const OPT_EMAIL_LINK_COLOR   = 'protech_wholesale_msg_email_link_color';
	/** Horizontal padding (px) a block keeps at phone widths. */
	public const OPT_EMAIL_MOBILE_PADDING = 'protech_wholesale_msg_email_mobile_padding';
	/** 'auto' picks Brevo when configured, else the site's own mailer; otherwise a provider id from MessageProviders::all(). */
	public const OPT_EMAIL_PROVIDER = 'protech_wholesale_msg_email_provider';
	/** 'auto' picks Brevo when configured; SMS has no non-Brevo provider yet, so "not configured" fails rather than falling back. */
	public const OPT_SMS_PROVIDER = 'protech_wholesale_msg_sms_provider';
	/** Not a user-facing setting; status only. */
	public const OPT_LAST_DAILY_RUN = 'protech_wholesale_msg_last_daily_run';

	public const DEFAULT_CONSENT_WORDING = 'By checking this box you agree to receive {kind} text messages from {brand} at the number provided. Msg frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help. See our Privacy Policy ({privacy_url}) and Terms ({terms_url}).';

	/**
	 * @return array<string, mixed> option => default value.
	 */
	public static function get_defaults(): array {
		return array(
			self::OPT_ENABLED               => 'no',
			self::OPT_BREVO_API_KEY         => '',
			self::OPT_FROM_NAME             => '',
			self::OPT_FROM_EMAIL            => '',
			self::OPT_REPLY_TO              => '',
			self::OPT_SMS_SENDER            => '',
			self::OPT_BRAND                 => '',
			self::OPT_DAILY_HOUR            => '10',
			self::OPT_QUIET_START           => '20',
			self::OPT_QUIET_END             => '10',
			self::OPT_FREQUENCY_CAP_DAYS    => '7',
			self::OPT_SMS_APPEND_STOP       => 'yes',
			self::OPT_SMS_OPTIN_CONFIRMATION => 'yes',
			self::OPT_EMAIL_FOOTER          => 'yes',
			self::OPT_PRIVACY_URL           => '',
			self::OPT_TERMS_URL             => '',
			self::OPT_CONSENT_WORDING       => self::DEFAULT_CONSENT_WORDING,
			self::OPT_LOG_RETENTION_DAYS    => '365',
			self::OPT_EMAIL_LOGO_ID         => '0',
			self::OPT_EMAIL_BRAND_COLOR     => '#42649d',
			self::OPT_EMAIL_FOOTER_TEXT     => '',
			self::OPT_EMAIL_WIDTH           => '600',
			self::OPT_EMAIL_HEADING_FONT    => '',
			self::OPT_EMAIL_LINK_COLOR      => '',
			self::OPT_EMAIL_MOBILE_PADDING  => '24',
			self::OPT_EMAIL_PROVIDER        => 'auto',
			self::OPT_SMS_PROVIDER          => 'auto',
			self::OPT_LAST_DAILY_RUN        => '0',
		);
	}

	public function register_hooks(): void {
		// A WooCommerce Settings API field type this plugin adds itself: WC_Admin_Settings
		// falls back to this action for any 'type' it doesn't know natively.
		add_action( 'woocommerce_admin_field_protech_media', array( __CLASS__, 'render_media_field' ) );
	}

	/**
	 * The logo field: a Media Library picker (wp.media, wired in admin.js)
	 * instead of typing an attachment id into a number box. WC_Admin_Settings
	 * saves it like any other field, since the hidden input's name is the
	 * option id.
	 *
	 * @param array<string, mixed> $field
	 */
	public static function render_media_field( array $field ): void {
		$id    = (int) get_option( (string) $field['id'], (string) ( $field['default'] ?? '0' ) );
		$thumb = $id > 0 ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';

		echo '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr( (string) $field['id'] ) . '">' . esc_html( (string) $field['title'] ) . '</label></th><td class="forminp">';
		echo '<div class="protech-media-picker">';
		echo '<span class="protech-media-thumb">' . ( $thumb ? '<img src="' . esc_url( (string) $thumb ) . '" alt="" style="max-width:120px;height:auto;display:block;margin-bottom:6px;" />' : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<input type="hidden" id="' . esc_attr( (string) $field['id'] ) . '" name="' . esc_attr( (string) $field['id'] ) . '" data-media-id="1" value="' . esc_attr( (string) $id ) . '" />';
		echo '<button type="button" class="button protech-settings-media-choose">' . esc_html__( 'Choose logo', 'protech-wholesale' ) . '</button> ';
		echo '<button type="button" class="button-link protech-settings-media-clear"' . ( $id > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'protech-wholesale' ) . '</button>';

		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="description">' . esc_html( (string) $field['desc'] ) . '</p>';
		}

		echo '</div></td></tr>';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			array(
				'title' => __( 'Automations', 'protech-wholesale' ),
				'desc'  => __( 'The master switch for the daily automation job and order-status messages. Manual sends (Compose) work either way.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_automations',
			),
			array(
				'title'   => __( 'Automations enabled', 'protech-wholesale' ),
				'desc'    => __( 'Run automated rules and order-status messages', 'protech-wholesale' ),
				'id'      => self::OPT_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_automations_end',
			),

			array(
				'title' => __( 'Brevo', 'protech-wholesale' ),
				'desc'  => __( 'Email and SMS are sent through Brevo. Leave the API key empty to use the key already saved by the Brevo plugin.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_brevo',
			),
			array(
				'title'       => __( 'Brevo API key', 'protech-wholesale' ),
				'desc'        => BrevoClient::is_configured()
					? __( 'A key is available (either this field or the Brevo plugin\'s own key). Leave empty to keep using the Brevo plugin\'s key.', 'protech-wholesale' )
					: __( 'No key found yet — connect Brevo below, or the Brevo plugin, before enabling automations.', 'protech-wholesale' ),
				'id'          => self::OPT_BREVO_API_KEY,
				'type'        => 'password',
				'default'     => '',
				'css'         => 'width:360px;',
				'placeholder' => __( 'Using the Brevo plugin\'s key', 'protech-wholesale' ),
			),
			array(
				'title'       => __( 'From name', 'protech-wholesale' ),
				'desc'        => __( 'Must be a sender verified in Brevo, or sends fail.', 'protech-wholesale' ),
				'id'          => self::OPT_FROM_NAME,
				'type'        => 'text',
				'default'     => '',
				'placeholder' => (string) get_option( 'woocommerce_email_from_name' ),
				'css'         => 'width:280px;',
			),
			array(
				'title'       => __( 'From email', 'protech-wholesale' ),
				'id'          => self::OPT_FROM_EMAIL,
				'type'        => 'email',
				'default'     => '',
				'placeholder' => (string) get_option( 'woocommerce_email_from_address' ),
				'css'         => 'width:280px;',
			),
			array(
				'title'       => __( 'Reply-to', 'protech-wholesale' ),
				'id'          => self::OPT_REPLY_TO,
				'type'        => 'email',
				'default'     => '',
				'placeholder' => self::from_email(),
				'css'         => 'width:280px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_brevo_end',
			),

			array(
				'title' => __( 'Sending', 'protech-wholesale' ),
				'desc'  => __( 'Automatic uses Brevo when it is connected, falling back to this site\'s own mail for email (SMS has no fallback: nothing else here can send a text).', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_sending',
			),
			array(
				'title'   => __( 'Send email through', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_PROVIDER,
				'type'    => 'select',
				'options' => MessageProviders::choices( 'email' ),
				'default' => 'auto',
			),
			array(
				'title'   => __( 'Send texts through', 'protech-wholesale' ),
				'id'      => self::OPT_SMS_PROVIDER,
				'type'    => 'select',
				'options' => MessageProviders::choices( 'sms' ),
				'default' => 'auto',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_sending_end',
			),

			array(
				'title' => __( 'SMS', 'protech-wholesale' ),
				'desc'  => __( 'US/Canada carriers do not support alphanumeric senders — a toll-free number or short/long code must be registered with Brevo first.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_sms',
			),
			array(
				'title'       => __( 'SMS sender', 'protech-wholesale' ),
				'desc'        => __( 'Your Brevo-registered toll-free number or code. Leave empty to use the account default.', 'protech-wholesale' ),
				'id'          => self::OPT_SMS_SENDER,
				'type'        => 'text',
				'default'     => '',
				'css'         => 'width:200px;',
			),
			array(
				'title'   => __( 'Brand name', 'protech-wholesale' ),
				'desc'    => __( 'Prefixed onto every text message, e.g. "Protech Sleeves: ...". Defaults to the site name.', 'protech-wholesale' ),
				'id'      => self::OPT_BRAND,
				'type'    => 'text',
				'default' => '',
				'placeholder' => (string) get_bloginfo( 'name' ),
				'css'     => 'width:280px;',
			),
			array(
				'title'   => __( 'Append "Reply STOP to opt out"', 'protech-wholesale' ),
				'id'      => self::OPT_SMS_APPEND_STOP,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Send an opt-in confirmation text', 'protech-wholesale' ),
				'desc'    => __( 'When a customer opts in to marketing or order-update texts, send them a confirmation message (frequency, rates, STOP/HELP) — the message carriers expect to see.', 'protech-wholesale' ),
				'id'      => self::OPT_SMS_OPTIN_CONFIRMATION,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'       => __( 'Quiet hours (no marketing texts)', 'protech-wholesale' ),
				'desc'        => __( 'Start hour, site time (0-23).', 'protech-wholesale' ),
				'id'          => self::OPT_QUIET_START,
				'type'        => 'number',
				'default'     => '20',
				'custom_attributes' => array( 'min' => '0', 'max' => '23' ),
				'css'         => 'width:70px;',
			),
			array(
				'desc'        => __( 'End hour.', 'protech-wholesale' ),
				'id'          => self::OPT_QUIET_END,
				'type'        => 'number',
				'default'     => '10',
				'custom_attributes' => array( 'min' => '0', 'max' => '23' ),
				'css'         => 'width:70px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_sms_end',
			),

			array(
				'title' => __( 'Sending rules', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_rules',
			),
			array(
				'title'   => __( 'Automated marketing runs at', 'protech-wholesale' ),
				'desc'    => __( 'Hour of day, site time, the daily automation job targets. Depends on real cron activity reaching the site — see the README.', 'protech-wholesale' ),
				'id'      => self::OPT_DAILY_HOUR,
				'type'    => 'number',
				'default' => '10',
				'custom_attributes' => array( 'min' => '0', 'max' => '23' ),
				'css'     => 'width:70px;',
			),
			array(
				'title'   => __( 'Frequency cap', 'protech-wholesale' ),
				'desc'    => __( 'days — the fewest days between two automated marketing messages to the same customer. 0 = no cap. Manual sends and order updates are exempt.', 'protech-wholesale' ),
				'id'      => self::OPT_FREQUENCY_CAP_DAYS,
				'type'    => 'number',
				'default' => '7',
				'custom_attributes' => array( 'min' => '0' ),
				'css'     => 'width:70px;',
			),
			array(
				'title'   => __( 'Add an unsubscribe footer to marketing emails', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_FOOTER,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Keep message log rows for', 'protech-wholesale' ),
				'desc'    => __( 'days', 'protech-wholesale' ),
				'id'      => self::OPT_LOG_RETENTION_DAYS,
				'type'    => 'number',
				'default' => '365',
				'custom_attributes' => array( 'min' => '30' ),
				'css'     => 'width:80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_rules_end',
			),

			array(
				'title' => __( 'Email design', 'protech-wholesale' ),
				'desc'  => __( 'The look every email template starts from. A template can override any of it.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_design',
			),
			array(
				'title'    => __( 'Logo image', 'protech-wholesale' ),
				'desc'     => __( 'Shown at the top of every email. Choose none to show the store name instead.', 'protech-wholesale' ),
				'id'       => self::OPT_EMAIL_LOGO_ID,
				'type'     => 'protech_media',
				'default'  => '0',
			),
			array(
				'title'   => __( 'Brand color', 'protech-wholesale' ),
				'desc'    => __( 'Buttons, links and headings.', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_BRAND_COLOR,
				'type'    => 'color',
				'default' => '#42649d',
				'css'     => 'width:6em;',
			),
			array(
				'title'   => __( 'Email width', 'protech-wholesale' ),
				'desc'    => __( 'pixels (480 to 700). 600 suits most inboxes.', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_WIDTH,
				'type'    => 'number',
				'default' => '600',
				'custom_attributes' => array( 'min' => '480', 'max' => '700' ),
				'css'     => 'width:80px;',
			),
			array(
				'title'   => __( 'Footer text', 'protech-wholesale' ),
				'desc'    => __( 'A line above the unsubscribe links, such as your store name and a tagline. Optional.', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_FOOTER_TEXT,
				'type'    => 'textarea',
				'default' => '',
				'css'     => 'width:100%;max-width:480px;height:70px;',
			),
			array(
				'title'   => __( 'Heading font', 'protech-wholesale' ),
				'desc'    => __( 'Leave on "Same as body" to use one font throughout.', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_HEADING_FONT,
				'type'    => 'select',
				'default' => '',
				'options' => array( '' => __( 'Same as body', 'protech-wholesale' ) ) + EmailBlocks::font_labels(),
			),
			array(
				'title'   => __( 'Link color', 'protech-wholesale' ),
				'desc'    => __( 'Leave empty to use the brand color.', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_LINK_COLOR,
				'type'    => 'color',
				'default' => '',
				'css'     => 'width:6em;',
			),
			array(
				'title'   => __( 'Mobile side padding', 'protech-wholesale' ),
				'desc'    => __( 'pixels (0 to 24), the room a block keeps on a phone.', 'protech-wholesale' ),
				'id'      => self::OPT_EMAIL_MOBILE_PADDING,
				'type'    => 'number',
				'default' => '24',
				'custom_attributes' => array( 'min' => '0', 'max' => '24' ),
				'css'     => 'width:70px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_design_end',
			),

			array(
				'title' => __( 'Compliance text', 'protech-wholesale' ),
				'desc'  => __( 'What a customer sees next to the SMS opt-in checkbox. {kind} becomes "order update" or "marketing and reorder reminder"; {brand}, {privacy_url} and {terms_url} are filled in automatically.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_msg_settings_compliance',
			),
			array(
				'id'      => self::OPT_CONSENT_WORDING,
				'type'    => 'textarea',
				'default' => self::DEFAULT_CONSENT_WORDING,
				'css'     => 'width:100%;height:80px;',
			),
			array(
				'title'       => __( 'Privacy policy URL', 'protech-wholesale' ),
				'id'          => self::OPT_PRIVACY_URL,
				'type'        => 'url',
				'default'     => '',
				'placeholder' => (string) get_privacy_policy_url(),
				'css'         => 'width:340px;',
			),
			array(
				'title'       => __( 'Terms URL', 'protech-wholesale' ),
				'id'          => self::OPT_TERMS_URL,
				'type'        => 'url',
				'default'     => '',
				'placeholder' => (string) wc_get_page_permalink( 'terms' ),
				'css'         => 'width:340px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_msg_settings_compliance_end',
			),
		);
	}

	public static function enabled(): bool {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	public static function email_provider(): string {
		return (string) get_option( self::OPT_EMAIL_PROVIDER, 'auto' );
	}

	public static function sms_provider(): string {
		return (string) get_option( self::OPT_SMS_PROVIDER, 'auto' );
	}

	public static function brevo_api_key(): string {
		$own = trim( (string) get_option( self::OPT_BREVO_API_KEY, '' ) );

		if ( '' !== $own ) {
			return $own;
		}

		// The Brevo (mailin) plugin's own v3 key, if that plugin is active.
		return trim( (string) get_option( 'sib_api_key_v3', '' ) );
	}

	public static function from_name(): string {
		$value = trim( (string) get_option( self::OPT_FROM_NAME, '' ) );

		return '' !== $value ? $value : (string) get_option( 'woocommerce_email_from_name' );
	}

	public static function from_email(): string {
		$value = sanitize_email( (string) get_option( self::OPT_FROM_EMAIL, '' ) );

		return '' !== $value ? $value : (string) get_option( 'woocommerce_email_from_address' );
	}

	public static function reply_to(): string {
		$value = sanitize_email( (string) get_option( self::OPT_REPLY_TO, '' ) );

		return '' !== $value ? $value : self::from_email();
	}

	public static function sms_sender(): string {
		return trim( (string) get_option( self::OPT_SMS_SENDER, '' ) );
	}

	public static function brand(): string {
		$value = trim( (string) get_option( self::OPT_BRAND, '' ) );

		return '' !== $value ? $value : (string) get_bloginfo( 'name' );
	}

	public static function daily_hour(): int {
		return max( 0, min( 23, (int) get_option( self::OPT_DAILY_HOUR, 10 ) ) );
	}

	/**
	 * @return array{start: int, end: int} Hours 0-23, site time.
	 */
	public static function quiet_hours(): array {
		return array(
			'start' => max( 0, min( 23, (int) get_option( self::OPT_QUIET_START, 20 ) ) ),
			'end'   => max( 0, min( 23, (int) get_option( self::OPT_QUIET_END, 10 ) ) ),
		);
	}

	public static function frequency_cap_days(): int {
		return max( 0, (int) get_option( self::OPT_FREQUENCY_CAP_DAYS, 7 ) );
	}

	public static function sms_append_stop(): bool {
		return 'yes' === get_option( self::OPT_SMS_APPEND_STOP, 'yes' );
	}

	public static function sms_optin_confirmation(): bool {
		return 'yes' === get_option( self::OPT_SMS_OPTIN_CONFIRMATION, 'yes' );
	}

	public static function email_footer_enabled(): bool {
		return 'yes' === get_option( self::OPT_EMAIL_FOOTER, 'yes' );
	}

	/** The site-wide email logo (a Media Library attachment id), or 0 for none. */
	public static function email_logo_id(): int {
		return max( 0, (int) get_option( self::OPT_EMAIL_LOGO_ID, 0 ) );
	}

	/** The site-wide brand color, a #rrggbb value. */
	public static function email_brand_color(): string {
		return EmailBlocks::hex( get_option( self::OPT_EMAIL_BRAND_COLOR, '#42649d' ), '#42649d' );
	}

	public static function email_footer_text(): string {
		return sanitize_textarea_field( (string) get_option( self::OPT_EMAIL_FOOTER_TEXT, '' ) );
	}

	/** The email width in pixels, kept inside 480 to 700. */
	public static function email_width(): int {
		return EmailBlocks::num( get_option( self::OPT_EMAIL_WIDTH, 600 ), 480, 700, 600 );
	}

	/** The site-wide heading font, a key from EmailBlocks::FONTS, or '' for "same as body". */
	public static function email_heading_font(): string {
		$key = (string) get_option( self::OPT_EMAIL_HEADING_FONT, '' );

		return array_key_exists( $key, EmailBlocks::FONTS ) ? $key : '';
	}

	/** The site-wide link color, a #rrggbb value, or '' for "use the brand color". */
	public static function email_link_color(): string {
		$value = (string) get_option( self::OPT_EMAIL_LINK_COLOR, '' );

		return '' !== $value ? EmailBlocks::hex( $value, '' ) : '';
	}

	/** Horizontal padding (px) a block keeps at phone widths, kept inside 0 to 24. */
	public static function email_mobile_padding(): int {
		return EmailBlocks::num( get_option( self::OPT_EMAIL_MOBILE_PADDING, 24 ), 0, 24, 24 );
	}

	public static function privacy_url(): string {
		$value = esc_url_raw( (string) get_option( self::OPT_PRIVACY_URL, '' ) );

		return '' !== $value ? $value : (string) get_privacy_policy_url();
	}

	public static function terms_url(): string {
		$value = esc_url_raw( (string) get_option( self::OPT_TERMS_URL, '' ) );

		return '' !== $value ? $value : (string) wc_get_page_permalink( 'terms' );
	}

	public static function consent_wording_template(): string {
		$value = (string) get_option( self::OPT_CONSENT_WORDING, self::DEFAULT_CONSENT_WORDING );

		return '' !== trim( $value ) ? $value : self::DEFAULT_CONSENT_WORDING;
	}

	public static function log_retention_days(): int {
		return max( 30, (int) get_option( self::OPT_LOG_RETENTION_DAYS, 365 ) );
	}

	public static function last_daily_run(): int {
		return (int) get_option( self::OPT_LAST_DAILY_RUN, 0 );
	}

	public static function set_last_daily_run( int $timestamp ): void {
		update_option( self::OPT_LAST_DAILY_RUN, (string) $timestamp, false );
	}

	/**
	 * True right now (site time) if the current hour falls in the quiet
	 * window. Handles a window that wraps midnight (e.g. 20 -> 10).
	 */
	public static function in_quiet_hours( ?int $now = null ): bool {
		$hours = self::quiet_hours();

		if ( $hours['start'] === $hours['end'] ) {
			return false; // A zero-width window means "no quiet hours."
		}

		$hour = (int) ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( wp_timezone() )->format( 'G' );

		if ( $hours['start'] < $hours['end'] ) {
			return $hour >= $hours['start'] && $hour < $hours['end'];
		}

		// Wraps midnight, e.g. 20 -> 10.
		return $hour >= $hours['start'] || $hour < $hours['end'];
	}

	/** The next timestamp (GMT) at which quiet hours end, site time considered. */
	public static function next_quiet_hours_end( ?int $now = null ): int {
		$now   = $now ?? time();
		$hours = self::quiet_hours();
		$tz    = wp_timezone();

		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( $hours['end'], 0, 0 );
		$today_ts = $today->getTimestamp();

		return $today_ts > $now ? $today_ts : $today_ts + DAY_IN_SECONDS;
	}
}
