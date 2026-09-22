<?php
/**
 * Messaging → Email templates: the library, and everything around the
 * client-side editor that is not the editor itself.
 *
 * The library lists every template with what it is used for and lets you
 * start a new one from a starter, edit, copy, preview or delete it, each
 * through admin-post.php and a redirect. Editing a template is a mount
 * point (render_editor()) for the Preact app in editor-src/, which saves,
 * previews and sends test emails through the protech/v1 REST routes
 * (RestTemplates) instead: this class only decides which template that app
 * opens on (template_for_edit(), also read by Plugin::enqueue_admin_assets()
 * to localize the same template into the page).
 *
 * The one admin-post preview (handle_preview) is unrelated to the editor's
 * own live preview: it is the "Preview" link on the library list, opening a
 * saved template through EmailRenderer, the same code a real send uses, so
 * what the admin sees cannot drift from what lands in an inbox.
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

	public const PREVIEW_ACTION   = 'protech_preview_email_template';
	public const NEW_ACTION       = 'protech_new_email_template';
	public const DUPLICATE_ACTION = 'protech_duplicate_email_template';
	public const DELETE_ACTION    = 'protech_delete_email_template';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
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

	/**
	 * The template the editor opens on: a blank one for a new template, the
	 * saved one otherwise. Shared with Plugin::enqueue_admin_assets(), which
	 * has to localize the same template into `window.protechEditor` before
	 * this method ever runs, so the two can never disagree about which
	 * template is being edited.
	 *
	 * @return array<string, mixed>
	 */
	public static function template_for_edit( string $id ): array {
		if ( 'new' === $id || '' === $id ) {
			return EmailTemplates::defaults();
		}

		return EmailTemplates::get( $id ) ?? EmailTemplates::defaults();
	}

	private static function render_editor( string $id ): void {
		$is_new = 'new' === $id || '' === $id;

		if ( ! $is_new && null === EmailTemplates::get( $id ) ) {
			echo '<p>' . esc_html__( 'That template no longer exists.', 'protech-wholesale' ) . ' <a href="' . esc_url( MessagingTab::url( 'templates' ) ) . '">' . esc_html__( 'Back to all templates', 'protech-wholesale' ) . '</a></p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects a fixed notice.
		if ( isset( $_GET['duplicated'] ) ) {
			echo '<div class="updated notice inline"><p>' . esc_html__( 'This is your copy. Give it a new name and change what you like.', 'protech-wholesale' ) . '</p></div>';
		}

		echo '<div id="protech-editor-root" data-template-id="' . esc_attr( $is_new ? '' : $id ) . '"></div>';
		echo '<noscript><p>' . esc_html__( 'This editor needs JavaScript enabled in your browser.', 'protech-wholesale' ) . '</p></noscript>';
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
