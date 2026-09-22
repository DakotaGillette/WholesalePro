<?php
/**
 * Merge tags available in automation rules and manual Compose messages —
 * {first_name}, {last_order_total}, {tracking_url}, etc. Rendering
 * happens at send time (not when a message is queued), so an order's
 * status or a shipment's tracking added after queuing is still picked
 * up, and values are always current.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MergeTags
 */
class MergeTags {

	/** Tags available on every trigger. */
	private const CUSTOMER_TAGS = array(
		'first_name', 'name', 'store_name', 'email', 'phone',
		'last_order_number', 'last_order_date', 'last_order_total', 'last_order_url',
		'days_since_last_order', 'order_count',
		'shop_url', 'account_url', 'orders_url', 'preferences_url', 'unsubscribe_url',
		'login_url', 'lost_password_url', 'set_password_url', 'application_reject_reason',
		'site_name', 'brand', 'store_address',
	);

	/** Only available on the order_status trigger and manual "service message" sends with an order attached, or (context 'order') a template bound to a WooCommerce order email. */
	private const ORDER_TAGS = array(
		'order_number', 'order_date', 'order_total', 'order_status', 'order_url',
		'tracking_number', 'tracking_url', 'carrier', 'tracking_block',
		'payment_method', 'shipping_method', 'billing_address', 'shipping_address',
	);

	/** Tags whose value is a URL, so HTML rendering uses esc_url() rather than esc_html(). */
	private const URL_TAGS = array(
		'last_order_url', 'shop_url', 'account_url', 'orders_url', 'preferences_url',
		'unsubscribe_url', 'order_url', 'tracking_url', 'login_url', 'lost_password_url', 'set_password_url',
	);

	/**
	 * @param string $context Pass 'order' for a template bound to a WooCommerce order email (WcEmailSlots), which is
	 *                         its own context rather than a trigger: nothing "triggers" it in the automations sense.
	 * @return array<string, string> tag => human label.
	 */
	public static function all( string $trigger = '', string $context = '' ): array {
		$labels = array(
			'first_name'             => __( 'First name', 'protech-wholesale' ),
			'name'                   => __( 'Full name', 'protech-wholesale' ),
			'store_name'             => __( 'Store name', 'protech-wholesale' ),
			'email'                  => __( 'Email address', 'protech-wholesale' ),
			'phone'                  => __( 'Phone number', 'protech-wholesale' ),
			'last_order_number'      => __( 'Last order number', 'protech-wholesale' ),
			'last_order_date'        => __( 'Last order date', 'protech-wholesale' ),
			'last_order_total'       => __( 'Last order total', 'protech-wholesale' ),
			'last_order_url'         => __( 'Last order link', 'protech-wholesale' ),
			'days_since_last_order'  => __( 'Days since last order', 'protech-wholesale' ),
			'order_count'            => __( 'Total orders placed', 'protech-wholesale' ),
			'shop_url'               => __( 'Shop link', 'protech-wholesale' ),
			'account_url'            => __( 'My Account link', 'protech-wholesale' ),
			'orders_url'             => __( 'Order history link', 'protech-wholesale' ),
			'preferences_url'        => __( 'Notification preferences link', 'protech-wholesale' ),
			'login_url'              => __( 'Wholesale login link', 'protech-wholesale' ),
			'lost_password_url'      => __( 'Password reset link', 'protech-wholesale' ),
			'set_password_url'       => __( 'Set-your-password link (approval email)', 'protech-wholesale' ),
			'application_reject_reason' => __( 'Reason for rejection (application emails, blank if none)', 'protech-wholesale' ),
			'unsubscribe_url'        => __( 'Unsubscribe link', 'protech-wholesale' ),
			'site_name'              => __( 'Site name', 'protech-wholesale' ),
			'brand'                  => __( 'Brand name', 'protech-wholesale' ),
			'store_address'          => __( 'Store postal address', 'protech-wholesale' ),
			'order_number'           => __( 'Order number', 'protech-wholesale' ),
			'order_date'             => __( 'Order date', 'protech-wholesale' ),
			'order_total'            => __( 'Order total', 'protech-wholesale' ),
			'order_status'           => __( 'Order status', 'protech-wholesale' ),
			'order_url'              => __( 'Order link', 'protech-wholesale' ),
			'tracking_number'        => __( 'Tracking number(s)', 'protech-wholesale' ),
			'tracking_url'           => __( 'Tracking link', 'protech-wholesale' ),
			'carrier'                => __( 'Carrier', 'protech-wholesale' ),
			'tracking_block'         => __( 'Tracking details (blank if none yet)', 'protech-wholesale' ),
			'payment_method'         => __( 'Payment method', 'protech-wholesale' ),
			'shipping_method'        => __( 'Shipping method', 'protech-wholesale' ),
			'billing_address'        => __( 'Billing address', 'protech-wholesale' ),
			'shipping_address'       => __( 'Shipping address (blank if same as billing, or no shipping)', 'protech-wholesale' ),
		);

		if ( 'order_status' !== $trigger && 'order' !== $context ) {
			$labels = array_diff_key( $labels, array_flip( self::ORDER_TAGS ) );
		}

		return $labels;
	}

	/**
	 * @return array<string, string> tag => raw value (not yet escaped for any output format).
	 */
	public static function context_for_customer( int $user_id, ?\WC_Order $event_order = null, ?\WC_Order $last_order = null ): array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		$app_name  = trim( (string) get_user_meta( $user_id, '_protech_wholesale_app_name', true ) );
		$first     = $app_name !== '' ? strtok( $app_name, ' ' ) : '';
		$first     = '' !== $first ? $first : trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
		$first     = '' !== $first ? $first : (string) $user->first_name;
		$first     = '' !== $first ? $first : (string) $user->display_name;

		$store_name = trim( (string) get_user_meta( $user_id, '_protech_wholesale_app_store_name', true ) );
		$store_name = '' !== $store_name ? $store_name : trim( (string) get_user_meta( $user_id, 'billing_company', true ) );

		$last_order = $last_order ?? Reorder::get_last_order_for_user( $user_id );

		$context = array(
			'first_name'      => $first,
			'name'            => '' !== $app_name ? $app_name : (string) $user->display_name,
			'store_name'      => $store_name,
			'email'           => (string) $user->user_email,
			'phone'           => SmsConsent::phone_for( $user_id ),
			'shop_url'        => (string) wc_get_page_permalink( 'shop' ),
			'account_url'     => (string) wc_get_page_permalink( 'myaccount' ),
			'orders_url'      => (string) wc_get_account_endpoint_url( 'orders' ),
			'preferences_url' => (string) wc_get_account_endpoint_url( NotificationsEndpoint::ENDPOINT ),
			'unsubscribe_url' => Unsubscribe::url( $user_id ),
			'login_url'         => WelcomeEmail::login_url(),
			'lost_password_url' => (string) wc_lostpassword_url(),
			// The approval email replaces this with a one-time link when it sends. Everywhere else it is
			// the ordinary password-reset page, so it never renders empty and never creates a reset key.
			'set_password_url'          => (string) wc_lostpassword_url(),
			'application_reject_reason' => '',
			'site_name'       => (string) get_bloginfo( 'name' ),
			'brand'           => MessagingSettings::brand(),
			'store_address'   => self::store_address(),
		);

		if ( $last_order instanceof \WC_Order ) {
			$created = $last_order->get_date_created();

			$context['last_order_number']     = (string) $last_order->get_order_number();
			$context['last_order_date']       = $created ? wc_format_datetime( $created ) : '';
			$context['last_order_total']      = wp_strip_all_tags( $last_order->get_formatted_order_total() );
			$context['last_order_url']        = $last_order->get_view_order_url();
			$context['days_since_last_order'] = $created ? (string) (int) floor( ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS ) : '';
		} else {
			foreach ( array( 'last_order_number', 'last_order_date', 'last_order_total', 'last_order_url', 'days_since_last_order' ) as $tag ) {
				$context[ $tag ] = '';
			}
		}

		$context['order_count'] = (string) wc_get_customer_order_count( $user_id );

		if ( $event_order instanceof \WC_Order ) {
			$context = array_merge( $context, self::order_context( $event_order ) );
		}

		return $context;
	}

	/**
	 * @return array<string, string>
	 */
	public static function order_context( \WC_Order $order ): array {
		$created  = $order->get_date_created();
		$tracking = self::tracking_for_order( $order );

		return array(
			'order_number'     => (string) $order->get_order_number(),
			'order_date'       => $created ? wc_format_datetime( $created ) : '',
			'order_total'      => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'order_status'     => wc_get_order_status_name( $order->get_status() ),
			'order_url'        => $order->get_view_order_url(),
			'tracking_number'  => $tracking['number'],
			'tracking_url'     => $tracking['url'],
			'carrier'          => $tracking['carrier'],
			'tracking_block'   => $tracking['block'],
			'payment_method'   => $order->get_payment_method_title(),
			'shipping_method'  => $order->get_shipping_method(),
			'billing_address'  => self::format_order_address( $order, 'billing' ),
			'shipping_address' => $order->has_shipping_address() ? self::format_order_address( $order, 'shipping' ) : '',
		);
	}

	/** A one-line-per-part postal address from an order, the same formatter store_address() uses for the site's own. */
	private static function format_order_address( \WC_Order $order, string $type ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		return wp_strip_all_tags( WC()->countries->get_formatted_address( $order->get_address( $type ), ', ' ) );
	}

	/**
	 * The merge-tag context for a WooCommerce order email (WcEmailSlots): a
	 * logged-in customer's usual context, or, for a guest order, one built
	 * straight from the order's billing details, since there is no wp_user
	 * to read. Either way {order_number} and friends are the order itself,
	 * not a "last order" that might be a different one.
	 *
	 * @return array<string, string>
	 */
	public static function context_for_order( \WC_Order $order ): array {
		$user_id = (int) $order->get_customer_id();

		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			return self::context_for_customer( $user_id, $order );
		}

		$context = array(
			'first_name'                 => $order->get_billing_first_name(),
			'name'                       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'store_name'                 => $order->get_billing_company(),
			'email'                      => $order->get_billing_email(),
			'phone'                      => $order->get_billing_phone(),
			'shop_url'                   => (string) wc_get_page_permalink( 'shop' ),
			'account_url'                => (string) wc_get_page_permalink( 'myaccount' ),
			'orders_url'                 => '',
			'preferences_url'            => '',
			'unsubscribe_url'            => '',
			'login_url'                  => WelcomeEmail::login_url(),
			'lost_password_url'          => (string) wc_lostpassword_url(),
			'set_password_url'           => (string) wc_lostpassword_url(),
			'application_reject_reason'  => '',
			'site_name'                  => (string) get_bloginfo( 'name' ),
			'brand'                      => MessagingSettings::brand(),
			'store_address'              => self::store_address(),
			'last_order_number'          => (string) $order->get_order_number(),
			'last_order_date'            => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
			'last_order_total'           => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'last_order_url'             => $order->get_view_order_url(),
			'days_since_last_order'      => '0',
			'order_count'                => '1',
		);

		return array_merge( $context, self::order_context( $order ) );
	}

	/**
	 * Bridges to the "Advanced Shipment Tracking" plugin when it's active;
	 * falls back to reading its own order-meta shape directly (the same
	 * meta key, in case the reading function isn't available); returns
	 * everything empty when there is no tracking yet, so a template
	 * referencing {tracking_block} degrades gracefully instead of showing
	 * a broken link.
	 *
	 * @return array{number: string, url: string, carrier: string, block: string}
	 */
	public static function tracking_for_order( \WC_Order $order ): array {
		$items = array();

		if ( function_exists( 'ast_get_tracking_items' ) ) {
			$items = (array) ast_get_tracking_items( $order->get_id() );
		} elseif ( class_exists( '\WC_Advanced_Shipment_Tracking_Actions' ) && method_exists( '\WC_Advanced_Shipment_Tracking_Actions', 'get_instance' ) ) {
			$items = (array) \WC_Advanced_Shipment_Tracking_Actions::get_instance()->get_tracking_items( $order->get_id(), true );
		} else {
			$raw = $order->get_meta( '_wc_shipment_tracking_items' );
			$items = is_array( $raw ) ? $raw : array();
		}

		/**
		 * Lets tests (and a site without AST) supply tracking items in the
		 * same shape AST would return.
		 *
		 * @param array<int, array<string, mixed>> $items
		 * @param \WC_Order                        $order
		 */
		$items = (array) apply_filters( 'protech_wholesale_order_tracking', $items, $order );

		if ( empty( $items ) ) {
			return array(
				'number'  => '',
				'url'     => '',
				'carrier' => '',
				'block'   => '',
			);
		}

		$numbers  = array();
		$lines    = array();
		$first_url = '';
		$first_carrier = '';

		foreach ( $items as $item ) {
			$number  = (string) ( $item['tracking_number'] ?? '' );
			$url     = (string) ( $item['formatted_tracking_link'] ?? $item['custom_tracking_link'] ?? '' );
			$carrier = (string) ( $item['formatted_tracking_provider'] ?? $item['custom_tracking_provider'] ?? $item['tracking_provider'] ?? '' );

			if ( '' === $number ) {
				continue;
			}

			$numbers[] = $number;

			if ( '' === $first_url && '' !== $url ) {
				$first_url = $url;
			}

			if ( '' === $first_carrier && '' !== $carrier ) {
				$first_carrier = $carrier;
			}

			$lines[] = trim( $carrier . ' ' . $number . ( '' !== $url ? ' ' . $url : '' ) );
		}

		return array(
			'number'  => implode( ', ', $numbers ),
			'url'     => $first_url,
			'carrier' => $first_carrier,
			'block'   => implode( "\n", $lines ),
		);
	}

	public static function store_address(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		// WooCommerce keeps country and state in one value ("US:IL"); the formatter wants them apart.
		$location = wc_format_country_state_string( (string) get_option( 'woocommerce_default_country' ) );

		$address = array(
			'address_1' => get_option( 'woocommerce_store_address' ),
			'address_2' => get_option( 'woocommerce_store_address_2' ),
			'city'      => get_option( 'woocommerce_store_city' ),
			'state'     => $location['state'],
			'postcode'  => get_option( 'woocommerce_store_postcode' ),
			'country'   => $location['country'],
		);

		return wp_strip_all_tags( WC()->countries->get_formatted_address( $address, ', ' ) );
	}

	/**
	 * The token half of render(): replaces {tags} with their values and does
	 * nothing else. No wp_kses_post, no wpautop, no tag stripping, so a caller
	 * that assembles its own HTML around the result (the email composer) does
	 * not have its markup re-filtered. wp_kses_post() runs every inline style
	 * attribute through safecss_filter_attr(), which silently deletes any
	 * property outside its whitelist, and email needs a few of those.
	 *
	 * `html` escapes each value (esc_url for URL tags, esc_html otherwise);
	 * any other format (`raw`, `text`, `subject`) inserts values untouched, for
	 * attribute values the caller escapes itself and for plain text. An unknown
	 * tag becomes an empty string, never the literal placeholder.
	 *
	 * @param array<string, string> $context tag => raw value.
	 */
	public static function fill( string $template, array $context, string $format = 'html' ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $matches ) use ( $context, $format ): string {
				$tag = $matches[1];

				if ( ! array_key_exists( $tag, $context ) ) {
					return ''; // Unknown tag: validation should have caught it, but never leak the literal placeholder.
				}

				$value = (string) $context[ $tag ];

				if ( 'html' === $format ) {
					return in_array( $tag, self::URL_TAGS, true ) ? esc_url( $value ) : esc_html( $value );
				}

				return $value;
			},
			$template
		);
	}

	/**
	 * @param array<string, string> $context tag => raw value.
	 */
	public static function render( string $template, array $context, string $format = 'html' ): string {
		if ( 'text' === $format ) {
			$template = wp_strip_all_tags( $template );
		} elseif ( 'subject' !== $format ) {
			$template = wp_kses_post( $template );
		}

		$rendered = self::fill( $template, $context, $format );

		if ( 'subject' === $format ) {
			return sanitize_text_field( $rendered );
		}

		if ( 'html' === $format && '' !== trim( $rendered ) && ! preg_match( '/<(p|div|table|ul|ol|br)\b/i', $rendered ) ) {
			$rendered = wpautop( $rendered );
		}

		return $rendered;
	}

	/**
	 * @return string[] Tags referenced in $template that either don't
	 *                   exist at all, or aren't available for $trigger.
	 */
	public static function unknown_tags( string $template, string $trigger = '', string $context = '' ): array {
		preg_match_all( '/\{([a-z_]+)\}/', $template, $matches );
		$available = self::all( $trigger, $context );

		return array_values( array_unique( array_diff( $matches[1] ?? array(), array_keys( $available ) ) ) );
	}

	/**
	 * @return array{chars: int, segments: int, unicode: bool}
	 */
	public static function sms_segments( string $text ): array {
		$chars   = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		$unicode = (bool) preg_match( '/[^\x00-\x7F]/', $text );
		$limit   = $unicode ? 70 : 160;

		return array(
			'chars'    => $chars,
			'segments' => max( 1, (int) ceil( $chars / $limit ) ),
			'unicode'  => $unicode,
		);
	}
}
