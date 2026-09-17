<?php
/**
 * Wholesale application intake.
 *
 * Which plugin renders /wholesale-application could not be confirmed
 * against the live site in this build environment (see DECISIONS.md), so
 * this class ships adapters for the four most common form plugins plus a
 * native fallback shortcode, gated by a single settings choice
 * (Settings::OPT_APPLICATION_SOURCE) so only one path is ever active.
 *
 * Every path funnels into create_pending_applicant(), which is the only
 * place that creates the WP user / stores meta / fires notifications —
 * keeping the "identify who applied" logic in one auditable spot
 * regardless of which form produced it.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ApplicationForm
 */
class ApplicationForm {

	public const SOURCE_NATIVE       = 'native';
	public const SOURCE_GRAVITY      = 'gravity_forms';
	public const SOURCE_WPFORMS      = 'wpforms';
	public const SOURCE_CF7          = 'cf7';
	public const SOURCE_FLUENT_FORMS = 'fluent_forms';

	/** Expected normalized field keys, matching the master prompt's field list. */
	private const FIELD_KEYS = array(
		'name',
		'title',
		'phone',
		'email',
		'store_name',
		'business_type',
		'address',
		'website',
		'sales_channels',
		'tcgs_carried',
		'hosts_events',
		'estimated_monthly_spend',
		'accuracy_confirmation',
	);

	public static function get_source_labels(): array {
		return array(
			self::SOURCE_NATIVE       => __( 'Native form (built into this plugin)', 'protech-wholesale' ),
			self::SOURCE_GRAVITY      => __( 'Gravity Forms', 'protech-wholesale' ),
			self::SOURCE_WPFORMS      => __( 'WPForms', 'protech-wholesale' ),
			self::SOURCE_CF7          => __( 'Contact Form 7', 'protech-wholesale' ),
			self::SOURCE_FLUENT_FORMS => __( 'Fluent Forms', 'protech-wholesale' ),
		);
	}

	public function register_hooks(): void {
		add_shortcode( 'protech_wholesale_application', array( $this, 'render_native_form' ) );
		add_action( 'admin_post_nopriv_protech_submit_application', array( $this, 'handle_native_submission' ) );
		add_action( 'admin_post_protech_submit_application', array( $this, 'handle_native_submission' ) );

		add_action( 'gform_after_submission', array( $this, 'handle_gravity_forms' ), 10, 2 );
		add_action( 'wpforms_process_complete', array( $this, 'handle_wpforms' ), 10, 4 );
		add_action( 'wpcf7_mail_sent', array( $this, 'handle_cf7' ) );
		add_action( 'fluentform_submission_inserted', array( $this, 'handle_fluent_forms' ), 10, 3 );
	}

	// -----------------------------------------------------------------
	// Native fallback form.
	// -----------------------------------------------------------------

	public function render_native_form(): string {
		if ( is_user_logged_in() ) {
			if ( Roles::is_wholesale_customer() ) {
				return '<p>' . esc_html__( 'You already have an approved wholesale account.', 'protech-wholesale' ) . '</p>';
			}

			if ( Roles::is_wholesale_pending() ) {
				return '<p>' . esc_html__( 'Your application is already under review. We will be in touch within 1–3 business days.', 'protech-wholesale' ) . '</p>';
			}
		}

		ob_start();
		wc_get_template(
			'application-form.php',
			array( 'action_url' => admin_url( 'admin-post.php' ) ),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);

		return (string) ob_get_clean();
	}

	public function handle_native_submission(): void {
		if ( ! isset( $_POST['protech_wholesale_application_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_application_nonce'] ) ),
				'protech_wholesale_application'
			)
		) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'protech-wholesale' ) );
		}

		// Honeypot: a hidden field real users never fill in.
		if ( ! empty( $_POST['protech_wholesale_website_confirm'] ) ) {
			Logger::warning( 'Application form honeypot triggered', array( 'ip' => $this->get_client_ip() ) );
			wp_safe_redirect( add_query_arg( 'wholesale_application', 'received', wp_get_referer() ?: home_url() ) );
			exit;
		}

		if ( $this->is_rate_limited() ) {
			wp_die( esc_html__( 'Too many submissions. Please try again later.', 'protech-wholesale' ) );
		}

		$data = array();

		foreach ( self::FIELD_KEYS as $key ) {
			$raw = wp_unslash( $_POST[ $key ] ?? '' );
			$data[ $key ] = 'address' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}

		$data['hosts_events']           = ! empty( $_POST['hosts_events'] );
		$data['accuracy_confirmation']  = ! empty( $_POST['accuracy_confirmation'] );

		$result = $this->create_pending_applicant( $data );

		$redirect = wp_get_referer() ?: home_url( '/wholesale-application' );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'wholesale_application', 'error', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'wholesale_application', 'received', $redirect ) );
		exit;
	}

	private function is_rate_limited(): bool {
		$ip  = $this->get_client_ip();
		$key = 'protech_wholesale_app_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );

		if ( $hits >= 3 ) {
			return true;
		}

		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		return false;
	}

	private function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	// -----------------------------------------------------------------
	// Third-party form adapters. Each maps its own submission shape to
	// FIELD_KEYS by matching field LABELS (not numeric IDs, which vary
	// per form/site) against a filterable synonym list, then defers to
	// create_pending_applicant(). Only runs when the matching source is
	// selected in Settings, so at most one adapter is ever live.
	// -----------------------------------------------------------------

	/**
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form definition.
	 */
	public function handle_gravity_forms( array $entry, array $form ): void {
		if ( Settings::application_source() !== self::SOURCE_GRAVITY ) {
			return;
		}

		$values = array();

		foreach ( $form['fields'] ?? array() as $field ) {
			$label            = (string) ( $field->label ?? '' );
			$values[ $label ] = $entry[ (string) $field->id ] ?? '';
		}

		$this->create_pending_applicant( $this->map_labeled_values( $values ) );
	}

	/**
	 * @param array $fields  WPForms sanitized field values, keyed by field ID.
	 * @param array $entry   Raw entry data.
	 * @param array $form_data Form settings, including field labels.
	 */
	public function handle_wpforms( array $fields, array $entry, array $form_data ): void {
		if ( Settings::application_source() !== self::SOURCE_WPFORMS ) {
			return;
		}

		$values = array();

		foreach ( $fields as $field ) {
			$label            = (string) ( $field['name'] ?? '' );
			$values[ $label ] = $field['value'] ?? '';
		}

		$this->create_pending_applicant( $this->map_labeled_values( $values ) );
	}

	/**
	 * @param \WPCF7_ContactForm $contact_form
	 */
	public function handle_cf7( $contact_form ): void {
		if ( Settings::application_source() !== self::SOURCE_CF7 ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();

		if ( ! $submission ) {
			return;
		}

		// CF7 has no field labels; mail-tag names are the closest analog
		// and are set by whoever built the form (e.g. [your-store-name]).
		$posted = $submission->get_posted_data();
		$values = array();

		foreach ( $posted as $tag_name => $value ) {
			$values[ str_replace( array( 'your-', '-', '_' ), array( '', ' ', ' ' ), $tag_name ) ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		$this->create_pending_applicant( $this->map_labeled_values( $values ) );
	}

	/**
	 * @param int   $entry_id
	 * @param array $form_data
	 * @param object $form
	 */
	public function handle_fluent_forms( int $entry_id, array $form_data, $form ): void {
		if ( Settings::application_source() !== self::SOURCE_FLUENT_FORMS ) {
			return;
		}

		if ( ! function_exists( 'wpFluent' ) ) {
			return;
		}

		$fields = json_decode( (string) ( $form->fields ?? '{}' ), true );
		$values = array();

		foreach ( $fields['fields'] ?? array() as $field ) {
			$name             = (string) ( $field['attributes']['name'] ?? '' );
			$label            = (string) ( $field['settings']['label'] ?? $name );
			$values[ $label ] = $form_data[ $name ] ?? '';
		}

		$this->create_pending_applicant( $this->map_labeled_values( $values ) );
	}

	/**
	 * @param array<string, mixed> $labeled_values Field label => submitted value.
	 * @return array<string, mixed> Normalized FIELD_KEYS => value.
	 */
	private function map_labeled_values( array $labeled_values ): array {
		$synonyms = apply_filters(
			'protech_wholesale_field_label_map',
			array(
				'name'                    => array( 'name', 'your name', 'full name', 'contact name' ),
				'title'                   => array( 'title', 'job title', 'your title', 'position' ),
				'phone'                   => array( 'phone', 'phone number', 'telephone' ),
				'email'                   => array( 'email', 'email address', 'your email' ),
				'store_name'              => array( 'store name', 'business name', 'shop name', 'company name' ),
				'business_type'           => array( 'business type', 'type of business' ),
				'address'                 => array( 'address', 'business address', 'store address' ),
				'website'                 => array( 'website', 'website url', 'store website' ),
				'sales_channels'          => array( 'sales channels', 'where do you sell', 'how do you sell' ),
				'tcgs_carried'            => array( 'tcgs carried', 'which tcgs', 'tcgs you carry', 'trading card games' ),
				'hosts_events'            => array( 'hosts events', 'do you host events', 'host events' ),
				'estimated_monthly_spend' => array( 'estimated monthly spend', 'monthly spend', 'estimated monthly order' ),
				'accuracy_confirmation'   => array( 'accuracy', 'i confirm', 'confirm accuracy', 'accurate' ),
			)
		);

		$normalized_input = array();

		foreach ( $labeled_values as $label => $value ) {
			$normalized_input[ strtolower( trim( (string) $label ) ) ] = $value;
		}

		$data = array_fill_keys( self::FIELD_KEYS, '' );

		foreach ( $synonyms as $key => $labels ) {
			foreach ( $labels as $label ) {
				if ( array_key_exists( $label, $normalized_input ) ) {
					$data[ $key ] = $normalized_input[ $label ];
					break;
				}
			}
		}

		$data['hosts_events']          = ! empty( $data['hosts_events'] ) && ! in_array( strtolower( (string) $data['hosts_events'] ), array( 'no', '0', 'false' ), true );
		$data['accuracy_confirmation'] = ! empty( $data['accuracy_confirmation'] );

		return array_map(
			static fn( $value ) => is_bool( $value ) ? $value : sanitize_text_field( (string) $value ),
			$data
		);
	}

	// -----------------------------------------------------------------
	// Shared: create the pending WP user + meta + notifications.
	// -----------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data Normalized FIELD_KEYS => value.
	 * @return int|\WP_Error New/updated user ID, or error.
	 */
	public function create_pending_applicant( array $data ) {
		$email = sanitize_email( $data['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'protech_invalid_email', __( 'A valid email address is required.', 'protech-wholesale' ) );
		}

		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof \WP_User ) {
			if ( Roles::is_wholesale_customer( $existing->ID ) ) {
				Logger::info( "Application resubmitted by existing wholesale customer #{$existing->ID}; ignored." );
				return $existing->ID;
			}

			$user_id = $existing->ID;
			$existing->set_role( Roles::PENDING );
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login' => $this->generate_unique_login( $email ),
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 20 ),
					'first_name' => $data['name'] ?? '',
					'role'       => Roles::PENDING,
				)
			);

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		foreach ( self::FIELD_KEYS as $key ) {
			update_user_meta( $user_id, '_protech_wholesale_app_' . $key, $data[ $key ] ?? '' );
		}

		update_user_meta( $user_id, '_protech_wholesale_app_status', 'pending' );
		update_user_meta( $user_id, '_protech_wholesale_app_submitted_at', current_time( 'mysql' ) );

		Logger::info( "New wholesale application: user #{$user_id} ({$email})" );

		Emails::send_admin_new_application( (int) $user_id );
		Emails::send_applicant_received( (int) $user_id );

		do_action( 'protech_wholesale_application_submitted', (int) $user_id, $data );

		return (int) $user_id;
	}

	private function generate_unique_login( string $email ): string {
		$base  = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'wholesale';
		$login = $base;
		$i     = 1;

		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}

		return $login;
	}
}
