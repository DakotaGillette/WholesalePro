<?php
/**
 * The things that have to be true for wholesale to work end to end, each
 * of which fails silently when it isn't: the wholesale shipping method
 * added to a zone, at least one product priced for wholesale, the
 * /wholesale portal page in place, and the application form the plugin
 * listens to actually existing. Shown as one notice at the top of the
 * Wholesale screen only while something is missing, with a link to fix
 * each item.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SetupChecks
 */
class SetupChecks {

	/**
	 * Names of the shipping zones (including "Locations not covered by
	 * your other zones") that have Protech Wholesale Shipping enabled.
	 *
	 * @return string[]
	 */
	public static function zones_with_wholesale_shipping(): array {
		if ( ! class_exists( \WC_Shipping_Zones::class ) ) {
			return array();
		}

		$names = array();
		$zones = \WC_Shipping_Zones::get_zones();

		// The catch-all zone (id 0) is not in get_zones().
		$zones[] = array( 'zone_id' => 0 );

		foreach ( $zones as $zone_data ) {
			$zone = \WC_Shipping_Zones::get_zone( (int) ( $zone_data['zone_id'] ?? 0 ) );

			if ( ! $zone instanceof \WC_Shipping_Zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( WholesaleShippingMethod::METHOD_ID === $method->id ) {
					$names[] = $zone->get_zone_name();
					break;
				}
			}
		}

		return $names;
	}

	public static function has_priced_products(): bool {
		// A plain post query: wc_get_products() does not take meta_key.
		$ids = CatalogQuery::without_filtering(
			static function (): array {
				return get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_key'       => ProductFields::META_HAS_WHOLESALE_PRICE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
			}
		);

		return ! empty( $ids );
	}

	/** The published page carrying the portal shortcode, if any. */
	public static function portal_page_id(): int {
		$page = get_page_by_path( 'wholesale' );

		if ( $page instanceof \WP_Post && 'publish' === $page->post_status && has_shortcode( (string) $page->post_content, 'protech_wholesale_portal' ) ) {
			return (int) $page->ID;
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => '[protech_wholesale_portal',
				'fields'         => 'ids',
			)
		);

		return ! empty( $pages ) ? (int) $pages[0] : 0;
	}

	/**
	 * Null when it cannot be checked (a source other than Fluent Forms, or
	 * Fluent Forms not loaded); true/false otherwise.
	 */
	public static function application_form_exists(): ?bool {
		if ( ApplicationForm::SOURCE_FLUENT_FORMS !== Settings::application_source() ) {
			return null;
		}

		$form_id = (int) Settings::application_form_id();

		if ( $form_id <= 0 || ! function_exists( 'wpFluent' ) ) {
			return null;
		}

		try {
			return null !== wpFluent()->table( 'fluentform_forms' )->where( 'id', $form_id )->first();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Null unless messaging is turned on (an unconnected Brevo is not a
	 * problem for a store that never enabled automations or SMS).
	 */
	public static function brevo_connected(): ?bool {
		if ( ! MessagingSettings::enabled() ) {
			return null;
		}

		return BrevoClient::is_configured();
	}

	/**
	 * Null unless messaging is turned on: a missing table on a store that
	 * never enabled automations or SMS has queued nothing to lose.
	 */
	public static function messages_table_exists(): ?bool {
		if ( ! MessagingSettings::enabled() ) {
			return null;
		}

		return MessageLog::table_exists();
	}

	/**
	 * Soft/advisory: null when there is no privacy policy page to check
	 * at all. A simple keyword check, not a legal review — see the
	 * Compliance view for suggested text to add.
	 */
	public static function privacy_policy_mentions_sms(): ?bool {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy' );

		if ( ! $page_id ) {
			return null;
		}

		$page = get_post( $page_id );

		if ( ! $page instanceof \WP_Post ) {
			return null;
		}

		$content = strtolower( (string) $page->post_content );

		return false !== strpos( $content, 'sms' ) || false !== strpos( $content, 'text message' );
	}

	/**
	 * Everything that is currently wrong, each with a fix link. Empty means
	 * the notice is not shown.
	 *
	 * @return array<int, array{text: string, url: string, link: string}>
	 */
	public static function problems(): array {
		$problems = array();

		if ( empty( self::zones_with_wholesale_shipping() ) ) {
			$problems[] = array(
				'text' => __( '"Protech Wholesale Shipping" is not in any shipping zone, so wholesale customers still see retail shipping.', 'protech-wholesale' ),
				'url'  => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
				'link' => __( 'Add it to a zone', 'protech-wholesale' ),
			);
		}

		if ( ! self::has_priced_products() ) {
			$problems[] = array(
				'text' => __( 'No product has a wholesale price yet, so wholesale customers see an empty shop.', 'protech-wholesale' ),
				'url'  => admin_url( 'edit.php?post_type=product' ),
				'link' => __( 'Open the products list', 'protech-wholesale' ),
			);
		}

		if ( 0 === self::portal_page_id() ) {
			$problems[] = array(
				'text' => __( 'There is no published page with the [protech_wholesale_portal] shortcode, so there is no wholesale login page.', 'protech-wholesale' ),
				'url'  => admin_url( 'post-new.php?post_type=page' ),
				'link' => __( 'Create the page', 'protech-wholesale' ),
			);
		}

		if ( false === self::application_form_exists() ) {
			$problems[] = array(
				'text' => sprintf(
					/* translators: %s: form ID. */
					__( 'Fluent Forms has no form with ID %s, so applications are not being received.', 'protech-wholesale' ),
					Settings::application_form_id()
				),
				'url'  => Approval::tab_url( 'settings' ),
				'link' => __( 'Check the form ID', 'protech-wholesale' ),
			);
		}

		if ( false === self::brevo_connected() ) {
			$problems[] = array(
				'text' => __( 'Automations are on but Brevo is not connected, so no automated emails or texts are actually going out.', 'protech-wholesale' ),
				'url'  => MessagingTab::url( 'settings', array( 'tab' => 'sending' ) ),
				'link' => __( 'Connect Brevo', 'protech-wholesale' ),
			);
		}

		if ( false === self::messages_table_exists() ) {
			$problems[] = array(
				'text' => __( 'The messages table is missing, so nothing queued for automations or campaigns can be sent until it is recreated.', 'protech-wholesale' ),
				'url'  => admin_url( 'plugins.php' ),
				'link' => __( 'Deactivate and reactivate the plugin', 'protech-wholesale' ),
			);
		}

		if ( false === self::privacy_policy_mentions_sms() && MessagingSettings::enabled() ) {
			$problems[] = array(
				'text' => __( 'Your privacy policy doesn\'t appear to mention SMS yet — Brevo\'s toll-free number verification looks for this.', 'protech-wholesale' ),
				'url'  => MessagingTab::url( 'settings', array( 'tab' => 'compliance' ) ),
				'link' => __( 'See suggested wording', 'protech-wholesale' ),
			);
		}

		if ( MessagingSettings::enabled() && '' === MergeTags::store_address() ) {
			$problems[] = array(
				'text' => __( 'Marketing emails must carry your postal address (CAN-SPAM), and the store address is empty, so they go out without one.', 'protech-wholesale' ),
				'url'  => admin_url( 'admin.php?page=wc-settings&tab=general' ),
				'link' => __( 'Set the store address', 'protech-wholesale' ),
			);
		}

		return $problems;
	}

	public static function render_notice(): void {
		$problems = self::problems();

		if ( empty( $problems ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline protech-setup-notice"><p><strong>' . esc_html__( 'Wholesale is not fully set up yet:', 'protech-wholesale' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em;">';

		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem['text'] ) . ' <a href="' . esc_url( $problem['url'] ) . '">' . esc_html( $problem['link'] ) . '</a></li>';
		}

		echo '</ul></div>';
	}
}
