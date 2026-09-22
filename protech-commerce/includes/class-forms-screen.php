<?php
/**
 * Messaging → Forms: signup forms an admin can create and embed with
 * `[protech_signup id="..."]`. A plain list-and-edit screen (no client-side
 * editor): a form is four short text fields, not a layout to design.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FormsScreen
 */
class FormsScreen {

	public const SAVE_ACTION   = 'protech_save_signup_form';
	public const DELETE_ACTION = 'protech_delete_signup_form';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
	}

	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';

		if ( '' !== $edit ) {
			self::render_form( 'new' === $edit ? SignupForms::defaults() : ( SignupForms::get( $edit ) ?? SignupForms::defaults() ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="updated notice inline"><p>' . esc_html__( 'Form saved.', 'protech-wholesale' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Signup forms', 'protech-wholesale' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A submission starts double opt-in: the person confirms by email before anything is marked subscribed.', 'protech-wholesale' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( MessagingTab::url( 'forms', array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'New form', 'protech-wholesale' ) . '</a></p>';

		$forms = SignupForms::all();

		if ( empty( $forms ) ) {
			echo '<p>' . esc_html__( 'No signup forms yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px;"><thead><tr><th>' . esc_html__( 'Name', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Shortcode', 'protech-wholesale' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $forms as $id => $form ) {
			echo '<tr>';
			echo '<td><a href="' . esc_url( MessagingTab::url( 'forms', array( 'edit' => $id ) ) ) . '"><strong>' . esc_html( (string) $form['name'] ) . '</strong></a></td>';
			echo '<td><code>[protech_signup id="' . esc_html( (string) $id ) . '"]</code></td>';

			$delete_url = wp_nonce_url( add_query_arg( array( 'action' => self::DELETE_ACTION, 'form_id' => $id ), admin_url( 'admin-post.php' ) ), self::DELETE_ACTION . '_' . $id );
			echo '<td><a href="' . esc_url( MessagingTab::url( 'forms', array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Edit', 'protech-wholesale' ) . '</a> | <a href="' . esc_url( $delete_url ) . '" style="color:#b32d2e;">' . esc_html__( 'Delete', 'protech-wholesale' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/** @param array<string, mixed> $form */
	private static function render_form( array $form ): void {
		$is_new = '' === (string) $form['id'];

		echo '<p><a href="' . esc_url( MessagingTab::url( 'forms' ) ) . '">&larr; ' . esc_html__( 'All forms', 'protech-wholesale' ) . '</a></p>';
		echo '<h2>' . esc_html( $is_new ? __( 'New signup form', 'protech-wholesale' ) : (string) $form['name'] ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $form['id'] ) . '" />';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="protech-form-name">' . esc_html__( 'Name', 'protech-wholesale' ) . '</label></th><td><input type="text" id="protech-form-name" name="name" value="' . esc_attr( (string) $form['name'] ) . '" class="regular-text" /><p class="description">' . esc_html__( 'For your own reference; never shown to a visitor.', 'protech-wholesale' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'First name field', 'protech-wholesale' ) . '</th><td><label><input type="checkbox" name="show_name_field" value="1" ' . checked( ! empty( $form['show_name_field'] ), true, false ) . ' /> ' . esc_html__( 'Ask for a first name, not only an email address.', 'protech-wholesale' ) . '</label></td></tr>';

		echo '<tr><th><label for="protech-form-consent">' . esc_html__( 'Consent wording', 'protech-wholesale' ) . '</label></th><td><textarea id="protech-form-consent" name="consent_text" rows="2" class="large-text">' . esc_textarea( (string) $form['consent_text'] ) . '</textarea><p class="description">' . esc_html__( 'Shown next to the required checkbox. This is what a subscriber is agreeing to.', 'protech-wholesale' ) . '</p></td></tr>';

		echo '<tr><th><label for="protech-form-success">' . esc_html__( 'Message after submitting', 'protech-wholesale' ) . '</label></th><td><textarea id="protech-form-success" name="success_message" rows="2" class="large-text">' . esc_textarea( (string) $form['success_message'] ) . '</textarea></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save form', 'protech-wholesale' ) );
		echo '</form>';

		if ( ! $is_new ) {
			echo '<p>' . esc_html__( 'Embed it with:', 'protech-wholesale' ) . ' <code>[protech_signup id="' . esc_html( (string) $form['id'] ) . '"]</code></p>';
		}
	}

	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input = array(
			'id'              => sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ),
			'name'            => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'show_name_field' => isset( $_POST['show_name_field'] ),
			'consent_text'    => sanitize_text_field( wp_unslash( $_POST['consent_text'] ?? '' ) ),
			'success_message' => sanitize_text_field( wp_unslash( $_POST['success_message'] ?? '' ) ),
		);

		$result = SignupForms::validate( $input );

		if ( ! empty( $result['errors'] ) ) {
			wp_die( esc_html( implode( ' ', $result['errors'] ) ), esc_html__( 'Could not save', 'protech-wholesale' ), array( 'back_link' => true ) );
		}

		SignupForms::save( $result['form'] );

		wp_safe_redirect( add_query_arg( 'saved', 1, MessagingTab::url( 'forms' ) ) );
		exit;
	}

	public function handle_delete(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['form_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::DELETE_ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		SignupForms::delete( $id );

		wp_safe_redirect( MessagingTab::url( 'forms' ) );
		exit;
	}
}
