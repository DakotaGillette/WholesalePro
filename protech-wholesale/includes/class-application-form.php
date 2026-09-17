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
	 * True if Settings::application_form_id() is empty (accept any form
	 * built with the selected plugin) or matches the submitted form's ID.
	 * Without this, a site with more than one form built on the same
	 * plugin (a contact form alongside the wholesale application, say)
	 * would have every one of them funneled into create_pending_applicant().
	 *
	 * @param int|string $form_id
	 */
	private function is_target_form( $form_id ): bool {
		$configured = Settings::application_form_id();

		return '' === $configured || (string) $form_id === $configured;
	}

	/**
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form definition.
	 */
	public function handle_gravity_forms( array $entry, array $form ): void {
		if ( Settings::application_source() !== self::SOURCE_GRAVITY || ! $this->is_target_form( $form['id'] ?? '' ) ) {
			return;
		}

		$values = array();

		foreach ( $form['fields'] ?? array() as $field ) {
			$label            = (string) ( $field->label ?? '' );
			$values[ $label ] = $this->flatten_value( $entry[ (string) $field->id ] ?? '' );
		}

		$this->create_pending_applicant( $this->map_labeled_values( $values ) );
	}

	/**
	 * @param array $fields  WPForms sanitized field values, keyed by field ID.
	 * @param array $entry   Raw entry data.
	 * @param array $form_data Form settings, including field labels.
	 */
	public function handle_wpforms( array $fields, array $entry, array $form_data ): void {
		if ( Settings::application_source() !== self::SOURCE_WPFORMS || ! $this->is_target_form( $form_data['id'] ?? '' ) ) {
			return;
		}

		$values = array();

		foreach ( $fields as $field ) {
			$label            = (string) ( $field['name'] ?? '' );
			$values[ $label ] = $this->flatten_value( $field['value'] ?? '' );
		}

		$this->create_pending_applicant( $this->map_labeled_values( $values ) );
	}

	/**
	 * @param \WPCF7_ContactForm $contact_form
	 */
	public function handle_cf7( $contact_form ): void {
		if ( Settings::application_source() !== self::SOURCE_CF7 || ! $this->is_target_form( $contact_form->id() ) ) {
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
		if ( Settings::application_source() !== self::SOURCE_FLUENT_FORMS || ! $this->is_target_form( $form->id ?? '' ) ) {
			return;
		}

		// Fluent Forms fires this hook inside a try/catch that only
		// catches \Exception, not \Error/\TypeError — anything this
		// adapter throws would otherwise vanish silently instead of
		// showing up anywhere. Catch everything and log it.
		try {
			// The Form model's DB column is `form_fields`, not `fields`
			// (confirmed against Fluent Forms 6.2.14's FluentForm\App\Models\Form).
			$fields = json_decode( (string) ( $form->form_fields ?? '{}' ), true );
			$values = array();

			foreach ( $this->flatten_fluent_fields( $fields['fields'] ?? array() ) as $field ) {
				$name    = (string) ( $field['attributes']['name'] ?? '' );
				$element = (string) ( $field['element'] ?? '' );

				// The "Terms & Conditions" element has no settings.label at
				// all — its consent text lives in settings.tnc_html instead.
				if ( 'terms_and_condition' === $element ) {
					$label = wp_strip_all_tags( (string) ( $field['settings']['tnc_html'] ?? $name ) );
				} else {
					$label = (string) ( $field['settings']['label'] ?? $name );
				}

				$raw_value = $form_data[ $name ] ?? '';

				// The Name element's first/last sub-values should read as
				// "First Last", not flatten_value()'s generic ", "-joined form.
				if ( 'input_name' === $element && is_array( $raw_value ) ) {
					$values[ $label ] = trim( ( $raw_value['first_name'] ?? '' ) . ' ' . ( $raw_value['last_name'] ?? '' ) );
				} else {
					$values[ $label ] = $this->flatten_value( $raw_value );
				}
			}

			$this->create_pending_applicant( $this->map_labeled_values( $values ) );
		} catch ( \Throwable $e ) {
			Logger::error( "Fluent Forms adapter failed on entry #{$entry_id}: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}
	}

	/**
	 * Fluent Forms' "Two Column"/"Three Column" layout elements
	 * (element === 'container') nest their real fields inside
	 * `columns[].fields[]` rather than listing them flat — a form built
	 * with any multi-column rows (like the live wholesale application
	 * form's Title/Phone and Business Name rows) would otherwise have
	 * those fields silently skipped entirely. Recurses in case of a
	 * container nested inside another container.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<int, array<string, mixed>> Flat list of leaf field definitions.
	 */
	private function flatten_fluent_fields( array $fields ): array {
		$flat = array();

		foreach ( $fields as $field ) {
			if ( isset( $field['columns'] ) && is_array( $field['columns'] ) ) {
				foreach ( $field['columns'] as $column ) {
					$flat = array_merge( $flat, $this->flatten_fluent_fields( $column['fields'] ?? array() ) );
				}
				continue;
			}

			$flat[] = $field;
		}

		return $flat;
	}

	/**
	 * Composite field types (Fluent Forms' Name and Address elements, and
	 * their equivalents in other builders) submit as an array of
	 * sub-values rather than a scalar — e.g. ['first_name' => 'A',
	 * 'last_name' => 'B'] or ['address_line_1' => ..., 'city' => ...].
	 * Cast straight to string would silently save the literal text
	 * "Array"; flatten to a readable joined string instead.
	 *
	 * @param mixed $value
	 */
	private function flatten_value( $value ): string {
		if ( is_array( $value ) ) {
			$parts = array_map( array( $this, 'flatten_value' ), $value );

			return implode( ', ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
		}

		return (string) $value;
	}

	/**
	 * @param array<string, mixed> $labeled_values Field label => submitted value.
	 * @return array<string, mixed> Normalized FIELD_KEYS => value.
	 */
	private function map_labeled_values( array $labeled_values ): array {
		// Substrings, not exact labels — real forms phrase questions in
		// full sentences ("Business Phone Number", "Is your business a
		// 'play store' ... that hosts TCG events, tournaments, or play
		// space?"), so a synonym only needs to appear somewhere in the
		// label. Verified against Protech Sleeves' live Fluent Forms
		// wholesale application (form #4) — see QA.md.
		$synonyms = apply_filters(
			'protech_wholesale_field_label_map',
			array(
				'title'                   => array( 'position', 'title', 'job title' ),
				'phone'                   => array( 'phone', 'telephone' ),
				'email'                   => array( 'email' ),
				'store_name'              => array( 'business name', 'store name', 'shop name', 'company name' ),
				'business_type'           => array( 'business type', 'type of business' ),
				// Not the bare word "address" — it's a substring of "Email
				// Address" too, which appears earlier in the form and would
				// otherwise win the match first.
				'address'                 => array( 'store address', 'physical store', 'business address', 'mailing address' ),
				'website'                 => array( 'website', 'website url', 'store website' ),
				'sales_channels'          => array( 'primarily sell', 'sales channels', 'where do you sell', 'how do you sell' ),
				'tcgs_carried'            => array( 'tcg games', 'tcgs carried', 'which tcgs', 'tcgs you carry', 'trading card games' ),
				'hosts_events'            => array( 'play store', 'lgs', 'hosts events', 'host events', 'hosts tcg events' ),
				'estimated_monthly_spend' => array( 'estimated monthly', 'monthly spend', 'purchase volume' ),
				'accuracy_confirmation'   => array( 'i confirm', 'confirm accuracy', 'accuracy', 'accurate' ),
			)
		);

		$normalized_input = array();

		foreach ( $labeled_values as $label => $value ) {
			$normalized_input[ strtolower( trim( (string) $label ) ) ] = $value;
		}

		$data = array_fill_keys( self::FIELD_KEYS, '' );

		foreach ( $synonyms as $key => $needles ) {
			foreach ( $needles as $needle ) {
				foreach ( $normalized_input as $label => $value ) {
					if ( str_contains( $label, $needle ) ) {
						$data[ $key ] = $value;
						break 2;
					}
				}
			}
		}

		// 'name' is a composite First/Last field on the live form with no
		// single combined label — fall back to concatenating those two
		// sub-labels when a direct "name" match isn't found.
		if ( '' === $data['name'] ) {
			$first = $last = '';

			foreach ( $normalized_input as $label => $value ) {
				if ( str_contains( $label, 'first name' ) ) {
					$first = (string) $value;
				} elseif ( str_contains( $label, 'last name' ) ) {
					$last = (string) $value;
				} elseif ( '' === $first && str_contains( $label, 'name' ) && ! str_contains( $label, 'business' ) && ! str_contains( $label, 'store' ) && ! str_contains( $label, 'company' ) ) {
					$first = (string) $value;
				}
			}

			$data['name'] = trim( "{$first} {$last}" );
		}

		// Real options are full sentences ("Yes – We are a brick-and-
		// mortar LGS...", "No – We are primarily online-only..."), so
		// treat only a leading "no" as declining rather than requiring
		// an exact 'no'/'0'/'false' value.
		$data['hosts_events']          = '' !== $data['hosts_events'] && ! preg_match( '/^no\b/i', trim( (string) $data['hosts_events'] ) );
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

			// This is a PUBLIC form: anyone can submit it with any email
			// address, so it must never be able to change what an existing
			// account can do. The pending role is only ever ADDED on top of
			// an ordinary shopper account (never used to replace its roles),
			// and an account with real capabilities — administrator, shop
			// manager, editor, ... — isn't touched at all: the application
			// is still recorded and the admin notified, so a genuine request
			// from a staff member can be approved by hand from their profile.
			if ( Roles::is_privileged( $user_id ) ) {
				Logger::warning( "Wholesale application submitted for the email of privileged account #{$user_id}; roles left unchanged, application recorded for manual review." );
			} else {
				Roles::grant( $user_id, Roles::PENDING );
			}
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

		update_user_meta( $user_id, Approval::META_APP_STATUS, Approval::STATUS_PENDING );
		delete_user_meta( $user_id, Approval::META_APP_REJECT_REASON );
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
