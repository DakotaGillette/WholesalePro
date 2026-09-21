<?php
/**
 * The three quantity levels, one line each, and the note under them. Shared
 * by the welcome email and the composer's "Quantity levels" block through
 * EmailBlocks::pricing_ladder(). Every number comes from the store settings.
 * Email markup: inline styles only.
 *
 * @package ProtechWholesale
 *
 * @var int    $displays_per_case
 * @var int    $volume_threshold  Displays that unlock free shipping and Standard pricing.
 * @var string $threshold_cases   The same quantity in cases.
 * @var int    $bulk_cases        Cases that unlock Volume pricing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p style="margin:0 0 6px;"><strong><?php echo esc_html( sprintf( /* translators: %d: number of displays. */ _n( 'Under %d display', 'Under %d displays', $volume_threshold, 'protech-wholesale' ), $volume_threshold ) ); ?></strong>: <?php esc_html_e( 'your wholesale price, plus shipping.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 6px;"><strong><?php echo esc_html( sprintf( /* translators: 1: number of displays, 2: the same in cases. */ __( '%1$d displays or more (%2$s cases)', 'protech-wholesale' ), $volume_threshold, $threshold_cases ) ); ?></strong>: <?php esc_html_e( 'free shipping and Standard pricing.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 12px;"><strong><?php echo esc_html( sprintf( /* translators: 1: number of cases, 2: the same in displays. */ __( '%1$d cases or more (%2$d displays)', 'protech-wholesale' ), $bulk_cases, $bulk_cases * $displays_per_case ) ); ?></strong>: <?php esc_html_e( 'Volume pricing, our best price.', 'protech-wholesale' ); ?></p>
<p style="margin:0 0 24px;"><?php esc_html_e( 'Your whole cart counts together, every product and color. A bar at the bottom of the page shows how close you are to the next level.', 'protech-wholesale' ); ?></p>
