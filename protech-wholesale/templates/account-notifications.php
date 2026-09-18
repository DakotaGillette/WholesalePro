<?php
/**
 * My Account -> Notifications: a wholesale customer's own SMS number and
 * consent choices. See NotificationsEndpoint::render().
 *
 * Override by copying to yourtheme/woocommerce/account-notifications.php.
 *
 * @package ProtechWholesale
 *
 * @var array{phone: string, sms_transactional: string, sms_marketing: string, email_marketing: string} $state
 * @var string $wording_transactional
 * @var string $wording_marketing
 * @var string $nonce_action
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" class="protech-notifications-form">
	<?php wp_nonce_field( $nonce_action, 'protech_wholesale_notifications_nonce' ); ?>

	<p>
		<label for="protech_sms_phone"><?php esc_html_e( 'SMS number', 'protech-wholesale' ); ?></label>
		<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" id="protech_sms_phone" name="protech_sms_phone" value="<?php echo esc_attr( $state['phone'] ); ?>" placeholder="+1 555 555 0100" />
	</p>

	<p>
		<label>
			<input type="checkbox" name="protech_sms_transactional" value="1" <?php checked( 'yes', $state['sms_transactional'] ); ?> />
			<?php esc_html_e( 'Order-update texts (shipping, delivery)', 'protech-wholesale' ); ?>
		</label>
		<br /><span class="description"><?php echo esc_html( $wording_transactional ); ?></span>
	</p>

	<p>
		<label>
			<input type="checkbox" name="protech_sms_marketing" value="1" <?php checked( 'yes', $state['sms_marketing'] ); ?> />
			<?php esc_html_e( 'Offers and reorder reminders by text', 'protech-wholesale' ); ?>
		</label>
		<br /><span class="description"><?php echo esc_html( $wording_marketing ); ?></span>
	</p>

	<p>
		<label>
			<input type="checkbox" name="protech_email_marketing" value="1" <?php checked( 'no' !== $state['email_marketing'] ); ?> />
			<?php esc_html_e( 'Marketing emails (offers, reorder reminders)', 'protech-wholesale' ); ?>
		</label>
		<br /><span class="description"><?php esc_html_e( 'Order confirmations and shipping emails are sent either way.', 'protech-wholesale' ); ?></span>
	</p>

	<p>
		<button type="submit" class="button"><?php esc_html_e( 'Save preferences', 'protech-wholesale' ); ?></button>
	</p>
</form>
