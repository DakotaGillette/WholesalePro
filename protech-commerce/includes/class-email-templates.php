<?php
/**
 * The email template library: what a template is, where it is stored, and
 * how it is created, copied, validated and bound to one of the lifecycle
 * emails.
 *
 * Templates live in a single non-autoloaded option, the same pattern as
 * Automations::OPTION and Campaigns::OPTION. Not a post type: wp_insert_post()
 * runs content through wp_filter_post_kses for any user without
 * unfiltered_html, which would quietly mangle stored block data, the very
 * failure the renderer is built to avoid. See EmailBlocks for the blocks and
 * EmailRenderer for turning a template into an email.
 *
 * A template that a rule or campaign points at is referenced by id from
 * `email[template_id]`; an empty id means the plain string body they had
 * before templates existed, so nothing migrates.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailTemplates
 */
class EmailTemplates {

	public const OPTION     = 'protech_wholesale_email_templates';
	public const MAX_STORED = 200;

	/** The fixed emails the plugin sends on its own, each of which a template can replace. */
	public const SLOT_WELCOME              = 'welcome';
	public const SLOT_APPLICATION_RECEIVED = 'application_received';
	public const SLOT_APPLICATION_APPROVED = 'application_approved';
	public const SLOT_APPLICATION_REJECTED = 'application_rejected';

	public const KIND_MARKETING     = 'marketing';
	public const KIND_TRANSACTIONAL = 'transactional';

	/** Groups templates in the "Start a new template" list; a template a starter never seeded is '' (uncategorized). */
	public const CATEGORIES = array( 'welcome', 'account', 'orders', 'promotions', 'newsletter', 'blank' );

	/**
	 * @return array<string, string> category key => label, in display order.
	 */
	public static function category_labels(): array {
		return array(
			'welcome'    => __( 'Welcome', 'protech-wholesale' ),
			'account'    => __( 'Account', 'protech-wholesale' ),
			'orders'     => __( 'Orders', 'protech-wholesale' ),
			'promotions' => __( 'Promotions', 'protech-wholesale' ),
			'newsletter' => __( 'Newsletter', 'protech-wholesale' ),
			'blank'      => __( 'Blank', 'protech-wholesale' ),
		);
	}

	/**
	 * @return array<string, string> slot => label.
	 */
	public static function slots(): array {
		return array(
			self::SLOT_WELCOME              => __( 'Welcome email (upgraded accounts)', 'protech-wholesale' ),
			self::SLOT_APPLICATION_RECEIVED => __( 'Application received', 'protech-wholesale' ),
			self::SLOT_APPLICATION_APPROVED => __( 'Application approved', 'protech-wholesale' ),
			self::SLOT_APPLICATION_REJECTED => __( 'Application rejected', 'protech-wholesale' ),
		);
	}

	/**
	 * A blank template: the defaults every stored template is filled out to.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'id'         => '',
			'name'       => '',
			'kind'       => self::KIND_MARKETING,
			'slot'       => '',
			'category'   => '',
			'subject'    => '',
			'preheader'  => '',
			'blocks'     => array(),
			'style'      => array(
				'width'          => 0,    // 0 = the site-wide width from Messaging settings.
				'page_bg'        => '#f4f5f7',
				'canvas'         => '#ffffff',
				'brand'          => '',   // '' = the site-wide brand color.
				'text'           => '#1f2937',
				'muted'          => '#6b7280',
				'font'           => 'helvetica',
				'heading_font'   => '',   // '' = the site-wide heading font, or the body font if that is unset too.
				'link_color'     => '',   // '' = the site-wide link color, or the brand color if that is unset too.
				'mobile_padding' => 0,    // 0 = the site-wide mobile side padding.
			),
			'header'     => array(
				'show_logo' => true,
				'logo_id'   => 0,  // 0 = the site-wide logo.
			),
			'footer'     => array(
				'text'         => '',
				'show_address' => true,
			),
			'created_at' => 0,
			'updated_at' => 0,
			'created_by' => 0,
			'seeded'     => '',
		);
	}

	// -----------------------------------------------------------------
	// Reading.
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array<string, mixed>> id => template, newest first.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$templates = array();

		foreach ( $stored as $id => $template ) {
			if ( is_array( $template ) ) {
				$templates[ (string) $id ] = self::fill_out( $template, (string) $id );
			}
		}

		uasort(
			$templates,
			static fn( array $a, array $b ): int => (int) $b['updated_at'] <=> (int) $a['updated_at']
		);

		return $templates;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? self::fill_out( $stored[ $id ], $id ) : null;
	}

	public static function exists( string $id ): bool {
		return null !== self::get( $id );
	}

	/**
	 * @return array<string, string> id => name, for a <select>.
	 */
	public static function choices(): array {
		return array_map( static fn( array $t ): string => (string) $t['name'], self::all() );
	}

	/** The template currently bound to a lifecycle email, if any. */
	public static function for_slot( string $slot ): ?array {
		foreach ( self::all() as $template ) {
			if ( $slot === $template['slot'] ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Every string in a template that may carry merge tags, joined, so unknown
	 * tags can be reported before it is used.
	 */
	public static function merge_tag_source( string $id ): string {
		$template = self::get( $id );

		if ( null === $template ) {
			return '';
		}

		$parts = array( (string) $template['subject'], (string) $template['preheader'], (string) $template['footer']['text'] );

		foreach ( (array) $template['blocks'] as $block ) {
			if ( is_array( $block ) ) {
				$parts[] = EmailBlocks::tag_source( $block );
			}
		}

		return implode( ' ', $parts );
	}

	// -----------------------------------------------------------------
	// Validating and writing.
	// -----------------------------------------------------------------

	/**
	 * Cleans what an admin submitted into a template, and reports what is
	 * wrong with it. Errors do not stop the cleaned template being returned
	 * (a live preview of a half-finished template needs one); the caller
	 * decides whether to save it.
	 *
	 * @param array<string, mixed> $input
	 * @return array{template: array<string, mixed>, errors: string[]}
	 */
	public static function validate( array $input ): array {
		$errors   = array();
		$template = self::defaults();
		$existing = self::get( (string) ( $input['id'] ?? '' ) );

		if ( null !== $existing ) {
			$template = $existing;
		}

		$template['name'] = EmailBlocks::text( $input['name'] ?? '', 120 );

		if ( '' === $template['name'] ) {
			$errors[] = __( 'Give the template a name.', 'protech-wholesale' );
		}

		$template['kind']      = EmailBlocks::pick( $input['kind'] ?? '', array( self::KIND_MARKETING, self::KIND_TRANSACTIONAL ), self::KIND_MARKETING );

		// Which lifecycle email this sends as. Only read when the form sends it, so a form without the field
		// (a starter, an import) leaves the binding as it was. A lifecycle email is a service email by nature.
		if ( array_key_exists( 'slot', $input ) ) {
			$template['slot'] = EmailBlocks::pick( $input['slot'], array_merge( array( '' ), array_keys( self::slots() ), array_keys( WcEmailSlots::slots() ) ), '' );
		}

		if ( '' !== (string) $template['slot'] ) {
			$template['kind'] = self::KIND_TRANSACTIONAL;
		}

		$template['subject']   = EmailBlocks::text( $input['subject'] ?? '', 200 );
		$template['preheader'] = EmailBlocks::text( $input['preheader'] ?? '', 200 );

		// Set by a starter (create_from_starter()/seed_starters()) and otherwise just carried
		// through unchanged: there is no field for it, so a normal save neither invents nor
		// loses which starter (if any) a template came from.
		$template['category'] = EmailBlocks::pick( $input['category'] ?? $template['category'], self::CATEGORIES, '' );

		$style_in = is_array( $input['style'] ?? null ) ? $input['style'] : null;
		$defaults = self::defaults()['style'];

		$template['style'] = null === $style_in ? $template['style'] : array(
			'width'          => 0 === (int) ( $style_in['width'] ?? 0 ) ? 0 : EmailBlocks::num( $style_in['width'], 480, 700, 600 ),
			'page_bg'        => EmailBlocks::hex( $style_in['page_bg'] ?? '', (string) $defaults['page_bg'] ),
			'canvas'         => EmailBlocks::hex( $style_in['canvas'] ?? '', (string) $defaults['canvas'] ),
			'brand'          => EmailBlocks::hex( $style_in['brand'] ?? '', '' ),
			'text'           => EmailBlocks::hex( $style_in['text'] ?? '', (string) $defaults['text'] ),
			'muted'          => EmailBlocks::hex( $style_in['muted'] ?? '', (string) $defaults['muted'] ),
			'font'           => EmailBlocks::pick( $style_in['font'] ?? '', array_keys( EmailBlocks::FONTS ), 'helvetica' ),
			'heading_font'   => EmailBlocks::pick( $style_in['heading_font'] ?? '', array_keys( EmailBlocks::FONTS ), '' ),
			'link_color'     => EmailBlocks::hex( $style_in['link_color'] ?? '', '' ),
			'mobile_padding' => 0 === (int) ( $style_in['mobile_padding'] ?? 0 ) ? 0 : EmailBlocks::num( $style_in['mobile_padding'], 0, 24, 24 ),
		);

		// A group that was not submitted at all keeps what the template already has; a group
		// that was submitted is taken as it stands, so an unticked checkbox (absent from a
		// form post) means off.
		if ( is_array( $input['header'] ?? null ) ) {
			$template['header'] = array(
				'show_logo' => EmailBlocks::flag( $input['header']['show_logo'] ?? false ),
				'logo_id'   => max( 0, (int) ( $input['header']['logo_id'] ?? 0 ) ),
			);
		}

		if ( is_array( $input['footer'] ?? null ) ) {
			$template['footer'] = array(
				'text'         => EmailBlocks::text( $input['footer']['text'] ?? '', 400 ),
				'show_address' => EmailBlocks::flag( $input['footer']['show_address'] ?? false ),
			);
		}

		$raw_blocks = is_array( $input['blocks'] ?? null ) ? $input['blocks'] : array();
		$blocks     = EmailBlocks::sanitize_all( $raw_blocks );

		if ( count( $blocks ) < count( array_filter( $raw_blocks, 'is_array' ) ) ) {
			$errors[] = __( 'Some blocks were dropped: an unknown block type, columns placed inside columns, or a Custom HTML block saved by someone without the unfiltered_html capability.', 'protech-wholesale' );
		}

		$template['blocks'] = $blocks;

		if ( empty( $blocks ) ) {
			$errors[] = __( 'Add at least one block.', 'protech-wholesale' );
		}

		$tag_source = implode( ' ', array_merge( array( $template['subject'], $template['preheader'], $template['footer']['text'] ), array_map( array( EmailBlocks::class, 'tag_source' ), $blocks ) ) );
		$unknown    = MergeTags::unknown_tags( $tag_source, '', WcEmailSlots::is_wc_slot( (string) $template['slot'] ) ? 'order' : '' );

		if ( ! empty( $unknown ) ) {
			$errors[] = sprintf(
				/* translators: %s: comma-separated merge tags. */
				__( 'These merge tags are not recognised: %s', 'protech-wholesale' ),
				'{' . implode( '}, {', $unknown ) . '}'
			);
		}

		return array(
			'template' => $template,
			'errors'   => $errors,
		);
	}

	/**
	 * Stores a template (inserting it when it has no id yet) and returns its id.
	 *
	 * @param array<string, mixed> $template
	 */
	public static function save( array $template ): string {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$now    = time();
		$id     = (string) ( $template['id'] ?? '' );

		if ( ! preg_match( '/^t_[a-z0-9]{6,12}$/', $id ) ) {
			$id                    = 't_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$template['created_at'] = $now;
			$template['created_by'] = get_current_user_id();
		}

		$template['id']         = $id;
		$template['updated_at'] = $now;

		// A slot belongs to one template at a time.
		if ( '' !== ( $template['slot'] ?? '' ) ) {
			foreach ( $stored as $other_id => $other ) {
				if ( (string) $other_id !== $id && is_array( $other ) && ( $other['slot'] ?? '' ) === $template['slot'] ) {
					$stored[ $other_id ]['slot'] = '';
				}
			}
		}

		$stored[ $id ] = $template;

		if ( count( $stored ) > self::MAX_STORED ) {
			uasort( $stored, static fn( $a, $b ): int => (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ) );
			$stored = array_slice( $stored, 0, self::MAX_STORED, true );
		}

		update_option( self::OPTION, $stored, false );

		return $id;
	}

	public static function delete( string $id ): void {
		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && isset( $stored[ $id ] ) ) {
			unset( $stored[ $id ] );
			update_option( self::OPTION, $stored, false );
		}
	}

	/**
	 * A copy under a new id and a new name, never bound to a lifecycle email
	 * (a slot belongs to one template at a time). Returns the new id, or ''
	 * when there is nothing to copy.
	 */
	public static function duplicate( string $id ): string {
		$template = self::get( $id );

		if ( null === $template ) {
			return '';
		}

		$template['id']     = '';
		$template['slot']   = '';
		$template['seeded'] = '';
		/* translators: %s: the name of the template being copied. */
		$template['name']   = sprintf( __( 'Copy of %s', 'protech-wholesale' ), (string) $template['name'] );

		// Fresh block ids, so the copy shares nothing with the original.
		$template['blocks'] = self::reid( (array) $template['blocks'] );

		return self::save( $template );
	}

	/**
	 * @param array<int, mixed> $blocks
	 * @return array<int, mixed>
	 */
	private static function reid( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$blocks[ $i ]['id'] = EmailBlocks::new_id();

			if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
				foreach ( $block['children'] as $c => $column ) {
					$blocks[ $i ]['children'][ $c ] = self::reid( (array) $column );
				}
			}
		}

		return $blocks;
	}

	/** Binds a template to a lifecycle email ('' unbinds it), clearing any other template holding that slot. */
	public static function bind_slot( string $id, string $slot ): void {
		$template = self::get( $id );

		if ( null === $template ) {
			return;
		}

		if ( '' !== $slot && ! array_key_exists( $slot, self::slots() ) ) {
			return;
		}

		$template['slot'] = $slot;
		self::save( $template );
	}

	/**
	 * A new template from a starter, under the starter's own name. Returns
	 * its id, or '' for an unknown key.
	 */
	public static function create_from_starter( string $key ): string {
		$starters = EmailStarters::all();

		if ( ! isset( $starters[ $key ] ) ) {
			return '';
		}

		$template           = self::validate( $starters[ $key ] )['template'];
		$template['id']     = '';
		$template['seeded'] = '';
		$template['slot']   = '';

		return self::save( $template );
	}

	/**
	 * Fills a stored template out to the current shape, so a template saved
	 * by an older version is never missing a key.
	 *
	 * @param array<string, mixed> $template
	 * @return array<string, mixed>
	 */
	private static function fill_out( array $template, string $id ): array {
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $template );

		foreach ( array( 'style', 'header', 'footer' ) as $group ) {
			$merged[ $group ] = array_merge( (array) $defaults[ $group ], is_array( $template[ $group ] ?? null ) ? $template[ $group ] : array() );
		}

		$merged['id']     = $id;
		$merged['blocks'] = is_array( $merged['blocks'] ) ? array_values( $merged['blocks'] ) : array();

		return $merged;
	}

	// -----------------------------------------------------------------
	// Starters.
	// -----------------------------------------------------------------

	/**
	 * Creates starter templates. With no argument that is every starter, and
	 * only while the library is empty, so it never duplicates them and never
	 * brings back one the owner deleted. A later release that adds starters
	 * names just those keys, and each is created only if no template already
	 * came from it. Never binds anything to a lifecycle email: a release must
	 * not change what a customer receives.
	 *
	 * @param string[]|null $only Starter keys to add, or null for the first-time set.
	 * @return int How many were created.
	 */
	public static function seed_starters( ?array $only = null ): int {
		$stored = get_option( self::OPTION, false );

		if ( null === $only && false !== $stored && ! empty( $stored ) ) {
			return 0;
		}

		$have    = array_column( self::all(), 'seeded' );
		$created = 0;

		foreach ( EmailStarters::all() as $key => $starter ) {
			if ( null !== $only && ( ! in_array( $key, $only, true ) || in_array( $key, $have, true ) ) ) {
				continue;
			}

			$starter['seeded'] = $key;
			$template          = self::validate( $starter )['template'];
			$template['seeded'] = $key;
			$template['id']     = '';

			self::save( $template );
			++$created;
		}

		return $created;
	}
}
