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
$protech_ink    = '#111111';
$protech_muted  = '#6b7280';
$protech_swatch = array( '#42649d', '#d64545', '#e8a33d', '#3f9e63', '#111111', '#8e5bd1', '#e77ab0', '#d9d9d9' );

$protech_pack_dots     = min( 10, $case_size );
$protech_display_tiles = min( 8, $displays_per_case );

// The drawing lives in EmailBlocks, shared with the email composer.
$protech_grid = static function ( int $count, int $columns, int $width, int $height, array $colors, string $outline ): string {
	return \ProtechWholesale\EmailBlocks::swatch_grid( $count, $columns, $width, $height, $colors, $outline );
};

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
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
				<tr>
					<td valign="top" width="30%" style="padding-right:6px;">
						<table role="presentation" cellspacing="0" cellpadding="0" border="0" height="56"><tr><td valign="middle">
							<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="26" height="36" style="width:26px;height:36px;background:<?php echo esc_attr( $protech_blue ); ?>;border-radius:3px;font-size:0;line-height:0;">&nbsp;</td></tr></table>
						</td></tr></table>
						<p style="margin:6px 0 2px;font-size:16px;font-weight:bold;color:<?php echo esc_attr( $protech_ink ); ?>;"><?php esc_html_e( '1 pack', 'protech-wholesale' ); ?></p>
						<p style="margin:0;font-size:12px;color:<?php echo esc_attr( $protech_muted ); ?>;"><?php esc_html_e( 'What your customer buys', 'protech-wholesale' ); ?></p>
					</td>
					<td valign="top" width="5%" style="padding:20px 4px 0;font-size:13px;font-weight:bold;color:<?php echo esc_attr( $protech_blue ); ?>;"><?php echo esc_html( sprintf( /* translators: %d: packs per display. */ __( '×%d', 'protech-wholesale' ), $case_size ) ); ?></td>
					<td valign="top" width="30%" style="padding-right:6px;">
						<table role="presentation" cellspacing="0" cellpadding="0" border="0" height="56"><tr><td valign="middle"><?php echo $protech_grid( $protech_pack_dots, 5, 8, 11, array( $protech_blue ), $protech_blue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts. ?></td></tr></table>
						<p style="margin:6px 0 2px;font-size:16px;font-weight:bold;color:<?php echo esc_attr( $protech_ink ); ?>;"><?php esc_html_e( '1 display', 'protech-wholesale' ); ?></p>
						<p style="margin:0;font-size:12px;color:<?php echo esc_attr( $protech_muted ); ?>;"><?php echo esc_html( sprintf( /* translators: %d: packs per display. */ _n( '= %d pack, one color', '= %d packs, one color', $case_size, 'protech-wholesale' ), $case_size ) ); ?></p>
					</td>
					<td valign="top" width="5%" style="padding:20px 4px 0;font-size:13px;font-weight:bold;color:<?php echo esc_attr( $protech_blue ); ?>;"><?php echo esc_html( sprintf( /* translators: %d: displays per case. */ __( '×%d', 'protech-wholesale' ), $displays_per_case ) ); ?></td>
					<td valign="top" width="30%">
						<table role="presentation" cellspacing="0" cellpadding="0" border="0" height="56"><tr><td valign="middle"><?php echo $protech_grid( $protech_display_tiles, 4, 14, 10, $protech_swatch, '#2a4166' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts. ?></td></tr></table>
						<p style="margin:6px 0 2px;font-size:16px;font-weight:bold;color:<?php echo esc_attr( $protech_ink ); ?>;"><?php esc_html_e( '1 case', 'protech-wholesale' ); ?></p>
						<p style="margin:0;font-size:12px;color:<?php echo esc_attr( $protech_muted ); ?>;"><?php echo esc_html( sprintf( /* translators: 1: displays per case, 2: packs per case. */ __( '= %1$d displays (%2$d packs), any colors', 'protech-wholesale' ), $displays_per_case, $case_packs ) ); ?></p>
					</td>
				</tr>
			</table>
			<p style="margin:18px 0 0;padding-top:16px;border-top:1px solid <?php echo esc_attr( $protech_border ); ?>;font-size:15px;color:#222222;">
				<strong style="color:#34507e;"><?php echo esc_html( sprintf( /* translators: %d: displays per case. */ __( '%d displays = 1 case.', 'protech-wholesale' ), $displays_per_case ) ); ?></strong>
				<?php esc_html_e( 'Mix and match your displays however you\'d like.', 'protech-wholesale' ); ?>
			</p>
		</td>
	</tr>
</table>

<h2 style="margin:24px 0 10px;font-size:18px;color:<?php echo esc_attr( $protech_blue ); ?>;"><?php esc_html_e( 'The more you order, the more you save', 'protech-wholesale' ); ?></h2>
<p style="margin:0 0 6px;"><strong><?php echo esc_html( sprintf( /* translators: %d: number of displays. */ _n( 'Under %d display', 'Under %d displays', $volume_threshold, 'protech-wholesale' ), $volume_threshold ) ); ?></strong>: <?php esc_html_e( 'your wholesale price, plus shipping.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 6px;"><strong><?php echo esc_html( sprintf( /* translators: 1: number of displays, 2: the same in cases. */ __( '%1$d displays or more (%2$s cases)', 'protech-wholesale' ), $volume_threshold, $threshold_cases ) ); ?></strong>: <?php esc_html_e( 'free shipping and Standard pricing.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 12px;"><strong><?php echo esc_html( sprintf( /* translators: 1: number of cases, 2: the same in displays. */ __( '%1$d cases or more (%2$d displays)', 'protech-wholesale' ), $bulk_cases, $bulk_cases * $displays_per_case ) ); ?></strong>: <?php esc_html_e( 'Volume pricing, our best price.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 24px;"><?php esc_html_e( 'Your whole cart counts together, every product and color. A bar at the bottom of the page shows how close you are to the next level.', 'protech-wholesale' ); ?></p>

<p style="margin:0 0 8px;"><?php esc_html_e( 'To restock fast, open any past order in My Account and choose Reorder.', 'protech-wholesale' ); ?></p>
<p style="margin:0;"><?php esc_html_e( 'Questions? Reply to this email and we will help.', 'protech-wholesale' ); ?></p>
