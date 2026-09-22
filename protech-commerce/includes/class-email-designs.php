<?php
/**
 * The design of one email you send yourself (3.9.0): a template-shaped array
 * (blocks, style, subject, preheader, header, footer) that belongs to that
 * email alone, the way MailPoet copies a template into each new newsletter.
 * Editing it never touches the template it started from.
 *
 * Each design is its own non-autoloaded option, not an entry in the template
 * library: EmailTemplates::save() trims the library past 200 by age, and a
 * pile of sent emails would silently push out starters and templates bound
 * to automatic emails. One option per design also keeps each Save small.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailDesigns
 */
class EmailDesigns {

	public const OPTION_PREFIX = 'protech_wholesale_email_design_';

	private static function option( string $campaign_id ): string {
		return self::OPTION_PREFIX . sanitize_key( $campaign_id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $campaign_id ): ?array {
		$design = get_option( self::option( $campaign_id ), null );

		return is_array( $design ) ? EmailTemplates::fill_out( $design, '' ) : null;
	}

	/**
	 * Cleans and stores a design. It is always stored, even with errors (a
	 * draft may be half finished); the errors come back for the caller to
	 * show, and only sending refuses them.
	 *
	 * @param array<string, mixed> $input A full or partial design; missing parts keep what is stored.
	 * @return array{design: array<string, mixed>, errors: string[]}
	 */
	public static function save( string $campaign_id, array $input ): array {
		$existing = self::get( $campaign_id ) ?? array();

		// validate() looks an id up in the template library and treats an absent
		// style/header/footer group as "keep the stored one", so a design goes in
		// with no id and with what it already had underneath what changed.
		$input         = array_merge( $existing, $input );
		$input['id']   = '';
		$input['slot'] = '';

		if ( '' === trim( (string) ( $input['name'] ?? '' ) ) ) {
			$input['name'] = __( 'Untitled email', 'protech-wholesale' );
		}

		$validated = EmailTemplates::validate( $input );
		$design    = $validated['template'];

		$design['id']         = '';
		$design['slot']       = '';
		$design['seeded']     = '';
		$design['created_at'] = (int) ( $existing['created_at'] ?? time() );
		$design['created_by'] = (int) ( $existing['created_by'] ?? get_current_user_id() );
		$design['updated_at'] = time();

		update_option( self::option( $campaign_id ), $design, false );

		return array(
			'design' => $design,
			'errors' => $validated['errors'],
		);
	}

	public static function delete( string $campaign_id ): void {
		delete_option( self::option( $campaign_id ) );
	}

	/**
	 * A fresh design from a starter, or null for an unknown key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function from_starter( string $key ): ?array {
		$starters = EmailStarters::all();

		if ( ! isset( $starters[ $key ] ) ) {
			return null;
		}

		return self::copy( (array) $starters[ $key ] );
	}

	/**
	 * A fresh design from a library template, or null when it does not exist.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function from_template( string $template_id ): ?array {
		$template = EmailTemplates::get( $template_id );

		return null === $template ? null : self::copy( $template );
	}

	/**
	 * A fresh design from another email: its own design, or for an email sent
	 * before 3.9.0, the library template it pointed at. Null when neither exists.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function from_campaign( string $campaign_id ): ?array {
		$design = self::get( $campaign_id );

		if ( null !== $design ) {
			return self::copy( $design );
		}

		$campaign = Campaigns::get( $campaign_id );
		$template = (string) ( $campaign['email']['template_id'] ?? '' );

		return '' !== $template ? self::from_template( $template ) : null;
	}

	/**
	 * A copy with new block ids and nothing tying it to where it came from.
	 *
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>
	 */
	private static function copy( array $source ): array {
		$source['id']     = '';
		$source['slot']   = '';
		$source['seeded'] = '';
		$source['blocks'] = EmailTemplates::reid( (array) ( $source['blocks'] ?? array() ) );

		return $source;
	}
}
