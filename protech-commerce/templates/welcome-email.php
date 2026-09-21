<?php
/**
 * The "Welcome to Protech Wholesale" email body (WelcomeEmail::body_html()):
 * how to log in, how displays and cases work (the same legend as the
 * product page, rebuilt from tables and inline styles because email
 * clients ignore stylesheets), and what the quantity tiers unlock.
 * WooCommerce wraps it in the store's branded header and footer.
 *
 * Override by copying to yourtheme/woocommerce/welcome-email.php.
 *
 * @package ProtechWholesale
 *
 * @var string $first_name
 * @var string $email             The address they log in with.
 * @var string $login_url         The wholesale login page.
 * @var string $lost_password_url
 * @var int    $case_size         Packs per display.
 * @var int    $displays_per_case
 * @var int    $case_packs        Packs per case.
 * @var int    $volume_threshold  Displays that unlock free shipping.
 * @var string $threshold_cases   The same quantity in cases.
 * @var int    $bulk_cases        Cases that unlock the best price.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$protech_allowed = array( 'a' => array( 'href' => true, 'style' => true ), 'strong' => array() );
$protech_blue    = '#42649d';
$protech_border = '#c9d6ea';

$protech_step = static function ( string $number ) use ( $protech_blue ): string {
	return \ProtechWholesale\EmailBlocks::step_number( $number, $protech_blue );
};
?>
<p style="margin:0 0 16px;"><?php echo esc_html( '' !== $first_name ? sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'protech-wholesale' ), $first_name ) : __( 'Hi,', 'protech-wholesale' ) ); ?></p>
<p style="margin:0 0 20px;"><?php esc_html_e( 'Your Protech Sleeves account now has wholesale pricing. Here is how to log in and how ordering works.', 'protech-wholesale' ); ?></p>

<h2 style="margin:24px 0 10px;font-size:18px;color:<?php echo esc_attr( $protech_blue ); ?>;"><?php esc_html_e( 'How to log in', 'protech-wholesale' ); ?></h2>
<p style="margin:0 0 8px;"><?php echo $protech_step( '1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts. ?><?php echo wp_kses( sprintf( /* translators: %s: link to the wholesale login page. */ __( 'Go to %s.', 'protech-wholesale' ), '<a href="' . esc_url( $login_url ) . '" style="color:' . esc_attr( $protech_blue ) . ';font-weight:bold;">' . esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $login_url ) ) ) . '</a>' ), $protech_allowed ); ?></p>
<p style="margin:0 0 8px;"><?php echo $protech_step( '2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts. ?><?php echo wp_kses( sprintf( /* translators: 1: their email address, 2: link to reset a password. */ __( 'Sign in with %1$s and the password you already use. Not sure of it? %2$s.', 'protech-wholesale' ), '<strong>' . esc_html( $email ) . '</strong>', '<a href="' . esc_url( $lost_password_url ) . '" style="color:' . esc_attr( $protech_blue ) . ';">' . esc_html__( 'Reset your password', 'protech-wholesale' ) . '</a>' ), $protech_allowed ); ?></p>
<p style="margin:0 0 16px;"><?php echo $protech_step( '3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts. ?><?php esc_html_e( 'Once you are in, every price in the shop is your wholesale price.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 24px;"><a href="<?php echo esc_url( $login_url ); ?>" style="display:inline-block;padding:12px 22px;background:<?php echo esc_attr( $protech_blue ); ?>;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;"><?php esc_html_e( 'Log in to wholesale', 'protech-wholesale' ); ?></a></p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;background:#eef3fa;border:1px solid <?php echo esc_attr( $protech_border ); ?>;border-radius:8px;">
	<tr>
		<td style="padding:20px 22px;">
			<p style="margin:0 0 16px;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:<?php echo esc_attr( $protech_blue ); ?>;"><?php esc_html_e( 'How wholesale quantities work', 'protech-wholesale' ); ?></p>
			<?php echo \ProtechWholesale\EmailBlocks::quantity_diagram( array( 'case_size' => $case_size, 'displays_per_case' => $displays_per_case, 'case_packs' => $case_packs, 'blue' => $protech_blue ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a template, escaped inside. ?>
		</td>
	</tr>
</table>

<h2 style="margin:24px 0 10px;font-size:18px;color:<?php echo esc_attr( $protech_blue ); ?>;"><?php esc_html_e( 'The more you order, the more you save', 'protech-wholesale' ); ?></h2>
<?php echo \ProtechWholesale\EmailBlocks::pricing_ladder( array( 'displays_per_case' => $displays_per_case, 'volume_threshold' => $volume_threshold, 'threshold_cases' => $threshold_cases, 'bulk_cases' => $bulk_cases ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a template, escaped inside. ?>

<p style="margin:0 0 8px;"><?php esc_html_e( 'To restock fast, open any past order in My Account and choose Reorder.', 'protech-wholesale' ); ?></p>
<p style="margin:0;"><?php esc_html_e( 'Questions? Reply to this email and we will help.', 'protech-wholesale' ); ?></p>
