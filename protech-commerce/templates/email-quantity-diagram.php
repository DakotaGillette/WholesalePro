<?php
/**
 * The pack, display and case picture: three tiles with the multiplication
 * between them, and the "8 displays = 1 case" rule underneath. Shared by the
 * welcome email (templates/welcome-email.php) and the composer's "Quantities
 * explained" block, through EmailBlocks::quantity_diagram(), so an email can
 * never draw it two different ways. Email markup: tables and inline styles.
 *
 * @package ProtechWholesale
 *
 * @var int    $case_size         Packs per display.
 * @var int    $displays_per_case
 * @var int    $case_packs        Packs per case.
 * @var string $blue              Brand color, a hex value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$protech_blue   = isset( $blue ) && '' !== $blue ? $blue : '#42649d';
$protech_border = '#c9d6ea';
$protech_ink    = '#111111';
$protech_muted  = '#6b7280';
$protech_swatch = array( '#42649d', '#d64545', '#e8a33d', '#3f9e63', '#111111', '#8e5bd1', '#e77ab0', '#d9d9d9' );

$protech_pack_dots     = min( 10, $case_size );
$protech_display_tiles = min( 8, $displays_per_case );

$protech_grid = static function ( int $count, int $columns, int $width, int $height, array $colors, string $outline ): string {
	return \ProtechWholesale\EmailBlocks::swatch_grid( $count, $columns, $width, $height, $colors, $outline );
};
?>
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
