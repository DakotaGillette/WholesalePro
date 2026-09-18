<?php
/**
 * The /wholesale portal page body. Approved customers never reach this
 * template (Portal::maybe_redirect_approved_customer() sends them to the
 * shop first) — only 'login', 'pending', 'rejected' and 'retail_only'
 * states render here.
 *
 * 'login' is a two-panel card: a Protech Blue pitch panel (benefits built
 * from the store's real thresholds — Portal::get_benefits()) beside the
 * login form. The other three states share one centred status card.
 *
 * Override by copying to yourtheme/woocommerce/portal.php.
 *
 * @package ProtechWholesale
 *
 * @var string                                            $state
 * @var \WP_Error|null                                    $login_error
 * @var string                                            $username    What was typed into a login that just failed.
 * @var array<int, array{title: string, detail: string}> $benefits
 * @var string                                            $apply_url
 * @var string                                            $contact_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$username = isset( $username ) ? (string) $username : '';
$benefits = isset( $benefits ) && is_array( $benefits ) ? $benefits : array();
?>
<div class="protech-wholesale-portal protech-wholesale-portal--<?php echo esc_attr( $state ); ?>">

	<?php if ( 'login' === $state ) : ?>

		<div class="protech-portal-card">

			<div class="protech-portal-pitch">
				<span class="protech-portal-eyebrow"><?php esc_html_e( 'Protech Sleeves Wholesale', 'protech-wholesale' ); ?></span>
				<h2 class="protech-portal-headline"><?php esc_html_e( 'Stock your shelves at partner pricing.', 'protech-wholesale' ); ?></h2>
				<p class="protech-portal-lede"><?php esc_html_e( 'For game stores and collectibles retailers. Approved partners order straight from the shop, at their own prices.', 'protech-wholesale' ); ?></p>

				<?php if ( ! empty( $benefits ) ) : ?>
					<ul class="protech-portal-benefits">
						<?php foreach ( $benefits as $protech_benefit ) : ?>
							<li class="protech-portal-benefit">
								<span class="protech-portal-benefit-icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" width="16" height="16" focusable="false"><path d="m5.5 12.5 4.2 4.2 8.8-9.4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
								</span>
								<span class="protech-portal-benefit-text">
									<strong><?php echo esc_html( $protech_benefit['title'] ?? '' ); ?></strong>
									<span><?php echo esc_html( $protech_benefit['detail'] ?? '' ); ?></span>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="protech-portal-form-panel">
				<h2 class="protech-portal-form-title"><?php esc_html_e( 'Partner login', 'protech-wholesale' ); ?></h2>
				<p class="protech-portal-form-intro"><?php esc_html_e( 'Welcome back. Log in to see your wholesale pricing.', 'protech-wholesale' ); ?></p>

				<?php if ( $login_error instanceof \WP_Error ) : ?>
					<div class="protech-portal-error" role="alert">
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5v5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.4" r="1.2" fill="currentColor"/></svg>
						<span><?php echo esc_html( wp_strip_all_tags( $login_error->get_error_message() ) ); ?></span>
					</div>
				<?php endif; ?>

				<form method="post" class="protech-portal-login-form">
					<?php wp_nonce_field( 'protech_wholesale_login', 'protech_wholesale_login_nonce' ); ?>

					<p class="protech-portal-field">
						<label for="protech-username"><?php esc_html_e( 'Email or username', 'protech-wholesale' ); ?></label>
						<input type="text" id="protech-username" name="protech_username" value="<?php echo esc_attr( $username ); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required />
					</p>

					<p class="protech-portal-field">
						<label for="protech-password"><?php esc_html_e( 'Password', 'protech-wholesale' ); ?></label>
						<span class="protech-portal-password">
							<input type="password" id="protech-password" name="protech_password" autocomplete="current-password" required />
							<button
								type="button"
								class="protech-password-toggle"
								aria-controls="protech-password"
								aria-pressed="false"
								aria-label="<?php esc_attr_e( 'Show password', 'protech-wholesale' ); ?>"
								data-label-show="<?php esc_attr_e( 'Show password', 'protech-wholesale' ); ?>"
								data-label-hide="<?php esc_attr_e( 'Hide password', 'protech-wholesale' ); ?>"
								hidden
							>
								<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.7"/><path class="protech-password-toggle-slash" d="M4 4l16 16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
							</button>
						</span>
					</p>

					<p class="protech-portal-row">
						<label class="protech-portal-remember">
							<input type="checkbox" name="protech_remember" value="1" />
							<span><?php esc_html_e( 'Remember me', 'protech-wholesale' ); ?></span>
						</label>
						<a class="protech-lost-password" href="<?php echo esc_url( wp_lostpassword_url( home_url( '/wholesale' ) ) ); ?>">
							<?php esc_html_e( 'Lost your password?', 'protech-wholesale' ); ?>
						</a>
					</p>

					<p class="protech-portal-submit">
						<button type="submit" class="protech-button protech-button--block"><?php esc_html_e( 'Log in', 'protech-wholesale' ); ?></button>
					</p>
				</form>

				<div class="protech-portal-apply">
					<span class="protech-portal-divider"><span><?php esc_html_e( 'Not a partner yet?', 'protech-wholesale' ); ?></span></span>
					<a class="protech-button protech-button--outline protech-button--block" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply for a wholesale account', 'protech-wholesale' ); ?></a>
				</div>
			</div>

		</div>

	<?php elseif ( 'pending' === $state ) : ?>

		<div class="protech-portal-status protech-portal-pending">
			<span class="protech-portal-status-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="28" height="28" focusable="false"><circle cx="12" cy="12" r="8.6" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.4V12l3 2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</span>
			<h2><?php esc_html_e( 'Your application is under review', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( 'Thanks for applying for a Protech Sleeves wholesale account. We typically respond within 1–3 business days.', 'protech-wholesale' ); ?></p>

			<ol class="protech-portal-steps">
				<li class="protech-portal-step is-done"><span class="protech-portal-step-dot" aria-hidden="true"></span><?php esc_html_e( 'Application received', 'protech-wholesale' ); ?></li>
				<li class="protech-portal-step is-current" aria-current="step"><span class="protech-portal-step-dot" aria-hidden="true"></span><?php esc_html_e( 'Under review', 'protech-wholesale' ); ?></li>
				<li class="protech-portal-step"><span class="protech-portal-step-dot" aria-hidden="true"></span><?php esc_html_e( 'Approved: wholesale pricing unlocked', 'protech-wholesale' ); ?></li>
			</ol>

			<p>
				<a href="<?php echo esc_url( $contact_url ); ?>"><?php esc_html_e( 'Have a question? Contact us.', 'protech-wholesale' ); ?></a>
			</p>
		</div>

	<?php elseif ( 'rejected' === $state ) : ?>

		<div class="protech-portal-status protech-portal-rejected">
			<span class="protech-portal-status-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="28" height="28" focusable="false"><path d="M4 6.5h16v11H4z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m4.5 7 7.5 6 7.5-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</span>
			<h2><?php esc_html_e( 'Update on your wholesale application', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( "We weren't able to approve your wholesale application at this time. If you think this was a mistake, or your business has changed since you applied, please get in touch.", 'protech-wholesale' ); ?></p>
			<p>
				<a class="protech-button protech-button--outline" href="<?php echo esc_url( $contact_url ); ?>"><?php esc_html_e( 'Contact us', 'protech-wholesale' ); ?></a>
			</p>
		</div>

	<?php elseif ( 'retail_only' === $state ) : ?>

		<div class="protech-portal-status protech-portal-retail-only">
			<span class="protech-portal-status-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="28" height="28" focusable="false"><path d="M4.5 9.5 6 4.5h12l1.5 5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M4.5 9.5a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0M6 12v7.5h12V12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</span>
			<h2><?php esc_html_e( 'This page is for approved wholesale accounts', 'protech-wholesale' ); ?></h2>
			<p><?php esc_html_e( 'Run a game store or collectibles shop? Apply for a wholesale account below.', 'protech-wholesale' ); ?></p>
			<p>
				<a class="protech-button" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply for a wholesale account', 'protech-wholesale' ); ?></a>
			</p>
		</div>

	<?php endif; ?>

</div>
