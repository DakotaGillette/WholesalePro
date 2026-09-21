<?php
/**
 * Building blocks for HTML email: the pieces of drawing that only work as
 * tables and inline styles, because email clients ignore stylesheets and
 * most CSS. They started life inside templates/welcome-email.php and live
 * here so the welcome email and the email composer's blocks share one
 * implementation instead of two that drift apart.
 *
 * Everything returned is already escaped, built from esc_attr() and casts,
 * so callers print it as is. Do not pass it through wp_kses_post(): that runs
 * every inline style through safecss_filter_attr(), which deletes anything
 * outside its whitelist (the mso- properties Outlook needs, for one), and is
 * the reason composed email HTML must never go through MergeTags::render().
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailBlocks
 */
class EmailBlocks {

	/**
	 * A grid of small filled squares, as a table: the only drawing an email
	 * client is sure to render. Rows wrap every $columns cells; colors cycle.
	 *
	 * @param string[] $colors Hex colors, used in order and repeated as needed.
	 */
	public static function swatch_grid( int $count, int $columns, int $width, int $height, array $colors, string $outline ): string {
		$columns = max( 1, $columns );
		$colors  = array() === $colors ? array( '#42649d' ) : array_values( $colors );

		$html = '<table role="presentation" cellspacing="3" cellpadding="0" border="0" style="background:#ffffff;border:1px solid ' . esc_attr( $outline ) . ';border-radius:3px;"><tr>';

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $i > 0 && 0 === $i % $columns ) {
				$html .= '</tr><tr>';
			}

			$html .= '<td width="' . (int) $width . '" height="' . (int) $height . '" style="width:' . (int) $width . 'px;height:' . (int) $height . 'px;background:' . esc_attr( $colors[ $i % count( $colors ) ] ) . ';border-radius:2px;font-size:0;line-height:0;">&nbsp;</td>';
		}

		return $html . '</tr></table>';
	}

	/** A small numbered circle, for "1, 2, 3" steps. */
	public static function step_number( string $number, string $color ): string {
		return '<span style="display:inline-block;width:22px;height:22px;line-height:22px;margin-right:8px;border-radius:11px;background:' . esc_attr( $color ) . ';color:#ffffff;font-size:12px;font-weight:bold;text-align:center;">' . esc_html( $number ) . '</span>';
	}
}
