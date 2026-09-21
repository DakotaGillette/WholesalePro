<?php
/**
 * Turns an email template into a finished email: one complete HTML document
 * (header, blocks, footer) and a real plain-text alternative.
 *
 * The template owns the whole email. Unlike a plain string body, nothing is
 * wrapped in WooCommerce's transactional header and footer, and nothing is
 * run through WC_Email::style_inline(), which would inline WooCommerce's own
 * email stylesheet onto our elements. The finished HTML is never passed
 * through wp_kses_post() either, which deletes inline CSS outside its
 * whitelist; see EmailBlocks for why, and MergeTags::fill() for how tags are
 * substituted without it.
 *
 * The unsubscribe footer is not decided here. The caller passes it in
 * (MessageTransport::footer_html_for()), so marketing cannot be rendered
 * without it by forgetting to ask.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailRenderer
 */
class EmailRenderer {

	/**
	 * The look of a template with the site-wide branding filled in wherever the
	 * template left a value empty.
	 *
	 * @param array<string, mixed> $template
	 * @return array{width: int, page_bg: string, canvas: string, brand: string, text: string, muted: string, font: string}
	 */
	public static function style( array $template ): array {
		$s     = is_array( $template['style'] ?? null ) ? $template['style'] : array();
		$fonts = EmailBlocks::FONTS;

		return array(
			'width'   => (int) ( $s['width'] ?? 0 ) > 0 ? (int) $s['width'] : MessagingSettings::email_width(),
			'page_bg' => '' !== ( $s['page_bg'] ?? '' ) ? (string) $s['page_bg'] : '#f4f5f7',
			'canvas'  => '' !== ( $s['canvas'] ?? '' ) ? (string) $s['canvas'] : '#ffffff',
			'brand'   => '' !== ( $s['brand'] ?? '' ) ? (string) $s['brand'] : MessagingSettings::email_brand_color(),
			'text'    => '' !== ( $s['text'] ?? '' ) ? (string) $s['text'] : '#1f2937',
			'muted'   => '' !== ( $s['muted'] ?? '' ) ? (string) $s['muted'] : '#6b7280',
			'font'    => $fonts[ (string) ( $s['font'] ?? 'helvetica' ) ] ?? $fonts['helvetica'],
		);
	}

	/**
	 * The merge-tag context for a recipient, plus the flags the blocks read:
	 * `_user_id`, `_wholesale` and `_preview`.
	 *
	 * @return array<string, mixed>
	 */
	public static function context( int $user_id, bool $preview = false ): array {
		$context = MergeTags::context_for_customer( $user_id );

		$context['_user_id']   = $user_id;
		$context['_wholesale'] = $user_id > 0 && Roles::is_wholesale_customer( $user_id );
		$context['_preview']   = $preview;

		return $context;
	}

	/**
	 * The subject line, tags filled in.
	 *
	 * @param array<string, mixed> $template
	 * @param array<string, mixed> $context
	 */
	public static function subject( array $template, array $context ): string {
		return MergeTags::render( (string) ( $template['subject'] ?? '' ), self::values( $context ), 'subject' );
	}

	/**
	 * @param array<string, mixed> $template
	 * @param array<string, mixed> $context  From context().
	 * @param array<string, mixed> $args     `footer_html`: the marketing footer, from MessageTransport::footer_html_for().
	 * @return array{html: string, text: string}
	 */
	public static function render( array $template, array $context, array $args = array() ): array {
		$style       = self::style( $template );
		$footer_html = (string) ( $args['footer_html'] ?? '' );
		$blocks      = '';
		$plain       = array();

		foreach ( (array) ( $template['blocks'] ?? array() ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$blocks .= EmailBlocks::render( $block, $context, $style );
			$text    = trim( EmailBlocks::plain( $block, $context ) );

			if ( '' !== $text ) {
				$plain[] = $text;
			}
		}

		$footer_text = MergeTags::fill( (string) ( $template['footer']['text'] ?? '' ), self::values( $context ), 'raw' );
		$footer      = self::footer_row( $template, $style, $footer_text, $footer_html );

		$html = self::document(
			self::subject( $template, $context ),
			MergeTags::fill( (string) ( $template['preheader'] ?? '' ), self::values( $context ), 'raw' ),
			$style,
			self::header_row( $template, $style ) . $blocks . $footer
		);

		$lines = array( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), '' );
		$lines = array_merge( $lines, array_map( static fn( string $p ): string => $p . "\n", $plain ) );

		if ( '' !== trim( $footer_text ) ) {
			$lines[] = $footer_text;
		}

		if ( '' !== $footer_html ) {
			$lines[] = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<br[^>]*>/i', "\n", $footer_html ) ), ENT_QUOTES, 'UTF-8' );
		}

		return array(
			'html' => $html,
			'text' => trim( implode( "\n", $lines ) ) . "\n",
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, string>
	 */
	private static function values( array $context ): array {
		return array_filter( $context, static fn( $key ): bool => is_string( $key ) && '_' !== substr( $key, 0, 1 ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * The whole file: doctype, the one media query that stacks columns on a
	 * phone, the hidden preview line, and the centered white card.
	 *
	 * @param array<string, mixed> $style
	 */
	private static function document( string $subject, string $preheader, array $style, string $rows ): string {
		$width  = (int) $style['width'];
		$canvas = esc_attr( (string) $style['canvas'] );

		$hidden = '' === trim( $preheader )
			? ''
			: '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . esc_html( $preheader ) . str_repeat( '&zwnj;&nbsp;', 40 ) . '</div>';

		return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
			. '<html xmlns="http://www.w3.org/1999/xhtml"><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge" />'
			. '<title>' . esc_html( $subject ) . '</title>'
			. '<style type="text/css">body{margin:0;padding:0;}img{border:0;}table{border-collapse:collapse;}'
			. '@media only screen and (max-width:' . ( $width + 20 ) . 'px){.pw-col{display:block !important;width:100% !important;padding:0 0 12px !important;}}</style>'
			. '</head><body style="margin:0;padding:0;background:' . esc_attr( (string) $style['page_bg'] ) . ';">'
			. $hidden
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . esc_attr( (string) $style['page_bg'] ) . ';"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="' . $width . '" cellspacing="0" cellpadding="0" border="0" style="width:' . $width . 'px;max-width:100%;background:' . $canvas . ';border-radius:10px;">'
			. $rows
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * The logo, or the site name when there is none.
	 *
	 * @param array<string, mixed> $template
	 * @param array<string, mixed> $style
	 */
	private static function header_row( array $template, array $style ): string {
		if ( empty( $template['header']['show_logo'] ) ) {
			return '';
		}

		$logo_id = (int) ( $template['header']['logo_id'] ?? 0 );
		$logo_id = $logo_id > 0 ? $logo_id : MessagingSettings::email_logo_id();
		$image   = $logo_id > 0 ? wp_get_attachment_image_src( $logo_id, 'medium' ) : false;

		if ( is_array( $image ) && '' !== (string) $image[0] ) {
			$inner = '<img src="' . esc_url( (string) $image[0] ) . '" alt="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '" width="180" style="display:block;margin:0 auto;width:180px;max-width:100%;height:auto;border:0;" />';
		} else {
			$inner = '<span style="font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:22px;font-weight:700;color:' . esc_attr( (string) $style['brand'] ) . ';">' . esc_html( (string) get_bloginfo( 'name' ) ) . '</span>';
		}

		return '<tr><td align="center" style="padding:28px 24px 12px;">' . $inner . '</td></tr>';
	}

	/**
	 * Footer text, then either the marketing footer the caller supplied (which
	 * already carries the postal address) or, when the template asks for it,
	 * the store name and address on their own.
	 *
	 * @param array<string, mixed> $template
	 * @param array<string, mixed> $style
	 */
	private static function footer_row( array $template, array $style, string $footer_text, string $footer_html ): string {
		$parts = '';
		$font  = 'font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:12px;line-height:1.5;color:' . esc_attr( (string) $style['muted'] ) . ';';

		if ( '' !== trim( $footer_text ) ) {
			$parts .= '<p style="margin:0 0 8px;' . $font . '">' . nl2br( esc_html( $footer_text ), false ) . '</p>';
		}

		if ( '' !== $footer_html ) {
			$parts .= '<div style="' . $font . '">' . $footer_html . '</div>';
		} elseif ( ! empty( $template['footer']['show_address'] ) ) {
			$address = MergeTags::store_address();
			$line    = trim( (string) get_bloginfo( 'name' ) . ( '' !== $address ? ', ' . $address : '' ) );

			if ( '' !== $line ) {
				$parts .= '<p style="margin:0;' . $font . '">' . esc_html( $line ) . '</p>';
			}
		}

		return '' === $parts ? '' : '<tr><td align="center" style="padding:16px 24px 28px;text-align:center;">' . $parts . '</td></tr>';
	}
}
