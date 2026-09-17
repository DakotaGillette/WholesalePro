<?php
/**
 * Native wholesale application form fallback.
 *
 * Used only when Settings::OPT_APPLICATION_SOURCE is "native" — i.e. no
 * supported form plugin adapter is active. Field set mirrors the
 * existing /wholesale-application page described in the master prompt.
 *
 * Override by copying to yourtheme/woocommerce/application-form.php.
 *
 * @package ProtechWholesale
 * @var string $action_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['wholesale_application'] ) ? sanitize_key( wp_unslash( $_GET['wholesale_application'] ) ) : '';
?>
<div class="protech-wholesale-application-form">
	<?php if ( 'received' === $notice ) : ?>
		<div class="woocommerce-message">
			<?php esc_html_e( 'Thanks! Your application has been received. We typically respond within 1–3 business days.', 'protech-wholesale' ); ?>
		</div>
	<?php elseif ( 'error' === $notice ) : ?>
		<div class="woocommerce-error">
			<?php esc_html_e( 'Something went wrong submitting your application. Please check your details and try again.', 'protech-wholesale' ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>">
		<input type="hidden" name="action" value="protech_submit_application" />
		<?php wp_nonce_field( 'protech_wholesale_application', 'protech_wholesale_application_nonce' ); ?>

		<p class="protech-hp-field" style="position:absolute;left:-9999px;" aria-hidden="true">
			<label for="protech_wholesale_website_confirm">Leave this field empty</label>
			<input type="text" name="protech_wholesale_website_confirm" id="protech_wholesale_website_confirm" tabindex="-1" autocomplete="off" />
		</p>

		<p>
			<label for="pw-name"><?php esc_html_e( 'Name', 'protech-wholesale' ); ?> <span class="required">*</span></label>
			<input type="text" id="pw-name" name="name" required />
		</p>
		<p>
			<label for="pw-title"><?php esc_html_e( 'Title', 'protech-wholesale' ); ?></label>
			<input type="text" id="pw-title" name="title" />
		</p>
		<p>
			<label for="pw-phone"><?php esc_html_e( 'Phone', 'protech-wholesale' ); ?> <span class="required">*</span></label>
			<input type="tel" id="pw-phone" name="phone" required />
		</p>
		<p>
			<label for="pw-email"><?php esc_html_e( 'Email', 'protech-wholesale' ); ?> <span class="required">*</span></label>
			<input type="email" id="pw-email" name="email" required />
		</p>
		<p>
			<label for="pw-store-name"><?php esc_html_e( 'Store name', 'protech-wholesale' ); ?> <span class="required">*</span></label>
			<input type="text" id="pw-store-name" name="store_name" required />
		</p>
		<p>
			<label for="pw-business-type"><?php esc_html_e( 'Business type', 'protech-wholesale' ); ?></label>
			<input type="text" id="pw-business-type" name="business_type" />
		</p>
		<p>
			<label for="pw-address"><?php esc_html_e( 'Address', 'protech-wholesale' ); ?> <span class="required">*</span></label>
			<textarea id="pw-address" name="address" required></textarea>
		</p>
		<p>
			<label for="pw-website"><?php esc_html_e( 'Website', 'protech-wholesale' ); ?></label>
			<input type="url" id="pw-website" name="website" />
		</p>
		<p>
			<label for="pw-sales-channels"><?php esc_html_e( 'Sales channels', 'protech-wholesale' ); ?></label>
			<input type="text" id="pw-sales-channels" name="sales_channels" />
		</p>
		<p>
			<label for="pw-tcgs"><?php esc_html_e( 'TCGs carried', 'protech-wholesale' ); ?></label>
			<input type="text" id="pw-tcgs" name="tcgs_carried" />
		</p>
		<p>
			<label>
				<input type="checkbox" name="hosts_events" value="1" />
				<?php esc_html_e( 'We host TCG events', 'protech-wholesale' ); ?>
			</label>
		</p>
		<p>
			<label for="pw-spend"><?php esc_html_e( 'Estimated monthly spend', 'protech-wholesale' ); ?></label>
			<input type="text" id="pw-spend" name="estimated_monthly_spend" />
		</p>
		<p>
			<label>
				<input type="checkbox" name="accuracy_confirmation" value="1" required />
				<?php esc_html_e( 'I confirm the information above is accurate.', 'protech-wholesale' ); ?>
			</label>
		</p>

		<p>
			<button type="submit" class="button"><?php esc_html_e( 'Submit application', 'protech-wholesale' ); ?></button>
		</p>
	</form>
</div>
