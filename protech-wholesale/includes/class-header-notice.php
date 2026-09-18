<?php
/**
 * [protech_header_notice] — Salient's own "Text To Display In Header"
 * customizer field (Theme Options → Header Layout & Style, or Customizer
 * → Header → General) already runs its content through do_shortcode(),
 * so this needs no theme edit and no JS DOM swap: the owner wraps
 * whatever text is already in that field with this shortcode, and it
 * shows a different message to a logged-in wholesale customer.
 *
 * In that field:
 *   [protech_header_notice]FREE SHIPPING WITH $30+ ORDERS[/protech_header_notice]
 *
 * A guest or retail customer sees the enclosed text exactly as typed —
 * this shortcode does nothing for them beyond passing it through (so a
 * nested shortcode in there, e.g. a Salient icon shortcode, still runs
 * the same as it would have without this wrapper). An approved wholesale
 * customer sees the wholesale line instead: by default, built from
 * Settings::get_volume_threshold_displays() so it can never drift from
 * the real free-shipping threshold if that setting ever changes; pass a
 * `wholesale` attribute to use fixed text instead.
 *
 * If this plugin is ever deactivated, the field then shows the shortcode
 * tags literally, like any other shortcode-dependent header text would —
 * no different from Salient's own shortcodes in that same field.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HeaderNotice
 */
class HeaderNotice {

	public function register_hooks(): void {
		add_shortcode( 'protech_header_notice', array( $this, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 * @param string|null                  $content The retail message, exactly as typed in the customizer field.
	 */
	public function render( $atts, ?string $content = '' ): string {
		$atts = shortcode_atts( array( 'wholesale' => '' ), (array) $atts, 'protech_header_notice' );

		if ( ! Roles::is_wholesale_customer() ) {
			// Untouched: whatever the field would have shown without this
			// shortcode wrapping it. do_shortcode() on the enclosed text
			// mirrors what WordPress does for shortcode content in general
			// (nested shortcodes are not auto-processed otherwise).
			return do_shortcode( (string) $content );
		}

		$message = '' !== $atts['wholesale']
			? $atts['wholesale']
			: sprintf(
				/* translators: %d: number of displays. */
				__( 'FREE SHIPPING ON WHOLESALE ORDERS OF %d+ DISPLAYS', 'protech-wholesale' ),
				Settings::get_volume_threshold_displays()
			);

		/**
		 * The header-text message shown to a logged-in wholesale customer,
		 * in place of the retail [protech_header_notice] content.
		 *
		 * @param string $message
		 */
		return (string) apply_filters( 'protech_wholesale_header_notice', $message );
	}
}
