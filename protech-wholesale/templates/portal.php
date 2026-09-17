<?php
/**
 * The /wholesale portal page body. Approved customers never reach this
 * template (Portal::maybe_redirect_approved_customer() sends them to the
 * shop first) — only 'login', 'pending', and 'retail_only' states render
 * here.
 *
 * Override by copying to yourtheme/woocommerce/portal.php.
 *
 * @package ProtechWholesale
 *
 * @var string          $state
 * @var \WP_Error|null  $login_error
 * @var string          $apply_url
 * @var string          $contact_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="protech-wholesale-portal protech-wholesale-portal--<?php echo esc_attr( $state ); ?>">

	<?php if ( 'login' === $state ) : ?>

		<div class="protech-portal-pitch">
			<h2><?php esc_html_e( 'Wholesale Partner Login', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( 'Approved retailers can log in below to see wholesale pricing and reorder in under a minute.', 'protech-wholesale' ); ?></p>
		</div>

		<?php if ( $login_error instanceof \WP_Error ) : ?>
			<div class="woocommerce-error"><?php echo esc_html( $login_error->get_error_message() ); ?></div>
		<?php endif; ?>

		<form method="post" class="protech-portal-login-form">
			<?php wp_nonce_field( 'protech_wholesale_login', 'protech_wholesale_login_nonce' ); ?>
			<p>
				<label for="protech-username"><?php esc_html_e( 'Email or username', 'protech-wholesale' ); ?></label>
				<input type="text" id="protech-username" name="protech_username" required />
			</p>
			<p>
				<label for="protech-password"><?php esc_html_e( 'Password', 'protech-wholesale' ); ?></label>
				<input type="password" id="protech-password" name="protech_password" required />
			</p>
			<p>
				<label>
					<input type="checkbox" name="protech_remember" value="1" />
					<?php esc_html_e( 'Remember me', 'protech-wholesale' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button alt"><?php esc_html_e( 'Log in', 'protech-wholesale' ); ?></button>
				<a class="protech-lost-password" href="<?php echo esc_url( wp_lostpassword_url( home_url( '/wholesale' ) ) ); ?>">
					<?php esc_html_e( 'Lost your password?', 'protech-wholesale' ); ?>
				</a>
			</p>
		</form>

		<p class="protech-portal-apply">
			<?php esc_html_e( 'Not a partner yet?', 'protech-wholesale' ); ?>
			<a class="button" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply here', 'protech-wholesale' ); ?></a>
		</p>

	<?php elseif ( 'pending' === $state ) : ?>

		<div class="protech-portal-pending">
			<h2><?php esc_html_e( 'Your application is under review', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( 'Thanks for applying for a Protech Sleeves wholesale account. We typically respond within 1–3 business days.', 'protech-wholesale' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $contact_url ); ?>"><?php esc_html_e( 'Have a question? Contact us.', 'protech-wholesale' ); ?></a>
			</p>
		</div>

	<?php elseif ( 'rejected' === $state ) : ?>

		<div class="protech-portal-rejected">
			<h2><?php esc_html_e( 'Update on your wholesale application', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( "We weren't able to approve your wholesale application at this time. If you think this was a mistake, or your business has changed since you applied, please get in touch.", 'protech-wholesale' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $contact_url ); ?>"><?php esc_html_e( 'Contact us', 'protech-wholesale' ); ?></a>
			</p>
		</div>

	<?php elseif ( 'retail_only' === $state ) : ?>

		<div class="protech-portal-retail-only">
			<h2><?php esc_html_e( 'This page is for approved wholesale accounts', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( 'Run a game store or collectibles shop? Apply for a wholesale account below.', 'protech-wholesale' ); ?></p>
			<a class="button" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply here', 'protech-wholesale' ); ?></a>
		</div>

	<?php endif; ?>

</div>
