<?php
/**
 * The email composer's blocks: what a template is made of, and how each one
 * turns into HTML that survives an email client.
 *
 * Every block is `{ id, type, attrs }` (plus `children` on `columns`). This
 * class owns, per type: the defaults, sanitizing what an admin submitted,
 * rendering to table rows with inline styles, and a plain-text rendering. The
 * renderer (EmailRenderer) only stacks the rows into a document.
 *
 * Two rules everything here follows:
 *
 *  - Email clients ignore stylesheets and most CSS, so every block is nested
 *    presentation tables and inline styles, the technique the welcome email
 *    proved. Nothing here needs a <style> block except the one media query
 *    that stacks columns on a phone (in EmailRenderer::document()).
 *  - The finished HTML is never passed through wp_kses_post(), which runs each
 *    inline style through safecss_filter_attr() and silently deletes anything
 *    outside its whitelist (the mso- properties Outlook needs, for one). Merge
 *    tags are substituted per field with MergeTags::fill(), escaping each
 *    value, and text an admin typed is sanitized once, on save.
 *
 * The drawing helpers at the bottom (swatch grid, numbered step, the quantity
 * diagram and the pricing lines) are shared with the welcome email.
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

	/** Columns nest one level deep, never more: the renderer cannot recurse and the editor stays tractable. */
	public const MAX_DEPTH  = 1;
	public const MAX_BLOCKS = 60;

	/** Horizontal padding of a top-level block, in px. */
	private const SIDE = 24;

	/** @var array<string, string> Font choice => CSS stack. */
	public const FONTS = array(
		'helvetica' => 'Helvetica,Arial,sans-serif',
		'georgia'   => "Georgia,'Times New Roman',serif",
		'system'    => "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
	);

	// -----------------------------------------------------------------
	// The registry.
	// -----------------------------------------------------------------

	/**
	 * Every block type: its label and default attributes. The order is the
	 * order of the editor's "Add a block" list.
	 *
	 * @return array<string, array{label: string, help: string, defaults: array<string, mixed>}>
	 */
	public static function types(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$common = array(
			'pt'    => 8,
			'pb'    => 8,
			'bg'    => '',
			'align' => 'left',
		);

		$cache = array(
			'heading'              => array(
				'label'    => __( 'Heading', 'protech-wholesale' ),
				'help'     => __( 'A large line of text.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'text' => __( 'Your heading', 'protech-wholesale' ), 'level' => 2, 'size' => 26, 'color' => '' ) ),
			),
			'text'                 => array(
				'label'    => __( 'Text', 'protech-wholesale' ),
				'help'     => __( 'Paragraphs. A blank line starts a new one.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'html' => __( 'Write your message here.', 'protech-wholesale' ), 'size' => 15, 'color' => '', 'line_height' => 1.6 ) ),
			),
			'button'               => array(
				'label'    => __( 'Button', 'protech-wholesale' ),
				'help'     => __( 'A call to action that links somewhere.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'label' => __( 'Shop now', 'protech-wholesale' ), 'url' => '{shop_url}', 'bg_color' => '', 'color' => '#ffffff', 'radius' => 8, 'full_width' => false, 'size' => 15, 'align' => 'center' ) ),
			),
			'image'                => array(
				'label'    => __( 'Image', 'protech-wholesale' ),
				'help'     => __( 'A picture from your media library.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'id' => 0, 'url' => '', 'alt' => '', 'width' => 552, 'link_url' => '' ) ),
			),
			'product_grid'         => array(
				'label'    => __( 'Products', 'protech-wholesale' ),
				'help'     => __( 'Product cards with a picture, name and price.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'mode' => 'picked', 'product_ids' => array(), 'columns' => 3, 'limit' => 6, 'show_price' => true, 'show_button' => true, 'button_label' => __( 'View', 'protech-wholesale' ) ) ),
			),
			'explainer_quantities' => array(
				'label'    => __( 'Quantities explained', 'protech-wholesale' ),
				'help'     => __( 'The pack, display and case picture. Wholesale customers only.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'show_title' => true, 'title' => __( 'How wholesale quantities work', 'protech-wholesale' ) ) ),
			),
			'explainer_ladder'     => array(
				'label'    => __( 'Quantity levels', 'protech-wholesale' ),
				'help'     => __( 'What each order size unlocks. Wholesale customers only.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'show_title' => true, 'title' => __( 'The more you order, the more you save', 'protech-wholesale' ) ) ),
			),
			'columns'              => array(
				'label'    => __( 'Columns', 'protech-wholesale' ),
				'help'     => __( 'Two or three blocks side by side.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'count' => 2, 'gap' => 16, 'valign' => 'top', 'children' => array( array(), array() ) ) ),
			),
			'divider'              => array(
				'label'    => __( 'Divider', 'protech-wholesale' ),
				'help'     => __( 'A thin line.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'color' => '#e5e7eb', 'thickness' => 1, 'width_pct' => 100 ) ),
			),
			'spacer'               => array(
				'label'    => __( 'Space', 'protech-wholesale' ),
				'help'     => __( 'Empty room between blocks.', 'protech-wholesale' ),
				'defaults' => array_merge( $common, array( 'height' => 24 ) ),
			),
		);

		return $cache;
	}

	public static function is_type( string $type ): bool {
		return array_key_exists( $type, self::types() );
	}

	/** A fresh block of $type with its defaults and a new id. */
	public static function make( string $type ): ?array {
		$types = self::types();

		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}

		$block = array(
			'id'    => self::new_id(),
			'type'  => $type,
			'attrs' => $types[ $type ]['defaults'],
		);

		if ( 'columns' === $type ) {
			unset( $block['attrs']['children'] );
			$block['children'] = array( array(), array() );
		}

		return $block;
	}

	public static function new_id(): string {
		return 'b_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	// -----------------------------------------------------------------
	// Sanitizing what an admin submitted.
	// -----------------------------------------------------------------

	/** The only inline tags a Text block keeps. No style attributes, ever: nothing here is filtered again. */
	public static function inline_allowed(): array {
		return array(
			'a'      => array( 'href' => true, 'title' => true ),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * @param array<int, mixed> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_all( array $blocks, int $depth = 0 ): array {
		$clean = array();

		foreach ( array_values( $blocks ) as $block ) {
			if ( count( $clean ) >= self::MAX_BLOCKS ) {
				break;
			}

			if ( ! is_array( $block ) ) {
				continue;
			}

			$sane = self::sanitize( $block, $depth );

			if ( null !== $sane ) {
				$clean[] = $sane;
			}
		}

		return $clean;
	}

	/**
	 * One block, or null when its type is unknown or it may not sit here
	 * (columns inside columns).
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>|null
	 */
	public static function sanitize( array $block, int $depth = 0 ): ?array {
		$type  = sanitize_key( (string) ( $block['type'] ?? '' ) );
		$types = self::types();

		if ( ! isset( $types[ $type ] ) || ( 'columns' === $type && $depth >= self::MAX_DEPTH ) ) {
			return null;
		}

		$in       = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$defaults = $types[ $type ]['defaults'];
		$id       = (string) ( $block['id'] ?? '' );

		$out = array(
			'pt'    => self::num( $in['pt'] ?? null, 0, 80, (int) $defaults['pt'] ),
			'pb'    => self::num( $in['pb'] ?? null, 0, 80, (int) $defaults['pb'] ),
			'bg'    => self::hex( $in['bg'] ?? '', '' ),
			'align' => self::pick( $in['align'] ?? '', array( 'left', 'center', 'right' ), (string) $defaults['align'] ),
		);

		$clean_children = array();

		switch ( $type ) {
			case 'heading':
				$out['text']  = self::text( $in['text'] ?? $defaults['text'], 300 );
				$out['level'] = self::num( $in['level'] ?? null, 1, 3, 2 );
				$out['size']  = self::num( $in['size'] ?? null, 14, 60, 26 );
				$out['color'] = self::hex( $in['color'] ?? '', '' );
				break;

			case 'text':
				$out['html']        = wp_kses( (string) ( $in['html'] ?? $defaults['html'] ), self::inline_allowed() );
				$out['size']        = self::num( $in['size'] ?? null, 11, 28, 15 );
				$out['color']       = self::hex( $in['color'] ?? '', '' );
				$out['line_height'] = min( 2.4, max( 1.0, (float) ( $in['line_height'] ?? 1.6 ) ) );
				break;

			case 'button':
				$out['label']      = self::text( $in['label'] ?? $defaults['label'], 80 );
				$out['url']        = self::url( $in['url'] ?? '' );
				$out['bg_color']   = self::hex( $in['bg_color'] ?? '', '' );
				$out['color']      = self::hex( $in['color'] ?? '', '#ffffff' );
				$out['radius']     = self::num( $in['radius'] ?? null, 0, 40, 8 );
				$out['full_width'] = self::flag( $in['full_width'] ?? false );
				$out['size']       = self::num( $in['size'] ?? null, 12, 24, 15 );
				break;

			case 'image':
				$out['id']       = max( 0, (int) ( $in['id'] ?? 0 ) );
				$out['url']      = esc_url_raw( (string) ( $in['url'] ?? '' ) );
				$out['alt']      = self::text( $in['alt'] ?? '', 200 );
				$out['width']    = self::num( $in['width'] ?? null, 40, 700, 552 );
				$out['link_url'] = self::url( $in['link_url'] ?? '' );
				break;

			case 'product_grid':
				$out['mode']         = self::pick( $in['mode'] ?? '', array( 'picked', 'newest' ), 'picked' );
				$out['product_ids']  = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['product_ids'] ?? array() ) ) ) ) ), 0, 12 );
				$out['columns']      = self::num( $in['columns'] ?? null, 1, 3, 3 );
				$out['limit']        = self::num( $in['limit'] ?? null, 1, 12, 6 );
				$out['show_price']   = self::flag( $in['show_price'] ?? true );
				$out['show_button']  = self::flag( $in['show_button'] ?? true );
				$out['button_label'] = self::text( $in['button_label'] ?? $defaults['button_label'], 40 );
				break;

			case 'explainer_quantities':
			case 'explainer_ladder':
				$out['show_title'] = self::flag( $in['show_title'] ?? true );
				$out['title']      = self::text( $in['title'] ?? $defaults['title'], 120 );
				break;

			case 'columns':
				$count           = self::num( $in['count'] ?? null, 2, 3, 2 );
				$out['count']    = $count;
				$out['gap']      = self::num( $in['gap'] ?? null, 0, 40, 16 );
				$out['valign']   = self::pick( $in['valign'] ?? '', array( 'top', 'middle', 'bottom' ), 'top' );
				$children        = is_array( $block['children'] ?? null ) ? array_values( $block['children'] ) : array();

				for ( $i = 0; $i < $count; $i++ ) {
					$clean_children[] = self::sanitize_all( is_array( $children[ $i ] ?? null ) ? $children[ $i ] : array(), $depth + 1 );
				}
				break;

			case 'divider':
				$out['color']     = self::hex( $in['color'] ?? '', '#e5e7eb' );
				$out['thickness'] = self::num( $in['thickness'] ?? null, 1, 8, 1 );
				$out['width_pct'] = self::num( $in['width_pct'] ?? null, 10, 100, 100 );
				break;

			case 'spacer':
				$out['height'] = self::num( $in['height'] ?? null, 4, 120, 24 );
				break;
		}

		$sane = array(
			'id'    => preg_match( '/^b_[a-z0-9]{4,12}$/', $id ) ? $id : self::new_id(),
			'type'  => $type,
			'attrs' => $out,
		);

		if ( 'columns' === $type ) {
			$sane['children'] = $clean_children;
		}

		return $sane;
	}

	// -- small sanitizers -------------------------------------------------------

	/** A #rgb or #rrggbb color, else $default. */
	public static function hex( $value, string $default ): string {
		$value = strtolower( trim( (string) $value ) );

		return preg_match( '/^#(?:[0-9a-f]{3}){1,2}$/', $value ) ? $value : $default;
	}

	public static function num( $value, int $min, int $max, int $default ): int {
		return is_numeric( $value ) ? (int) max( $min, min( $max, (int) $value ) ) : $default;
	}

	/** @param string[] $allowed */
	public static function pick( $value, array $allowed, string $default ): string {
		return in_array( (string) $value, $allowed, true ) ? (string) $value : $default;
	}

	public static function flag( $value ): bool {
		return in_array( $value, array( true, 1, '1', 'yes', 'on', 'true' ), true );
	}

	/** Plain text, one line, HTML gone. Merge tags in braces pass through untouched. */
	public static function text( $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	/** A link: either a lone merge tag such as {shop_url} (esc_url_raw would mangle it) or a real URL. */
	public static function url( $value ): string {
		$value = trim( (string) $value );

		if ( preg_match( '/^\{[a-z_]+\}$/', $value ) ) {
			return $value;
		}

		return esc_url_raw( $value );
	}

	// -----------------------------------------------------------------
	// Merge tags in a block, for validation.
	// -----------------------------------------------------------------

	/** Every string in a block that may carry {tags}, joined, so unknown tags can be found before saving. */
	public static function tag_source( array $block ): string {
		$a   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$out = array();

		foreach ( array( 'text', 'html', 'label', 'url', 'alt', 'link_url', 'button_label', 'title' ) as $key ) {
			if ( isset( $a[ $key ] ) && is_string( $a[ $key ] ) ) {
				$out[] = $a[ $key ];
			}
		}

		foreach ( (array) ( $block['children'] ?? array() ) as $column ) {
			foreach ( (array) $column as $child ) {
				if ( is_array( $child ) ) {
					$out[] = self::tag_source( $child );
				}
			}
		}

		return implode( ' ', $out );
	}

	// -----------------------------------------------------------------
	// Rendering.
	// -----------------------------------------------------------------

	/**
	 * A block as table rows, or '' when it has nothing to show this recipient.
	 *
	 * $ctx is MergeTags::context_for_customer() plus `_user_id`, `_wholesale`
	 * (whether the recipient is a wholesale customer) and `_preview`.
	 * $style is EmailRenderer::style(). $inner is true inside a column, where
	 * the side padding is dropped because the column supplies its own.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	public static function render( array $block, array $ctx, array $style, bool $inner = false ): string {
		$type  = (string) ( $block['type'] ?? '' );
		$types = self::types();

		if ( ! isset( $types[ $type ] ) ) {
			return '';
		}

		// Whatever the block left out falls back to its default, so a block written by hand still renders.
		$a = array_merge( $types[ $type ]['defaults'], is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );

		switch ( $type ) {
			case 'heading':
				$text = self::tags( esc_html( (string) ( $a['text'] ?? '' ) ), $ctx );

				if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
					return '';
				}

				$tag   = 'h' . self::num( $a['level'] ?? null, 1, 3, 2 );
				$color = '' !== ( $a['color'] ?? '' ) ? (string) $a['color'] : (string) $style['text'];

				return self::row(
					'<' . $tag . ' style="margin:0;padding:0;font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:' . (int) $a['size'] . 'px;line-height:1.3;font-weight:700;color:' . esc_attr( $color ) . ';text-align:' . esc_attr( (string) $a['align'] ) . ';">' . $text . '</' . $tag . '>',
					$a,
					$inner
				);

			case 'text':
				return self::render_text( $a, $ctx, $style, $inner );

			case 'button':
				return self::render_button( $a, $ctx, $style, $inner );

			case 'image':
				return self::render_image( $a, $ctx, $style, $inner );

			case 'product_grid':
				return self::render_product_grid( $a, $ctx, $style, $inner );

			case 'explainer_quantities':
				return self::render_explainer( true, $a, $ctx, $style, $inner );

			case 'explainer_ladder':
				return self::render_explainer( false, $a, $ctx, $style, $inner );

			case 'columns':
				return self::render_columns( $block, $ctx, $style, $inner );

			case 'divider':
				$width = (int) $a['width_pct'];

				return self::row(
					'<table role="presentation" width="' . $width . '%" align="' . esc_attr( (string) $a['align'] ) . '" cellspacing="0" cellpadding="0" border="0"><tr><td height="' . (int) $a['thickness'] . '" style="height:' . (int) $a['thickness'] . 'px;border-top:' . (int) $a['thickness'] . 'px solid ' . esc_attr( (string) $a['color'] ) . ';font-size:0;line-height:0;">&nbsp;</td></tr></table>',
					$a,
					$inner
				);

			case 'spacer':
				$h = (int) $a['height'];

				return '<tr><td height="' . $h . '" style="height:' . $h . 'px;font-size:0;line-height:0;">&nbsp;</td></tr>';
		}

		return '';
	}

	/**
	 * Wraps a block's content in a row: padding, background and alignment
	 * shared by every type.
	 *
	 * @param array<string, mixed> $a
	 */
	private static function row( string $content, array $a, bool $inner ): string {
		$side = $inner ? 0 : self::SIDE;
		$bg   = '' !== ( $a['bg'] ?? '' ) ? 'background:' . esc_attr( (string) $a['bg'] ) . ';' : '';

		return '<tr><td align="' . esc_attr( (string) ( $a['align'] ?? 'left' ) ) . '" style="padding:' . (int) ( $a['pt'] ?? 0 ) . 'px ' . $side . 'px ' . (int) ( $a['pb'] ?? 0 ) . 'px;' . $bg . '">' . $content . '</td></tr>';
	}

	/**
	 * Merge tags into text that has already been escaped for HTML: each value
	 * is escaped as it goes in, the surrounding markup is left exactly as is.
	 *
	 * @param array<string, mixed> $ctx
	 */
	private static function tags( string $escaped, array $ctx ): string {
		return MergeTags::fill( $escaped, self::tag_values( $ctx ), 'html' );
	}

	/**
	 * The tag => value part of $ctx (the underscore-prefixed keys are flags).
	 *
	 * @param array<string, mixed> $ctx
	 * @return array<string, string>
	 */
	private static function tag_values( array $ctx ): array {
		return array_filter( $ctx, static fn( $key ): bool => is_string( $key ) && '_' !== substr( $key, 0, 1 ), ARRAY_FILTER_USE_KEY );
	}

	/** A link target with merge tags filled in raw, then made safe. */
	private static function link( string $url, array $ctx ): string {
		return esc_url( MergeTags::fill( $url, self::tag_values( $ctx ), 'raw' ) );
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	private static function render_text( array $a, array $ctx, array $style, bool $inner ): string {
		$html = trim( (string) ( $a['html'] ?? '' ) );

		if ( '' === $html ) {
			return '';
		}

		$color = '' !== ( $a['color'] ?? '' ) ? (string) $a['color'] : (string) $style['text'];
		$base  = 'font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:' . (int) $a['size'] . 'px;line-height:' . (float) $a['line_height'] . ';color:' . esc_attr( $color ) . ';text-align:' . esc_attr( (string) $a['align'] ) . ';';

		// Links carry the brand color; the allowlist has no style attribute, so nothing is duplicated.
		$html = str_replace( '<a ', '<a style="color:' . esc_attr( (string) $style['brand'] ) . ';" ', $html );
		$html = self::tags( $html, $ctx );

		$out = '';

		foreach ( preg_split( '/\R{2,}/', $html ) ?: array() as $chunk ) {
			$chunk = trim( $chunk );

			if ( '' === $chunk ) {
				continue;
			}

			if ( preg_match( '/^<(ul|ol)\b/i', $chunk ) ) {
				$out .= preg_replace( '/^<(ul|ol)\b/i', '<$1 style="margin:0 0 14px;padding-left:22px;' . $base . '"', $chunk );
				continue;
			}

			$out .= '<p style="margin:0 0 14px;' . $base . '">' . nl2br( $chunk, false ) . '</p>';
		}

		return '' === $out ? '' : self::row( $out, $a, $inner );
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	private static function render_button( array $a, array $ctx, array $style, bool $inner ): string {
		$label = self::tags( esc_html( (string) ( $a['label'] ?? '' ) ), $ctx );
		$url   = self::link( (string) ( $a['url'] ?? '' ), $ctx );

		if ( '' === trim( wp_strip_all_tags( $label ) ) ) {
			return '';
		}

		$bg     = '' !== ( $a['bg_color'] ?? '' ) ? (string) $a['bg_color'] : (string) $style['brand'];
		$radius = (int) $a['radius'];
		$full   = ! empty( $a['full_width'] );

		$link = '<a href="' . ( '' !== $url ? $url : '#' ) . '" target="_blank" style="display:' . ( $full ? 'block' : 'inline-block' ) . ';padding:14px 28px;font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:' . (int) $a['size'] . 'px;font-weight:700;line-height:1.2;color:' . esc_attr( (string) $a['color'] ) . ';text-decoration:none;text-align:center;border-radius:' . $radius . 'px;">' . $label . '</a>';

		$table = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="' . esc_attr( (string) $a['align'] ) . '"' . ( $full ? ' width="100%"' : '' ) . '><tr><td align="center" bgcolor="' . esc_attr( $bg ) . '" style="background:' . esc_attr( $bg ) . ';border-radius:' . $radius . 'px;">' . $link . '</td></tr></table>';

		return self::row( $table, $a, $inner );
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	private static function render_image( array $a, array $ctx, array $style, bool $inner ): string {
		$src = '';

		if ( ! empty( $a['id'] ) ) {
			$image = wp_get_attachment_image_src( (int) $a['id'], 'large' );
			$src   = is_array( $image ) ? (string) $image[0] : '';
		}

		if ( '' === $src ) {
			$src = (string) ( $a['url'] ?? '' );
		}

		if ( '' === $src ) {
			return '';
		}

		$max   = max( 40, (int) $style['width'] - ( $inner ? 0 : 2 * self::SIDE ) );
		$width = min( (int) $a['width'], $max );
		$alt   = MergeTags::fill( (string) ( $a['alt'] ?? '' ), self::tag_values( $ctx ), 'raw' );
		$img   = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" width="' . $width . '" style="display:block;width:100%;max-width:' . $width . 'px;height:auto;border:0;outline:none;text-decoration:none;' . ( 'center' === $a['align'] ? 'margin:0 auto;' : '' ) . '" />';

		$link = '' !== ( $a['link_url'] ?? '' ) ? self::link( (string) $a['link_url'], $ctx ) : '';

		if ( '' !== $link ) {
			$img = '<a href="' . $link . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
		}

		return self::row( $img, $a, $inner );
	}

	// -- product grid -----------------------------------------------------------

	/**
	 * The products a grid shows this recipient: the picked ones, or the newest,
	 * minus anything they may not see (wholesale-only for a retail reader,
	 * unpriced for a wholesale one when unpriced products are hidden).
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $ctx
	 * @return \WC_Product[]
	 */
	public static function grid_products( array $a, array $ctx ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$limit = self::num( $a['limit'] ?? null, 1, 12, 6 );

		if ( 'newest' === ( $a['mode'] ?? 'picked' ) ) {
			$products = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => $limit * 3, // Some will be filtered out below.
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'objects',
				)
			);
		} else {
			$products = array_map( 'wc_get_product', (array) ( $a['product_ids'] ?? array() ) );
		}

		$user_id   = (int) ( $ctx['_user_id'] ?? 0 );
		$wholesale = ! empty( $ctx['_wholesale'] );
		$out       = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$id = $product->get_id();

			// The newest products are what the shop shows: never the ones hidden from the
			// catalog (an unlisted kit, say). A product an admin picks by hand is always allowed.
			if ( 'newest' === ( $a['mode'] ?? 'picked' ) && ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog' ), true ) ) {
				continue;
			}

			if ( ! $wholesale && ProductFields::is_wholesale_only( $id ) ) {
				continue;
			}

			if ( $wholesale && $user_id > 0 && 'hide' === Settings::empty_price_behavior() && ! Pricing::is_available_at_wholesale_including_variations( $id, $user_id ) ) {
				continue;
			}

			$out[] = $product;

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The price line for one product, as the reader would pay it: the lowest
	 * wholesale price per pack for a wholesale customer, else the shop price.
	 * Plain text with a real dollar sign (wc_price() returns entities).
	 *
	 * @param array<string, mixed> $ctx
	 */
	public static function grid_price( \WC_Product $product, array $ctx ): string {
		$user_id = (int) ( $ctx['_user_id'] ?? 0 );

		if ( ! empty( $ctx['_wholesale'] ) && $user_id > 0 ) {
			$ids    = $product->is_type( 'variable' ) ? array_map( 'intval', $product->get_children() ) : array( $product->get_id() );
			$prices = array();

			foreach ( $ids as $vid ) {
				$price = Pricing::get_wholesale_price( $vid, $user_id );

				if ( null !== $price ) {
					$prices[] = $price;
				}
			}

			if ( ! empty( $prices ) ) {
				/* translators: %s: price per pack. */
				return sprintf( __( '%s per pack', 'protech-wholesale' ), self::money( min( $prices ) ) );
			}
		}

		$price = $product->is_type( 'variable' ) ? (float) $product->get_variation_price( 'min' ) : (float) $product->get_price();

		return $price > 0 ? self::money( $price ) : '';
	}

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	private static function render_product_grid( array $a, array $ctx, array $style, bool $inner ): string {
		$products = self::grid_products( $a, $ctx );

		if ( empty( $products ) ) {
			return '';
		}

		$columns = self::num( $a['columns'] ?? null, 1, 3, 3 );
		$width   = (int) floor( 100 / $columns );
		$font    = esc_attr( (string) $style['font'] );
		$rows    = array_chunk( $products, $columns );
		$html    = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">';

		foreach ( $rows as $row ) {
			$html .= '<tr>';

			foreach ( $row as $product ) {
				$image_id = $product->get_image_id();
				$src      = $image_id ? wp_get_attachment_image_url( (int) $image_id, 'woocommerce_thumbnail' ) : '';
				$src      = $src ? $src : wc_placeholder_img_src();
				$url      = esc_url( (string) get_permalink( $product->get_id() ) );
				$name     = esc_html( $product->get_name() );

				$cell  = '<a href="' . $url . '" target="_blank" style="text-decoration:none;"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $product->get_name() ) . '" width="100%" style="display:block;width:100%;height:auto;border:0;border-radius:6px;" /></a>';
				$cell .= '<p style="margin:8px 0 2px;font-family:' . $font . ';font-size:14px;font-weight:700;line-height:1.3;color:' . esc_attr( (string) $style['text'] ) . ';"><a href="' . $url . '" target="_blank" style="color:' . esc_attr( (string) $style['text'] ) . ';text-decoration:none;">' . $name . '</a></p>';

				$price = ! empty( $a['show_price'] ) ? self::grid_price( $product, $ctx ) : '';

				if ( '' !== $price ) {
					$cell .= '<p style="margin:0 0 8px;font-family:' . $font . ';font-size:13px;color:' . esc_attr( (string) $style['muted'] ) . ';">' . esc_html( $price ) . '</p>';
				}

				if ( ! empty( $a['show_button'] ) && '' !== trim( (string) ( $a['button_label'] ?? '' ) ) ) {
					$cell .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"><tr><td align="center" bgcolor="' . esc_attr( (string) $style['brand'] ) . '" style="background:' . esc_attr( (string) $style['brand'] ) . ';border-radius:6px;"><a href="' . $url . '" target="_blank" style="display:inline-block;padding:8px 16px;font-family:' . $font . ';font-size:13px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">' . esc_html( (string) $a['button_label'] ) . '</a></td></tr></table>';
				}

				$html .= '<td width="' . $width . '%" valign="top" align="center" style="padding:0 8px 16px;text-align:center;">' . $cell . '</td>';
			}

			// Keep the last row's cells the same width as the rows above it.
			for ( $pad = count( $row ); $pad < $columns; $pad++ ) {
				$html .= '<td width="' . $width . '%" style="padding:0 8px;">&nbsp;</td>';
			}

			$html .= '</tr>';
		}

		return self::row( $html . '</table>', $a, $inner );
	}

	// -- explainers -------------------------------------------------------------

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	private static function render_explainer( bool $quantities, array $a, array $ctx, array $style, bool $inner ): string {
		// Wholesale terms mean nothing to a retail reader; a preview shows them so the admin can see the block.
		if ( empty( $ctx['_wholesale'] ) && empty( $ctx['_preview'] ) ) {
			return '';
		}

		$title = ! empty( $a['show_title'] ) ? self::tags( esc_html( (string) ( $a['title'] ?? '' ) ), $ctx ) : '';
		$args  = self::explainer_numbers() + array( 'blue' => (string) $style['brand'] );

		if ( $quantities ) {
			$body = ( '' !== $title ? '<p style="margin:0 0 16px;font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:' . esc_attr( (string) $style['brand'] ) . ';">' . $title . '</p>' : '' ) . self::quantity_diagram( $args );

			return self::row(
				'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef3fa;border:1px solid #c9d6ea;border-radius:8px;"><tr><td style="padding:20px 22px;font-family:' . esc_attr( (string) $style['font'] ) . ';">' . $body . '</td></tr></table>',
				$a,
				$inner
			);
		}

		$body = ( '' !== $title ? '<h2 style="margin:0 0 10px;font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:18px;color:' . esc_attr( (string) $style['brand'] ) . ';">' . $title . '</h2>' : '' ) . self::pricing_ladder( $args );

		return self::row( '<div style="font-family:' . esc_attr( (string) $style['font'] ) . ';font-size:14px;line-height:1.5;color:' . esc_attr( (string) $style['text'] ) . ';">' . $body . '</div>', $a, $inner );
	}

	/**
	 * The store numbers both explainers are drawn from, so an email can never
	 * disagree with the shop.
	 *
	 * @return array{case_size: int, displays_per_case: int, case_packs: int, volume_threshold: int, threshold_cases: string, bulk_cases: int}
	 */
	public static function explainer_numbers(): array {
		$case_size         = max( 1, Settings::get_default_case_size() );
		$displays_per_case = max( 1, Settings::get_default_displays_per_case() );
		$volume_threshold  = Settings::get_volume_threshold_displays();

		return array(
			'case_size'         => $case_size,
			'displays_per_case' => $displays_per_case,
			'case_packs'        => $case_size * $displays_per_case,
			'volume_threshold'  => $volume_threshold,
			'threshold_cases'   => VolumePricing::format_quantity( $volume_threshold / $displays_per_case ),
			'bulk_cases'        => Settings::get_bulk_threshold_cases(),
		);
	}

	// -- columns ----------------------------------------------------------------

	/**
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $ctx
	 * @param array<string, mixed> $style
	 */
	private static function render_columns( array $block, array $ctx, array $style, bool $inner ): string {
		$a       = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$count   = self::num( $a['count'] ?? null, 2, 3, 2 );
		$half    = (int) floor( (int) ( $a['gap'] ?? 16 ) / 2 );
		$width   = (int) floor( 100 / $count );
		$columns = array_values( (array) ( $block['children'] ?? array() ) );
		$html    = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
		$any     = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$rows = '';

			foreach ( (array) ( $columns[ $i ] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$rows .= self::render( $child, $ctx, $style, true );
				}
			}

			$any  = $any || '' !== $rows;
			$html .= '<td class="pw-col" width="' . $width . '%" valign="' . esc_attr( (string) ( $a['valign'] ?? 'top' ) ) . '" style="width:' . $width . '%;padding:0 ' . $half . 'px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $rows . '</table></td>';
		}

		return $any ? self::row( $html . '</tr></table>', $a, $inner ) : '';
	}

	// -----------------------------------------------------------------
	// Plain text.
	// -----------------------------------------------------------------

	/**
	 * A block as plain text, for the text/plain part of the email, so it does
	 * not depend on stripping tags off a table layout.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $ctx
	 */
	public static function plain( array $block, array $ctx ): string {
		$a      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$values = self::tag_values( $ctx );
		$fill   = static fn( string $s ): string => MergeTags::fill( $s, $values, 'raw' );

		switch ( (string) ( $block['type'] ?? '' ) ) {
			case 'heading':
				return strtoupper( $fill( (string) ( $a['text'] ?? '' ) ) ) . "\n";

			case 'text':
				$html = (string) ( $a['html'] ?? '' );
				$html = (string) preg_replace_callback(
					'/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',
					static fn( array $m ): string => wp_strip_all_tags( $m[2] ) . ' (' . $m[1] . ')',
					$html
				);
				$html = (string) preg_replace( '/<\/li>/i', "\n", (string) preg_replace( '/<li[^>]*>/i', '- ', $html ) );

				return html_entity_decode( $fill( wp_strip_all_tags( $html ) ), ENT_QUOTES, 'UTF-8' ) . "\n";

			case 'button':
				return $fill( (string) ( $a['label'] ?? '' ) ) . ': ' . $fill( (string) ( $a['url'] ?? '' ) ) . "\n";

			case 'image':
				return '' !== ( $a['alt'] ?? '' ) ? '[' . $fill( (string) $a['alt'] ) . "]\n" : '';

			case 'product_grid':
				$out = '';

				foreach ( self::grid_products( $a, $ctx ) as $product ) {
					$out .= '- ' . $product->get_name() . ' - ' . self::grid_price( $product, $ctx ) . ' - ' . get_permalink( $product->get_id() ) . "\n";
				}

				return $out;

			case 'explainer_quantities':
			case 'explainer_ladder':
				if ( empty( $ctx['_wholesale'] ) && empty( $ctx['_preview'] ) ) {
					return '';
				}

				$n = self::explainer_numbers();

				if ( 'explainer_quantities' === $block['type'] ) {
					return sprintf(
						/* translators: 1: displays per case, 2: packs per display. */
						__( '%1$d displays = 1 case. 1 display = %2$d packs. Mix and match your displays however you like.', 'protech-wholesale' ),
						$n['displays_per_case'],
						$n['case_size']
					) . "\n";
				}

				return sprintf(
					/* translators: 1: displays for free shipping, 2: cases, 3: cases for the best price. */
					__( 'Under %1$d displays: your wholesale price. %1$d displays or more (%2$s cases): free shipping and Standard pricing. %3$d cases or more: Volume pricing.', 'protech-wholesale' ),
					$n['volume_threshold'],
					$n['threshold_cases'],
					$n['bulk_cases']
				) . "\n";

			case 'columns':
				$out = '';

				foreach ( (array) ( $block['children'] ?? array() ) as $column ) {
					foreach ( (array) $column as $child ) {
						if ( is_array( $child ) ) {
							$out .= self::plain( $child, $ctx );
						}
					}
				}

				return $out;

			case 'divider':
				return "----\n";
		}

		return '';
	}

	// -----------------------------------------------------------------
	// Drawing helpers, shared with the welcome email.
	// -----------------------------------------------------------------

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

	/**
	 * The pack, display and case picture with the "8 displays = 1 case" rule.
	 * templates/email-quantity-diagram.php, shared with the welcome email.
	 *
	 * @param array<string, mixed> $args case_size, displays_per_case, case_packs, blue.
	 */
	public static function quantity_diagram( array $args ): string {
		return wc_get_template_html( 'email-quantity-diagram.php', $args, '', PROTECH_WHOLESALE_DIR . 'templates/' );
	}

	/**
	 * The three quantity levels and the note under them.
	 * templates/email-pricing-ladder.php, shared with the welcome email.
	 *
	 * @param array<string, mixed> $args displays_per_case, volume_threshold, threshold_cases, bulk_cases.
	 */
	public static function pricing_ladder( array $args ): string {
		return wc_get_template_html( 'email-pricing-ladder.php', $args, '', PROTECH_WHOLESALE_DIR . 'templates/' );
	}
}
