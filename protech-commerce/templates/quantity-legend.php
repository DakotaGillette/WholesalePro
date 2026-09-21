<?php
/**
 * "How wholesale quantities work": pack → display → case, drawn from the
 * product's own case composition, above the Display/Case control on a
 * single product page (CaseRules::render_unit_selector()). Meant to be
 * read at a glance by a buyer who has never heard the words display or
 * case: three tiles, then the one rule that matters.
 *
 * Override by copying to yourtheme/woocommerce/quantity-legend.php.
 *
 * @package ProtechWholesale
 *
 * @var int    $case_size          Packs per display.
 * @var int    $displays_per_case
 * @var int    $case_packs         Packs per case.
 * @var int    $volume_threshold   Displays that unlock free shipping.
 * @var string $threshold_cases    The same quantity in cases, formatted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The art is illustrative: a handful of dots and swatches, never a
// literal count for an unusual composition.
$protech_pack_dots     = min( 12, $case_size );
$protech_display_tiles = min( 12, $displays_per_case );
?>
<aside class="protech-legend" aria-label="<?php esc_attr_e( 'How wholesale quantities work', 'protech-wholesale' ); ?>">
	<p class="protech-legend-title"><?php esc_html_e( 'How wholesale quantities work', 'protech-wholesale' ); ?></p>

	<ol class="protech-legend-units">
		<li class="protech-legend-unit">
			<span class="protech-legend-art" aria-hidden="true"><span class="protech-legend-pack"></span></span>
			<span class="protech-legend-name"><?php esc_html_e( '1 pack', 'protech-wholesale' ); ?></span>
			<span class="protech-legend-meta"><?php esc_html_e( 'What your customer buys', 'protech-wholesale' ); ?></span>
		</li>
		<li class="protech-legend-times" aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %d: packs per display. */ __( '×%d', 'protech-wholesale' ), $case_size ) ); ?></li>
		<li class="protech-legend-unit">
			<span class="protech-legend-art" aria-hidden="true">
				<span class="protech-legend-display">
					<?php for ( $protech_i = 0; $protech_i < $protech_pack_dots; $protech_i++ ) : ?>
						<span class="protech-legend-pack protech-legend-pack--small"></span>
					<?php endfor; ?>
				</span>
			</span>
			<span class="protech-legend-name"><?php esc_html_e( '1 display', 'protech-wholesale' ); ?></span>
			<span class="protech-legend-meta"><?php echo esc_html( sprintf( /* translators: %d: packs per display. */ _n( '= %d pack, one color', '= %d packs, one color', $case_size, 'protech-wholesale' ), $case_size ) ); ?></span>
		</li>
		<li class="protech-legend-times" aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %d: displays per case. */ __( '×%d', 'protech-wholesale' ), $displays_per_case ) ); ?></li>
		<li class="protech-legend-unit">
			<span class="protech-legend-art" aria-hidden="true">
				<span class="protech-legend-case">
					<?php for ( $protech_i = 0; $protech_i < $protech_display_tiles; $protech_i++ ) : ?>
						<span class="protech-legend-swatch"></span>
					<?php endfor; ?>
				</span>
			</span>
			<span class="protech-legend-name"><?php esc_html_e( '1 case', 'protech-wholesale' ); ?></span>
			<span class="protech-legend-meta"><?php echo esc_html( sprintf( /* translators: 1: displays per case, 2: packs per case. */ __( '= %1$d displays (%2$d packs), any colors', 'protech-wholesale' ), $displays_per_case, $case_packs ) ); ?></span>
		</li>
	</ol>

	<p class="protech-legend-rule">
		<strong><?php echo esc_html( sprintf( /* translators: %d: displays per case. */ __( '%d displays = 1 case.', 'protech-wholesale' ), $displays_per_case ) ); ?></strong>
		<?php esc_html_e( 'Mix and match your displays however you\'d like.', 'protech-wholesale' ); ?>
	</p>
	<p class="protech-legend-shipping"><?php echo esc_html( sprintf( /* translators: 1: number of displays, 2: the same quantity in cases. */ __( 'Free shipping from %1$d displays (%2$s cases), any combination of colors.', 'protech-wholesale' ), $volume_threshold, $threshold_cases ) ); ?></p>
</aside>
