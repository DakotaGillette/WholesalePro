<?php
/**
 * The wholesale status strip across the top of every My Account page —
 * see MyAccount::render_account_header().
 *
 * Override by copying to yourtheme/woocommerce/account-wholesale-header.php.
 *
 * @package ProtechWholesale
 *
 * @var string $status       'approved' or 'pending'.
 * @var string $display_name
 * @var string $company      May be empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$protech_is_approved = 'approved' === $status;
$protech_heading     = '' !== $company ? $company : $display_name;
?>
<div class="protech-account-header protech-account-header--<?php echo esc_attr( $status ); ?>">
	<span class="protech-account-header-icon" aria-hidden="true">
		<?php if ( $protech_is_approved ) : ?>
			<svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path d="M12 2.8 4.5 5.6v5.9c0 4.6 3.1 8.4 7.5 9.7 4.4-1.3 7.5-5.1 7.5-9.7V5.6L12 2.8Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m8.6 12.2 2.4 2.4 4.5-4.8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
		<?php else : ?>
			<svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><circle cx="12" cy="12" r="8.6" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.4V12l3 2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
		<?php endif; ?>
	</span>

	<div class="protech-account-header-text">
		<span class="protech-account-header-name"><?php echo esc_html( $protech_heading ); ?></span>
		<span class="protech-account-header-note">
			<?php
			if ( $protech_is_approved ) {
				esc_html_e( 'Wholesale pricing is active on every product while you are signed in.', 'protech-wholesale' );
			} else {
				esc_html_e( 'You will see wholesale pricing as soon as your application is approved.', 'protech-wholesale' );
			}
			?>
		</span>
	</div>

	<span class="protech-badge <?php echo $protech_is_approved ? 'protech-badge--partner' : 'protech-badge--pending'; ?>">
		<?php
		if ( $protech_is_approved ) {
			esc_html_e( 'Wholesale Partner', 'protech-wholesale' );
		} else {
			esc_html_e( 'Application under review', 'protech-wholesale' );
		}
		?>
	</span>
</div>
