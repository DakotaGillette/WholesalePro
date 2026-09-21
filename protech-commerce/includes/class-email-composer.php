<?php
/**
 * The email templates screens under Messaging → Email templates.
 *
 * This release: the library list, and a preview that renders any template
 * exactly as it would be sent. The editor (blocks, drag and drop, live
 * preview) arrives next; nothing here changes how a message is sent.
 *
 * The preview goes through EmailRenderer, the same code the real send uses,
 * so what the admin sees cannot drift from what lands in an inbox.
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

	public const PREVIEW_ACTION = 'protech_preview_email_template';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
	}

	/** The signed link that opens a template's preview. */
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

	/**
	 * A template as it would arrive, for $user_id (the admin's own account by
	 * default, so merge tags fill in with real values). Marketing templates
	 * carry the same footer a real send adds.
	 *
	 * @param array<string, mixed> $template
	 */
	public static function preview_html( array $template, int $user_id ): string {
		$category = EmailTemplates::KIND_TRANSACTIONAL === ( $template['kind'] ?? '' ) ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;

		return EmailRenderer::render(
			$template,
			EmailRenderer::context( $user_id, true ),
			array( 'footer_html' => MessageTransport::footer_html_for( $user_id, $category ) )
		)['html'];
	}

	/** Opens a preview in its own page, or in the editor's frame later. */
	public function handle_preview(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['template_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::PREVIEW_ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$template = EmailTemplates::get( $id );

		if ( null === $template ) {
			wp_die( esc_html__( 'That template no longer exists.', 'protech-wholesale' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		echo self::preview_html( $template, get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a finished email document, escaped where it is built.
		exit;
	}

	/** Where each template is used: the lifecycle email it is bound to, and the rules and campaigns pointing at it. */
	private static function used_by( string $id, string $slot ): string {
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

	/** The library list. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		echo '<h2>' . esc_html__( 'Email templates', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'Designed emails you can reuse: a header, blocks of text, buttons, images and products, and a footer. Preview shows a template with your own name filled in.', 'protech-wholesale' ) . '</p>';

		$templates = EmailTemplates::all();

		if ( empty( $templates ) ) {
			echo '<p>' . esc_html__( 'No templates yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';

		foreach ( array( __( 'Name', 'protech-wholesale' ), __( 'Type', 'protech-wholesale' ), __( 'Blocks', 'protech-wholesale' ), __( 'Used for', 'protech-wholesale' ), __( 'Updated', 'protech-wholesale' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $templates as $id => $template ) {
			$kind = EmailTemplates::KIND_TRANSACTIONAL === $template['kind']
				? __( 'Service email', 'protech-wholesale' )
				: __( 'Marketing', 'protech-wholesale' );

			$updated = (int) $template['updated_at'] > 0
				? sprintf( /* translators: %s: how long ago, e.g. "2 days". */ __( '%s ago', 'protech-wholesale' ), human_time_diff( (int) $template['updated_at'] ) )
				: '';

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $template['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $kind ) . '</td>';
			echo '<td>' . esc_html( (string) count( (array) $template['blocks'] ) ) . '</td>';
			echo '<td>' . esc_html( self::used_by( (string) $id, (string) $template['slot'] ) ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $updated ) . '</td>';
			echo '<td><a href="' . esc_url( self::preview_url( (string) $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview', 'protech-wholesale' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
