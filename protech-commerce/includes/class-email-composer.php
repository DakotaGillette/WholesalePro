<?php
/**
 * Messaging → Email templates: the library and the editor's handlers.
 *
 * The library lists every template with what it is used for and lets you
 * start a new one from a starter, edit, copy, preview or delete it. The
 * editor's form itself is EmailEditor; this class is the screens' routing and
 * everything that writes.
 *
 * Every change goes through admin-post.php and comes back as a redirect: the
 * page heading has already been printed by the time a view renders, so nothing
 * in a view can send its own redirect header. A short per-admin transient (the
 * "stash") carries a half-finished template and its errors across that
 * redirect, so a failed save never loses what was typed.
 *
 * Previews go through EmailRenderer, the same code a real send uses, so what
 * the admin sees cannot drift from what lands in an inbox.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailComposer
 */
class EmailComposer {

	public const PREVIEW_ACTION       = 'protech_preview_email_template';
	public const DRAFT_PREVIEW_ACTION = 'protech_preview_email_draft';
	public const SAVE_ACTION          = 'protech_save_email_template';
	public const SEND_TEST_ACTION     = 'protech_send_email_template_test';
	public const NEW_ACTION           = 'protech_new_email_template';
	public const DUPLICATE_ACTION     = 'protech_duplicate_email_template';
	public const DELETE_ACTION        = 'protech_delete_email_template';
	public const NONCE_FIELD          = 'protech_email_template_nonce';

	private const STASH_TTL = 5 * MINUTE_IN_SECONDS;

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::DRAFT_PREVIEW_ACTION, array( $this, 'handle_draft_preview' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::SEND_TEST_ACTION, array( $this, 'handle_send_test' ) );
		add_action( 'admin_post_' . self::NEW_ACTION, array( $this, 'handle_new' ) );
		add_action( 'admin_post_' . self::DUPLICATE_ACTION, array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
	}

	// -----------------------------------------------------------------
	// Links.
	// -----------------------------------------------------------------

	/** The signed link that opens a saved template's preview. */
	public static function preview_url( string $template_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::PREVIEW_ACTION,
					'template_id' => $template_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::PREVIEW_ACTION . '_' . $template_id
		);
	}

	/** A signed link for a one-click action on a saved template (copy, delete) or a new one (`starter`). */
	private static function action_url( string $action, string $id ): string {
		$arg = self::NEW_ACTION === $action ? 'starter' : 'template_id';

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					$arg     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $id
		);
	}

	public static function edit_url( string $id ): string {
		return MessagingTab::url( 'templates', array( 'edit' => $id ) );
	}

	private static function back( array $args = array(), string $edit = '' ): never {
		wp_safe_redirect( '' === $edit ? MessagingTab::url( 'templates', $args ) : MessagingTab::url( 'templates', array_merge( array( 'edit' => $edit ), $args ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Rendering a template for the admin.
	// -----------------------------------------------------------------

	/**
	 * A template as it would arrive, for $user_id (the admin's own account by
	 * default, so merge tags fill in with real values). Marketing templates
	 * carry the same footer a real send adds.
	 *
	 * @param array<string, mixed> $template
	 */
	public static function preview_html( array $template, int $user_id ): string {
		return EmailRenderer::render(
			$template,
			EmailRenderer::context( $user_id, true ),
			array( 'footer_html' => MessageTransport::footer_html_for( $user_id, self::category_of( $template ) ) )
		)['html'];
	}

	/** @param array<string, mixed> $template */
	public static function category_of( array $template ): string {
		return EmailTemplates::KIND_TRANSACTIONAL === ( $template['kind'] ?? '' ) ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;
	}

	private static function send_page( string $html ): never {
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a finished email document, escaped where it is built.
		exit;
	}

	private static function require_cap(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}
	}

	// -----------------------------------------------------------------
	// The stash: a half-finished template across a redirect.
	// -----------------------------------------------------------------

	private static function stash_key(): string {
		return 'protech_wholesale_tpl_stash_' . get_current_user_id();
	}

	/** @param array<string, mixed> $data */
	private static function stash( array $data ): void {
		set_transient( self::stash_key(), $data, self::STASH_TTL );
	}

	/** @return array<string, mixed>|null */
	private static function unstash(): ?array {
		$data = get_transient( self::stash_key() );

		if ( false === $data ) {
			return null;
		}

		delete_transient( self::stash_key() );

		return is_array( $data ) ? $data : null;
	}

	// -----------------------------------------------------------------
	// Handlers.
	// -----------------------------------------------------------------

	/** Opens a saved template's preview in its own page. */
	public function handle_preview(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['template_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::PREVIEW_ACTION . '_' . $id );
		self::require_cap();

		$template = EmailTemplates::get( $id );

		if ( null === $template ) {
			wp_die( esc_html__( 'That template no longer exists.', 'protech-wholesale' ) );
		}

		self::send_page( self::preview_html( $template, get_current_user_id() ) );
	}

	/** The editor's live preview: the form as it stands, not saved, rendered into the frame. */
	public function handle_draft_preview(): void {
		check_admin_referer( self::SAVE_ACTION, self::NONCE_FIELD );
		self::require_cap();

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned field by field in EmailTemplates::validate().

		self::send_page( self::preview_html( EmailTemplates::validate( $input )['template'], get_current_user_id() ) );
	}

	/** Saves the template, or returns to the editor with what went wrong and everything still typed. */
	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION, self::NONCE_FIELD );
		self::require_cap();

		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned field by field in EmailTemplates::validate().
		$id     = sanitize_text_field( (string) ( $input['id'] ?? '' ) );
		$result = EmailTemplates::validate( $input );

		if ( ! empty( $result['errors'] ) ) {
			self::stash( array( 'id' => $id, 'input' => $input, 'errors' => $result['errors'] ) );
			self::back( array(), '' === $id ? 'new' : $id );
		}

		$saved = EmailTemplates::save( $result['template'] );

		Logger::info( sprintf( 'Email template "%s" saved by admin #%d.', $result['template']['name'], get_current_user_id() ) );

		self::back( array( 'saved' => 1 ), $saved );
	}

	/** Sends the template, as it stands in the editor, to any address. */
	public function handle_send_test(): void {
		check_admin_referer( self::SAVE_ACTION, self::NONCE_FIELD );
		self::require_cap();

		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned field by field in EmailTemplates::validate().
		$id       = sanitize_text_field( (string) ( $input['id'] ?? '' ) );
		$template = EmailTemplates::validate( $input )['template']; // Errors do not matter for a preview.
		$admin    = wp_get_current_user();
		$typed    = sanitize_email( (string) ( $input['preview_email'] ?? '' ) );
		$to       = is_email( $typed ) ? $typed : (string) $admin->user_email;
		$context  = EmailRenderer::context( (int) $admin->ID, true );
		$subject  = EmailRenderer::subject( $template, $context );

		$result = MessageTransport::send_template_email( (int) $admin->ID, $to, $subject, $template, $context, self::category_of( $template ), array( 'test' ) );

		self::log_test( (int) $admin->ID, $result );

		self::stash(
			array(
				'id'     => $id,
				'input'  => $input,
				'errors' => array(),
				'test'   => array( 'ok' => 'sent' === $result['status'], 'to' => $to, 'error' => $result['error'] ),
			)
		);
		self::back( array(), '' === $id ? 'new' : $id );
	}

	/**
	 * A test send is recorded like every other message, so the log shows it.
	 * Public so the REST test-send route can share it.
	 *
	 * @param array{status: string, provider: string, provider_id: string, recipient: string, subject: string, error: string, reason: string, retryable: bool} $result
	 */
	public static function log_test( int $user_id, array $result ): void {
		$log_id = MessageLog::enqueue(
			array(
				'user_id'   => $user_id,
				'channel'   => MessageLog::CHANNEL_EMAIL,
				'kind'      => MessageLog::KIND_TEST,
				'category'  => MessageLog::CATEGORY_TRANSACTIONAL,
				'rule_id'   => 'template-test',
				'anchor'    => 'template-test:' . microtime( true ),
				'recipient' => $result['recipient'],
			)
		);

		if ( $log_id ) {
			MessageLog::claim( $log_id );
			MessageLog::finish(
				$log_id,
				$result['status'],
				array(
					'provider'    => $result['provider'],
					'provider_id' => $result['provider_id'],
					'recipient'   => $result['recipient'],
					'subject'     => $result['subject'],
					'error'       => $result['error'],
					'reason'      => $result['reason'],
				)
			);
		}
	}

	/** "Start a new template from ...": a fresh copy of a starter, straight into the editor. */
	public function handle_new(): void {
		$key = sanitize_key( wp_unslash( $_GET['starter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::NEW_ACTION . '_' . $key );
		self::require_cap();

		$id = EmailTemplates::create_from_starter( $key );

		self::back( array(), '' === $id ? '' : $id );
	}

	public function handle_duplicate(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['template_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::DUPLICATE_ACTION . '_' . $id );
		self::require_cap();

		$copy = EmailTemplates::duplicate( $id );

		self::back( '' === $copy ? array() : array( 'duplicated' => 1 ), $copy );
	}

	/** Deletes a template, unless something still sends it: a lifecycle email or an automation rule. */
	public function handle_delete(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['template_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::DELETE_ACTION . '_' . $id );
		self::require_cap();

		$template = EmailTemplates::get( $id );

		if ( null === $template ) {
			self::back();
		}

		if ( '' !== self::used_by( $id, (string) $template['slot'] ) ) {
			self::back( array( 'blocked' => rawurlencode( (string) $template['name'] ) ) );
		}

		EmailTemplates::delete( $id );

		self::back( array( 'deleted' => 1 ) );
	}

	// -----------------------------------------------------------------
	// The library.
	// -----------------------------------------------------------------

	/** Where each template is used: the lifecycle email it is bound to, and the rules and campaigns pointing at it. */
	public static function used_by( string $id, string $slot ): string {
		$uses = array();

		if ( '' !== $slot ) {
			$uses[] = EmailTemplates::slots()[ $slot ] ?? $slot;
		}

		foreach ( Automations::all() as $rule ) {
			if ( $id === (string) ( $rule['email']['template_id'] ?? '' ) ) {
				$uses[] = (string) $rule['name'];
			}
		}

		foreach ( Campaigns::all() as $campaign ) {
			if ( $id === (string) ( $campaign['email']['template_id'] ?? '' ) ) {
				$uses[] = __( 'A past campaign', 'protech-wholesale' );
				break;
			}
		}

		return empty( $uses ) ? '' : implode( ', ', array_unique( $uses ) );
	}

	/** The library, or the editor when `edit` is in the address. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';

		if ( '' !== $edit ) {
			self::render_editor( $edit );
			return;
		}

		self::render_list();
	}

	private static function render_editor( string $id ): void {
		$is_new = 'new' === $id;
		$stash  = self::unstash();
		$errors = array();

		if ( null !== $stash && (string) ( $stash['id'] ?? '' ) === ( $is_new ? '' : $id ) ) {
			$template = EmailTemplates::validate( (array) ( $stash['input'] ?? array() ) )['template'];
			$errors   = (array) ( $stash['errors'] ?? array() );

			if ( ! empty( $stash['test'] ) ) {
				$test = (array) $stash['test'];

				echo '<div class="notice ' . ( ! empty( $test['ok'] ) ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html(
					! empty( $test['ok'] )
						/* translators: %s: email address. */
						? sprintf( __( 'Preview sent to %s.', 'protech-wholesale' ), (string) $test['to'] )
						/* translators: %s: error message. */
						: sprintf( __( 'The preview could not be sent: %s', 'protech-wholesale' ), '' !== (string) $test['error'] ? (string) $test['error'] : __( 'check the address.', 'protech-wholesale' ) )
				) . '</p></div>';
			}
		} elseif ( $is_new ) {
			$template = EmailTemplates::defaults();
		} else {
			$template = EmailTemplates::get( $id );

			if ( null === $template ) {
				echo '<p>' . esc_html__( 'That template no longer exists.', 'protech-wholesale' ) . ' <a href="' . esc_url( MessagingTab::url( 'templates' ) ) . '">' . esc_html__( 'Back to all templates', 'protech-wholesale' ) . '</a></p>';
				return;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects a fixed notice.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="updated notice inline"><p>' . esc_html__( 'Template saved.', 'protech-wholesale' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects a fixed notice.
		if ( isset( $_GET['duplicated'] ) ) {
			echo '<div class="updated notice inline"><p>' . esc_html__( 'This is your copy. Give it a new name and change what you like.', 'protech-wholesale' ) . '</p></div>';
		}

		EmailEditor::render( $template, $is_new ? '' : $id, $errors );
	}

	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, only selects a fixed notice.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="updated notice inline"><p>' . esc_html__( 'Template deleted.', 'protech-wholesale' ) . '</p></div>';
		}

		if ( isset( $_GET['blocked'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: template name. */
					__( '"%s" is still in use, so it was not deleted. Point what uses it at another template first.', 'protech-wholesale' ),
					sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['blocked'] ) ) )
				)
			) . '</p></div>';
		}
		// phpcs:enable

		echo '<h2>' . esc_html__( 'Email templates', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'Designed emails you can reuse: a header, blocks of text, buttons, images and products, and a footer.', 'protech-wholesale' ) . '</p>';

		echo '<h3>' . esc_html__( 'Start a new template', 'protech-wholesale' ) . '</h3><p>';
		echo '<a class="button button-primary" href="' . esc_url( self::edit_url( 'new' ) ) . '">' . esc_html__( 'Blank template', 'protech-wholesale' ) . '</a> ';
		echo '<span class="description">' . esc_html__( 'or start from a ready-made one:', 'protech-wholesale' ) . '</span> ';

		foreach ( EmailStarters::all() as $key => $starter ) {
			echo '<a class="button" href="' . esc_url( self::action_url( self::NEW_ACTION, (string) $key ) ) . '">' . esc_html( (string) $starter['name'] ) . '</a> ';
		}

		echo '</p>';

		$templates = EmailTemplates::all();

		if ( empty( $templates ) ) {
			echo '<p>' . esc_html__( 'No templates yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';

		foreach ( array( __( 'Name', 'protech-wholesale' ), __( 'Type', 'protech-wholesale' ), __( 'Blocks', 'protech-wholesale' ), __( 'Used for', 'protech-wholesale' ), __( 'Updated', 'protech-wholesale' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $templates as $id => $template ) {
			$id   = (string) $id;
			$kind = EmailTemplates::KIND_TRANSACTIONAL === $template['kind']
				? __( 'Service email', 'protech-wholesale' )
				: __( 'Marketing', 'protech-wholesale' );

			$updated = (int) $template['updated_at'] > 0
				? sprintf( /* translators: %s: how long ago, e.g. "2 days". */ __( '%s ago', 'protech-wholesale' ), human_time_diff( (int) $template['updated_at'] ) )
				: '';

			$used = self::used_by( $id, (string) $template['slot'] );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( self::edit_url( $id ) ) . '">' . esc_html( (string) $template['name'] ) . '</a></strong></td>';
			echo '<td>' . esc_html( $kind ) . '</td>';
			echo '<td>' . esc_html( (string) count( (array) $template['blocks'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $used ? $used : '—' ) . '</td>';
			echo '<td>' . esc_html( $updated ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( self::edit_url( $id ) ) . '">' . esc_html__( 'Edit', 'protech-wholesale' ) . '</a> | ';
			echo '<a href="' . esc_url( self::action_url( self::DUPLICATE_ACTION, $id ) ) . '">' . esc_html__( 'Duplicate', 'protech-wholesale' ) . '</a> | ';
			echo '<a href="' . esc_url( self::preview_url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview', 'protech-wholesale' ) . '</a> | ';
			echo '<a href="' . esc_url( self::action_url( self::DELETE_ACTION, $id ) ) . '" class="protech-confirm-delete-template" style="color:#b32d2e;">' . esc_html__( 'Delete', 'protech-wholesale' ) . '</a>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
