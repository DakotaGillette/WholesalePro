<?php
/**
 * The template editor's form: the blocks as cards you can add, reorder and
 * open, a settings panel, and the preview frame.
 *
 * The form's field names are the state. Every setting is a real input named
 * by position (`blocks[2][attrs][text]`, `blocks[3][children][0][1][attrs][label]`),
 * so the page posts the whole template and the server stays the one source of
 * truth. There is no JSON in a hidden field and no client-side model to keep in
 * step: assets/js/email-composer.js only moves cards around and renumbers the
 * names, using each input's `data-name-suffix` (the part after the position)
 * rather than rewriting the name text.
 *
 * Fields are generated from a small schema per block type, so the plain-language
 * labels live in one place and a new block type needs no new markup.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailEditor
 */
class EmailEditor {

	/**
	 * The fields to edit for a block type, in order. `tag` marks a text field
	 * that accepts personal details (the Insert menu targets it); `summary`
	 * marks the one whose value describes the block in its collapsed card.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( string $type ): array {
		$align = array( 'left' => __( 'Left', 'protech-wholesale' ), 'center' => __( 'Center', 'protech-wholesale' ), 'right' => __( 'Right', 'protech-wholesale' ) );

		switch ( $type ) {
			case 'heading':
				return array(
					array( 'key' => 'text', 'label' => __( 'Heading', 'protech-wholesale' ), 'type' => 'text', 'tag' => true, 'summary' => true ),
					array( 'key' => 'size', 'label' => __( 'Text size (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 14, 'max' => 60 ),
					array( 'key' => 'level', 'label' => __( 'Importance', 'protech-wholesale' ), 'type' => 'select', 'options' => array( '1' => __( 'Main title', 'protech-wholesale' ), '2' => __( 'Heading', 'protech-wholesale' ), '3' => __( 'Small heading', 'protech-wholesale' ) ) ),
					array( 'key' => 'align', 'label' => __( 'Alignment', 'protech-wholesale' ), 'type' => 'select', 'options' => $align ),
					array( 'key' => 'color', 'label' => __( 'Text color', 'protech-wholesale' ), 'type' => 'color', 'help' => __( 'Empty uses the template text color.', 'protech-wholesale' ) ),
				);

			case 'text':
				return array(
					array( 'key' => 'html', 'label' => __( 'Text', 'protech-wholesale' ), 'type' => 'textarea', 'tag' => true, 'summary' => true, 'help' => __( 'A blank line starts a new paragraph. You can use bold, italic, links and lists.', 'protech-wholesale' ) ),
					array( 'key' => 'size', 'label' => __( 'Text size (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 11, 'max' => 28 ),
					array( 'key' => 'align', 'label' => __( 'Alignment', 'protech-wholesale' ), 'type' => 'select', 'options' => $align ),
					array( 'key' => 'color', 'label' => __( 'Text color', 'protech-wholesale' ), 'type' => 'color' ),
				);

			case 'button':
				return array(
					array( 'key' => 'label', 'label' => __( 'Button text', 'protech-wholesale' ), 'type' => 'text', 'tag' => true, 'summary' => true ),
					array( 'key' => 'url', 'label' => __( 'Where it goes', 'protech-wholesale' ), 'type' => 'text', 'tag' => true, 'help' => __( 'A web address, or a personal detail such as the shop link.', 'protech-wholesale' ) ),
					array( 'key' => 'bg_color', 'label' => __( 'Button color', 'protech-wholesale' ), 'type' => 'color', 'help' => __( 'Empty uses the brand color.', 'protech-wholesale' ) ),
					array( 'key' => 'color', 'label' => __( 'Text color', 'protech-wholesale' ), 'type' => 'color' ),
					array( 'key' => 'align', 'label' => __( 'Alignment', 'protech-wholesale' ), 'type' => 'select', 'options' => $align ),
					array( 'key' => 'full_width', 'label' => __( 'Stretch across the email', 'protech-wholesale' ), 'type' => 'checkbox' ),
					array( 'key' => 'radius', 'label' => __( 'Corner roundness (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 0, 'max' => 40 ),
				);

			case 'image':
				return array(
					array( 'key' => 'id', 'label' => __( 'Picture', 'protech-wholesale' ), 'type' => 'image' ),
					array( 'key' => 'alt', 'label' => __( 'Description', 'protech-wholesale' ), 'type' => 'text', 'tag' => true, 'summary' => true, 'help' => __( 'Read out when the picture does not load, and by screen readers.', 'protech-wholesale' ) ),
					array( 'key' => 'link_url', 'label' => __( 'Link (optional)', 'protech-wholesale' ), 'type' => 'text', 'tag' => true ),
					array( 'key' => 'width', 'label' => __( 'Width (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 40, 'max' => 700 ),
					array( 'key' => 'align', 'label' => __( 'Alignment', 'protech-wholesale' ), 'type' => 'select', 'options' => $align ),
				);

			case 'product_grid':
				return array(
					array( 'key' => 'mode', 'label' => __( 'Which products', 'protech-wholesale' ), 'type' => 'select', 'options' => array( 'picked' => __( 'The ones I choose', 'protech-wholesale' ), 'newest' => __( 'The newest in the shop', 'protech-wholesale' ) ) ),
					array( 'key' => 'product_ids', 'label' => __( 'Products', 'protech-wholesale' ), 'type' => 'products', 'help' => __( 'Only used when you choose your own.', 'protech-wholesale' ) ),
					array( 'key' => 'limit', 'label' => __( 'How many (newest)', 'protech-wholesale' ), 'type' => 'number', 'min' => 1, 'max' => 12 ),
					array( 'key' => 'columns', 'label' => __( 'Across', 'protech-wholesale' ), 'type' => 'select', 'options' => array( '1' => __( '1 product', 'protech-wholesale' ), '2' => __( '2 products', 'protech-wholesale' ), '3' => __( '3 products', 'protech-wholesale' ) ) ),
					array( 'key' => 'show_price', 'label' => __( 'Show the price', 'protech-wholesale' ), 'type' => 'checkbox', 'help' => __( 'Wholesale customers see their own wholesale price.', 'protech-wholesale' ) ),
					array( 'key' => 'show_button', 'label' => __( 'Show a button under each', 'protech-wholesale' ), 'type' => 'checkbox' ),
					array( 'key' => 'button_label', 'label' => __( 'Button text', 'protech-wholesale' ), 'type' => 'text' ),
				);

			case 'explainer_quantities':
			case 'explainer_ladder':
				return array(
					array( 'key' => 'show_title', 'label' => __( 'Show a title', 'protech-wholesale' ), 'type' => 'checkbox' ),
					array( 'key' => 'title', 'label' => __( 'Title', 'protech-wholesale' ), 'type' => 'text', 'summary' => true, 'help' => __( 'Drawn from your store settings, so it always matches the shop. Only wholesale customers see it.', 'protech-wholesale' ) ),
				);

			case 'columns':
				return array(
					array( 'key' => 'count', 'label' => __( 'Columns', 'protech-wholesale' ), 'type' => 'select', 'options' => array( '2' => __( 'Two', 'protech-wholesale' ), '3' => __( 'Three', 'protech-wholesale' ) ) ),
					array( 'key' => 'gap', 'label' => __( 'Space between (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 0, 'max' => 40 ),
					array( 'key' => 'valign', 'label' => __( 'Line up at the', 'protech-wholesale' ), 'type' => 'select', 'options' => array( 'top' => __( 'Top', 'protech-wholesale' ), 'middle' => __( 'Middle', 'protech-wholesale' ), 'bottom' => __( 'Bottom', 'protech-wholesale' ) ) ),
				);

			case 'divider':
				return array(
					array( 'key' => 'color', 'label' => __( 'Line color', 'protech-wholesale' ), 'type' => 'color' ),
					array( 'key' => 'thickness', 'label' => __( 'Thickness (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 1, 'max' => 8 ),
					array( 'key' => 'width_pct', 'label' => __( 'Width (%)', 'protech-wholesale' ), 'type' => 'number', 'min' => 10, 'max' => 100 ),
				);

			case 'spacer':
				return array(
					array( 'key' => 'height', 'label' => __( 'Height (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 4, 'max' => 120, 'summary' => true ),
				);
		}

		return array();
	}

	/** The spacing and background every block shares, tucked under "Spacing". */
	public static function common_fields(): array {
		return array(
			array( 'key' => 'pt', 'label' => __( 'Space above (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 0, 'max' => 80 ),
			array( 'key' => 'pb', 'label' => __( 'Space below (px)', 'protech-wholesale' ), 'type' => 'number', 'min' => 0, 'max' => 80 ),
			array( 'key' => 'bg', 'label' => __( 'Background color', 'protech-wholesale' ), 'type' => 'color', 'help' => __( 'Empty is transparent.', 'protech-wholesale' ) ),
		);
	}

	// -----------------------------------------------------------------
	// Fields.
	// -----------------------------------------------------------------

	/**
	 * One labelled input. `$name_prefix` is the position so far (`blocks[2]`);
	 * the suffix is kept in data-name-suffix so the script can renumber.
	 *
	 * @param array<string, mixed> $field
	 * @param mixed                $value
	 */
	private static function field( array $field, $value, string $name_prefix, string $suffix_root = '[attrs]' ): string {
		$key    = (string) $field['key'];
		$suffix = $suffix_root . '[' . $key . ']';
		$name   = $name_prefix . $suffix;
		$type   = (string) $field['type'];
		$label  = '<span class="protech-field-label">' . esc_html( (string) $field['label'] ) . '</span>';
		$help   = ! empty( $field['help'] ) ? '<span class="protech-field-help">' . esc_html( (string) $field['help'] ) . '</span>' : '';
		$attrs  = 'name="' . esc_attr( $name ) . '" data-name-suffix="' . esc_attr( $suffix ) . '"';
		$class  = 'protech-field protech-field--' . $type;

		switch ( $type ) {
			case 'textarea':
				return '<p class="' . $class . '"><label>' . $label . '<textarea rows="6" class="widefat' . ( ! empty( $field['tag'] ) ? ' protech-tag-target' : '' ) . '" ' . $attrs . ' ' . ( ! empty( $field['summary'] ) ? 'data-summary="1"' : '' ) . '>' . esc_textarea( (string) $value ) . '</textarea></label>' . $help . '</p>';

			case 'number':
				return '<p class="' . $class . '"><label>' . $label . '<input type="number" ' . $attrs . ' value="' . esc_attr( (string) $value ) . '" min="' . (int) ( $field['min'] ?? 0 ) . '" max="' . (int) ( $field['max'] ?? 999 ) . '" step="' . esc_attr( (string) ( $field['step'] ?? 1 ) ) . '" ' . ( ! empty( $field['summary'] ) ? 'data-summary="1"' : '' ) . ' /></label>' . $help . '</p>';

			case 'select':
				$html = '<p class="' . $class . '"><label>' . $label . '<select ' . $attrs . '>';

				foreach ( (array) $field['options'] as $option => $option_label ) {
					$html .= '<option value="' . esc_attr( (string) $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
				}

				return $html . '</select></label>' . $help . '</p>';

			case 'color':
				return '<p class="' . $class . '"><label>' . $label . '<span class="protech-color"><input type="color" class="protech-color-pick" value="' . esc_attr( '' !== (string) $value ? (string) $value : '#42649d' ) . '" aria-label="' . esc_attr( (string) $field['label'] ) . '" /><input type="text" ' . $attrs . ' value="' . esc_attr( (string) $value ) . '" maxlength="7" placeholder="#rrggbb" class="protech-color-text" /></span></label>' . $help . '</p>';

			case 'checkbox':
				// An unticked box is absent from a form post, so a hidden 0 goes first.
				return '<p class="' . $class . '"><label><input type="hidden" ' . $attrs . ' value="0" /><input type="checkbox" ' . $attrs . ' value="1"' . checked( ! empty( $value ), true, false ) . ' /> ' . esc_html( (string) $field['label'] ) . '</label>' . $help . '</p>';

			case 'image':
				return self::media_picker( (int) $value, $name, $suffix, (string) $field['label'], $key );

			case 'products':
				return self::product_picker( (array) $value, $name_prefix, $suffix, $label, $help, $class );

			default:
				return '<p class="' . $class . '"><label>' . $label . '<input type="text" class="widefat' . ( ! empty( $field['tag'] ) ? ' protech-tag-target' : '' ) . '" ' . $attrs . ' value="' . esc_attr( (string) $value ) . '" ' . ( ! empty( $field['summary'] ) ? 'data-summary="1"' : '' ) . ' /></label>' . $help . '</p>';
		}
	}

	/** A Media Library picture: the id in a hidden input, a thumbnail, and a Choose button the script wires up. */
	private static function media_picker( int $id, string $name, string $suffix, string $label, string $key ): string {
		$thumb = $id > 0 ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';

		return '<div class="protech-field protech-field--image protech-media-picker">'
			. '<span class="protech-field-label">' . esc_html( $label ) . '</span>'
			. '<span class="protech-media-thumb">' . ( $thumb ? '<img src="' . esc_url( (string) $thumb ) . '" alt="" />' : '' ) . '</span>'
			. '<input type="hidden" name="' . esc_attr( $name ) . '" data-name-suffix="' . esc_attr( $suffix ) . '" data-media-id="1" value="' . esc_attr( (string) $id ) . '" />'
			. '<button type="button" class="button protech-media-choose">' . esc_html__( 'Choose picture', 'protech-wholesale' ) . '</button> '
			. '<button type="button" class="button-link protech-media-clear"' . ( $id > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'protech-wholesale' ) . '</button>'
			. '</div>';
	}

	/**
	 * @param array<int, mixed> $ids
	 */
	private static function product_picker( array $ids, string $name_prefix, string $suffix, string $label, string $help, string $class ): string {
		$html = '<p class="' . $class . '"><label>' . $label . '<select class="wc-product-search widefat" multiple="multiple" name="' . esc_attr( $name_prefix . $suffix ) . '[]" data-name-suffix="' . esc_attr( $suffix ) . '[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'protech-wholesale' ) . '" data-action="woocommerce_json_search_products_and_variations">';

		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );

			if ( $product instanceof \WC_Product ) {
				$html .= '<option value="' . esc_attr( (string) $product->get_id() ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}

		return $html . '</select></label>' . $help . '</p>';
	}

	// -----------------------------------------------------------------
	// Blocks.
	// -----------------------------------------------------------------

	/**
	 * One block as a card. Used for the blocks already in the template and, with
	 * placeholder values, for the copies the script clones when you add one.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function card( array $block, string $name_prefix, bool $nested = false ): string {
		$type  = (string) ( $block['type'] ?? '' );
		$types = EmailBlocks::types();

		if ( ! isset( $types[ $type ] ) ) {
			return '';
		}

		$attrs = array_merge( $types[ $type ]['defaults'], is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );
		$label = $types[ $type ]['label'];

		$html  = '<li class="protech-block" data-type="' . esc_attr( $type ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( $name_prefix . '[id]' ) . '" data-name-suffix="[id]" value="' . esc_attr( (string) ( $block['id'] ?? '' ) ) . '" />';
		$html .= '<input type="hidden" name="' . esc_attr( $name_prefix . '[type]' ) . '" data-name-suffix="[type]" value="' . esc_attr( $type ) . '" />';

		$html .= '<div class="protech-block-head" draggable="true">';
		$html .= '<span class="protech-block-handle" aria-hidden="true">&#8942;&#8942;</span>';
		$html .= '<button type="button" class="protech-block-toggle" aria-expanded="false"><strong class="protech-block-title">' . esc_html( $label ) . '</strong> <span class="protech-block-summary"></span></button>';
		$html .= '<span class="protech-block-actions">';
		$html .= '<button type="button" class="button-link" data-act="up" aria-label="' . esc_attr__( 'Move up', 'protech-wholesale' ) . '">&uarr;</button>';
		$html .= '<button type="button" class="button-link" data-act="down" aria-label="' . esc_attr__( 'Move down', 'protech-wholesale' ) . '">&darr;</button>';
		$html .= '<button type="button" class="button-link" data-act="copy" aria-label="' . esc_attr__( 'Duplicate this block', 'protech-wholesale' ) . '">' . esc_html__( 'Copy', 'protech-wholesale' ) . '</button>';
		$html .= '<button type="button" class="button-link protech-remove" data-act="remove" aria-label="' . esc_attr__( 'Remove this block', 'protech-wholesale' ) . '">' . esc_html__( 'Remove', 'protech-wholesale' ) . '</button>';
		$html .= '</span></div>';

		$html .= '<div class="protech-block-body" hidden>';

		foreach ( self::fields( $type ) as $field ) {
			$html .= self::field( $field, $attrs[ (string) $field['key'] ] ?? '', $name_prefix );
		}

		if ( 'columns' === $type ) {
			$html .= self::column_lists( $block, $name_prefix );
		}

		if ( 'spacer' !== $type && 'columns' !== $type ) {
			$html .= '<details class="protech-block-more"><summary>' . esc_html__( 'Spacing and background', 'protech-wholesale' ) . '</summary>';

			foreach ( self::common_fields() as $field ) {
				$html .= self::field( $field, $attrs[ (string) $field['key'] ] ?? '', $name_prefix );
			}

			$html .= '</details>';
		}

		return $html . '</div></li>';
	}

	/**
	 * The lists inside a Columns block: always three in the page, the third
	 * hidden when there are two, so switching the count needs no reload.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function column_lists( array $block, string $name_prefix ): string {
		$count    = (int) ( $block['attrs']['count'] ?? 2 );
		$children = is_array( $block['children'] ?? null ) ? array_values( $block['children'] ) : array();
		$html     = '<div class="protech-columns">';

		for ( $c = 0; $c < 3; $c++ ) {
			$html .= '<div class="protech-column" data-column="' . $c . '"' . ( $c >= $count ? ' hidden' : '' ) . '>';
			$html .= '<p class="protech-column-title">' . esc_html( sprintf( /* translators: %d: column number. */ __( 'Column %d', 'protech-wholesale' ), $c + 1 ) ) . '</p>';
			$html .= '<ol class="protech-block-list protech-block-list--nested" data-column="' . $c . '">';

			foreach ( (array) ( $children[ $c ] ?? array() ) as $j => $child ) {
				if ( is_array( $child ) ) {
					$html .= self::card( $child, $name_prefix . '[children][' . $c . '][' . $j . ']', true );
				}
			}

			$html .= '</ol>' . self::add_menu( true ) . '</div>';
		}

		return $html . '</div>';
	}

	/** The "+ Add a block" menu inside a column (columns cannot hold columns). */
	private static function add_menu( bool $nested ): string {
		$html = '<select class="protech-add-in-list" aria-label="' . esc_attr__( 'Add a block to this column', 'protech-wholesale' ) . '"><option value="">' . esc_html__( '+ Add a block', 'protech-wholesale' ) . '</option>';

		foreach ( EmailBlocks::types() as $type => $def ) {
			if ( $nested && 'columns' === $type ) {
				continue;
			}

			$html .= '<option value="' . esc_attr( $type ) . '">' . esc_html( $def['label'] ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * Empty cards, one per block type, that the script clones and renumbers.
	 * Printed in <script type="text/html"> so they are inert until used.
	 */
	public static function block_templates(): string {
		$html = '';

		foreach ( array_keys( EmailBlocks::types() ) as $type ) {
			$block = EmailBlocks::make( $type );

			if ( null === $block ) {
				continue;
			}

			$block['id'] = '';

			if ( 'columns' === $type ) {
				$block['attrs'] = EmailBlocks::types()['columns']['defaults'];
			}

			$html .= '<script type="text/html" id="protech-block-tpl-' . esc_attr( $type ) . '">' . self::card( $block, 'blocks[0]' ) . '</script>';
		}

		return $html;
	}

	// -----------------------------------------------------------------
	// The whole editor.
	// -----------------------------------------------------------------

	/**
	 * The "Insert a personal detail" menu: plain-language names for the merge
	 * tags, so nobody has to type braces.
	 */
	private static function insert_menu(): string {
		$html = '<label class="protech-insert"><span class="screen-reader-text">' . esc_html__( 'Insert a personal detail', 'protech-wholesale' ) . '</span><select id="protech-insert-detail"><option value="">' . esc_html__( 'Insert a personal detail…', 'protech-wholesale' ) . '</option>';

		foreach ( MergeTags::all( '' ) as $tag => $label ) {
			$html .= '<option value="{' . esc_attr( $tag ) . '}">' . esc_html( $label ) . '</option>';
		}

		return $html . '</select></label>';
	}

	/**
	 * @param array<string, mixed> $template A cleaned template (from EmailTemplates::validate() or ::get()).
	 * @param string[]             $errors
	 */
	public static function render( array $template, string $id, array $errors = array() ): void {
		$is_new   = '' === $id || 'new' === $id;
		$style    = (array) $template['style'];
		$header   = (array) $template['header'];
		$footer   = (array) $template['footer'];
		$admin    = admin_url( 'admin-post.php' );
		$back     = MessagingTab::url( 'templates' );
		$field    = static fn( string $label, string $html ): string => '<p class="protech-field"><label><span class="protech-field-label">' . esc_html( $label ) . '</span>' . $html . '</label></p>';

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error inline"><ul style="margin:0.5em 0 0 1.5em;list-style:disc;">';

			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}

			echo '</ul></div>';
		}

		echo '<form method="post" action="' . esc_url( $admin ) . '" class="protech-composer" id="protech-composer-form">';
		wp_nonce_field( EmailComposer::SAVE_ACTION, EmailComposer::NONCE_FIELD );
		// The submit buttons carry the real `action` (save, preview, test); this is the default.
		echo '<input type="hidden" name="action" value="' . esc_attr( EmailComposer::SAVE_ACTION ) . '" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( $is_new ? '' : $id ) . '" />';

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All templates', 'protech-wholesale' ) . '</a></p>';
		echo '<h2>' . esc_html( $is_new ? __( 'New email template', 'protech-wholesale' ) : __( 'Edit email template', 'protech-wholesale' ) ) . '</h2>';

		echo '<div class="protech-composer-grid">';

		// -- Left: what is in the email. ---------------------------------------
		echo '<div class="protech-composer-main">';
		echo '<div class="protech-composer-top">';
		echo $field( __( 'Template name', 'protech-wholesale' ), '<input type="text" name="name" class="widefat" value="' . esc_attr( (string) $template['name'] ) . '" required="required" />' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo $field( __( 'Subject line', 'protech-wholesale' ), '<input type="text" name="subject" class="widefat protech-tag-target" value="' . esc_attr( (string) $template['subject'] ) . '" />' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo $field( __( 'Preview text', 'protech-wholesale' ), '<input type="text" name="preheader" class="widefat protech-tag-target" value="' . esc_attr( (string) $template['preheader'] ) . '" /><span class="protech-field-help">' . esc_html__( 'The grey line many inboxes show after the subject.', 'protech-wholesale' ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';

		echo '<div class="protech-composer-bar"><h3>' . esc_html__( 'Email content', 'protech-wholesale' ) . '</h3>' . self::insert_menu() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p class="protech-composer-hint">' . esc_html__( 'Click a block to edit it. Drag blocks by the dots, or use the arrows, to reorder them.', 'protech-wholesale' ) . '</p>';

		echo '<ol class="protech-block-list" id="protech-blocks">';

		foreach ( array_values( (array) $template['blocks'] ) as $i => $block ) {
			if ( is_array( $block ) ) {
				echo self::card( $block, 'blocks[' . $i . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
		}

		echo '</ol>';
		echo '<p class="protech-composer-empty"' . ( empty( $template['blocks'] ) ? '' : ' hidden' ) . '>' . esc_html__( 'No blocks yet. Add one below.', 'protech-wholesale' ) . '</p>';

		echo '<div class="protech-palette" role="group" aria-label="' . esc_attr__( 'Add a block', 'protech-wholesale' ) . '"><strong>' . esc_html__( 'Add a block:', 'protech-wholesale' ) . '</strong> ';

		foreach ( EmailBlocks::types() as $type => $def ) {
			echo '<button type="button" class="button protech-add-block" data-type="' . esc_attr( $type ) . '" title="' . esc_attr( $def['help'] ) . '">' . esc_html( $def['label'] ) . '</button> ';
		}

		echo '</div>';
		echo '<div class="protech-live screen-reader-text" id="protech-composer-status" aria-live="polite" role="status"></div>';
		echo '</div>';

		// -- Right: settings and preview. --------------------------------------
		echo '<aside class="protech-composer-side">';
		echo '<div class="protech-panel">';
		echo '<h3>' . esc_html__( 'Preview', 'protech-wholesale' ) . '</h3>';
		echo '<p class="protech-preview-controls">';
		echo '<button type="button" class="button button-small protech-device is-active" data-width="100%">' . esc_html__( 'Desktop', 'protech-wholesale' ) . '</button> ';
		echo '<button type="button" class="button button-small protech-device" data-width="375px">' . esc_html__( 'Phone', 'protech-wholesale' ) . '</button> ';
		echo '<button type="submit" class="button button-small" id="protech-refresh-preview" name="action" value="' . esc_attr( EmailComposer::DRAFT_PREVIEW_ACTION ) . '" formtarget="protech-preview" formnovalidate="formnovalidate">' . esc_html__( 'Refresh', 'protech-wholesale' ) . '</button>';
		echo '</p>';
		echo '<div class="protech-preview-wrap"><iframe name="protech-preview" id="protech-preview" title="' . esc_attr__( 'Email preview', 'protech-wholesale' ) . '" src="about:blank"></iframe></div>';
		echo '<p class="description">' . esc_html__( 'Filled in with your own name, so you see real values.', 'protech-wholesale' ) . '</p>';
		echo '</div>';

		echo '<div class="protech-panel"><h3>' . esc_html__( 'Send a preview', 'protech-wholesale' ) . '</h3>';
		echo '<p class="protech-field"><label><span class="protech-field-label">' . esc_html__( 'Email address', 'protech-wholesale' ) . '</span><input type="email" name="preview_email" class="widefat" placeholder="' . esc_attr( wp_get_current_user()->user_email ) . '" /></label></p>';
		echo '<button type="submit" class="button" name="action" value="' . esc_attr( EmailComposer::SEND_TEST_ACTION ) . '" formnovalidate="formnovalidate">' . esc_html__( 'Send preview', 'protech-wholesale' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Leave the address blank to send it to yourself.', 'protech-wholesale' ) . '</p></div>';

		echo '<div class="protech-panel"><h3>' . esc_html__( 'Email settings', 'protech-wholesale' ) . '</h3>';
		echo $field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			__( 'Type', 'protech-wholesale' ),
			'<select name="kind"><option value="marketing"' . selected( $template['kind'], 'marketing', false ) . '>' . esc_html__( 'Marketing (has unsubscribe links)', 'protech-wholesale' ) . '</option><option value="transactional"' . selected( $template['kind'], 'transactional', false ) . '>' . esc_html__( 'Service email (no unsubscribe links)', 'protech-wholesale' ) . '</option></select>'
		);
		echo '</div>';

		echo '<div class="protech-panel"><h3>' . esc_html__( 'Sent automatically as', 'protech-wholesale' ) . '</h3>';
		$slot_choices = '<option value="">' . esc_html__( 'Nothing (I will pick it in a rule or message)', 'protech-wholesale' ) . '</option>';

		foreach ( EmailTemplates::slots() as $slot_key => $slot_label ) {
			$slot_choices .= '<option value="' . esc_attr( (string) $slot_key ) . '"' . selected( (string) $template['slot'], (string) $slot_key, false ) . '>' . esc_html( (string) $slot_label ) . '</option>';
		}

		echo $field( __( 'Use this design for', 'protech-wholesale' ), '<select name="slot">' . $slot_choices . '</select>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p class="description">' . esc_html__( 'Pick one of the emails the shop sends on its own (a welcome, or a reply to an application) and this design is sent in place of the built-in wording. Only one template can have each. Pick "Nothing" to go back to the built-in email. These are service emails, so they carry no unsubscribe link.', 'protech-wholesale' ) . '</p>';
		echo '</div>';

		echo '<div class="protech-panel"><h3>' . esc_html__( 'Design', 'protech-wholesale' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Leave a color empty to use your Email design settings.', 'protech-wholesale' ) . '</p>';

		foreach (
			array(
				array( 'key' => 'brand', 'label' => __( 'Brand color', 'protech-wholesale' ), 'type' => 'color' ),
				array( 'key' => 'text', 'label' => __( 'Text color', 'protech-wholesale' ), 'type' => 'color' ),
				array( 'key' => 'canvas', 'label' => __( 'Email background', 'protech-wholesale' ), 'type' => 'color' ),
				array( 'key' => 'page_bg', 'label' => __( 'Page background', 'protech-wholesale' ), 'type' => 'color' ),
				array( 'key' => 'font', 'label' => __( 'Font', 'protech-wholesale' ), 'type' => 'select', 'options' => array( 'helvetica' => 'Helvetica / Arial', 'georgia' => 'Georgia', 'system' => __( 'System font', 'protech-wholesale' ) ) ),
				array( 'key' => 'width', 'label' => __( 'Width (px, 0 = default)', 'protech-wholesale' ), 'type' => 'number', 'min' => 0, 'max' => 700 ),
			) as $f
		) {
			echo self::field( $f, $style[ $f['key'] ] ?? '', 'style', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}

		echo '</div>';

		echo '<div class="protech-panel"><h3>' . esc_html__( 'Header and footer', 'protech-wholesale' ) . '</h3>';
		echo '<p class="protech-field"><label><input type="hidden" name="header[show_logo]" value="0" /><input type="checkbox" name="header[show_logo]" value="1"' . checked( ! empty( $header['show_logo'] ), true, false ) . ' /> ' . esc_html__( 'Show the logo (or store name) at the top', 'protech-wholesale' ) . '</label></p>';
		echo self::media_picker( (int) ( $header['logo_id'] ?? 0 ), 'header[logo_id]', '', __( 'Logo for this template (empty uses the one in Email design)', 'protech-wholesale' ), 'logo_id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo $field( __( 'Footer text', 'protech-wholesale' ), '<textarea name="footer[text]" rows="3" class="widefat protech-tag-target">' . esc_textarea( (string) $footer['text'] ) . '</textarea>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p class="protech-field"><label><input type="hidden" name="footer[show_address]" value="0" /><input type="checkbox" name="footer[show_address]" value="1"' . checked( ! empty( $footer['show_address'] ), true, false ) . ' /> ' . esc_html__( 'Show the store address (marketing emails always show it)', 'protech-wholesale' ) . '</label></p>';
		echo '</div>';

		echo '<p class="protech-composer-save"><button type="submit" name="action" value="' . esc_attr( EmailComposer::SAVE_ACTION ) . '" class="button button-primary button-large">' . esc_html( $is_new ? __( 'Create template', 'protech-wholesale' ) : __( 'Save template', 'protech-wholesale' ) ) . '</button></p>';
		echo '</aside>';

		echo '</div>'; // Grid.
		echo self::block_templates(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</form>';
	}
}
