<?php
/**
 * A signup form's markup ([protech_signup id="..."], SignupForms::shortcode()).
 * Submits a plain POST to admin-post.php, no JavaScript required.
 *
 * Override by copying to yourtheme/woocommerce/signup-form.php.
 *
 * @package ProtechWholesale
 *
 * @var array<string, mixed> $form
 * @var string               $notice      '', 'ok' or 'error', from ?protech_signup=.
 * @var string               $submit_url
 * @var string               $redirect_to
 */

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="protech-signup-form">
	<?php if ( 'ok' === $notice ) : ?>
		<p class="protech-signup-form__notice"><?php echo esc_html( (string) $form['success_message'] ); ?></p>
	<?php else : ?>
		<?php if ( 'error' === $notice ) : ?>
			<p class="protech-signup-form__error"><?php esc_html_e( 'Enter a valid email address.', 'protech-wholesale' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $submit_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( SignupForms::SUBMIT_ACTION ); ?>" />
			<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form['id'] ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />

			<!-- A real visitor never sees or fills this in; a bot filling every field usually does. -->
			<p style="position:absolute;left:-9999px;" aria-hidden="true">
				<label>Website<input type="text" name="protech_hp" tabindex="-1" autocomplete="off" /></label>
			</p>

			<?php if ( ! empty( $form['show_name_field'] ) ) : ?>
				<p>
					<label for="protech-signup-first-name"><?php esc_html_e( 'First name', 'protech-wholesale' ); ?></label>
					<input type="text" id="protech-signup-first-name" name="first_name" autocomplete="given-name" />
				</p>
			<?php endif; ?>

			<p>
				<label for="protech-signup-email"><?php esc_html_e( 'Email', 'protech-wholesale' ); ?></label>
				<input type="email" id="protech-signup-email" name="email" required="required" autocomplete="email" />
			</p>

			<p>
				<label>
					<input type="checkbox" name="consent" value="1" required="required" />
					<?php echo esc_html( (string) $form['consent_text'] ); ?>
				</label>
			</p>

			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Subscribe', 'protech-wholesale' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
