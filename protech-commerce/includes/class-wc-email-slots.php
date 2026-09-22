<?php
/**
 * Lets a designed template stand in for one of WooCommerce's own order
 * emails. Swaps the TEMPLATE FILE `woocommerce_locate_template()` resolves
 * for a bound email, rather than replacing the WC_Email object: enable /
 * recipient / BCC settings, and any other plugin's hooks on the same email
 * (order-table filters, etc.), are untouched. See DECISIONS.md for why a
 * template swap was chosen over subclassing WC_Email.
 *
 * Bound emails are addressed as slot "wc:<id>", stored and bound the same
 * way as the plugin's own lifecycle slots (EmailTemplates::bind_slot()).
 *
 * Covers only the nine WooCommerce emails that carry a real order:
 * `customer_new_account` and `customer_reset_password` have no order at all
 * and would need a different, account-only merge-tag context, so they are
 * deliberately left for a later pass (see DECISIONS.md).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WcEmailSlots
 */
class WcEmailSlots {

	public const PREFIX = 'wc:';

	/**
	 * WooCommerce email id => [html template path, plain template path], as
	 * set in that email class's own constructor (verified against
	 * WooCommerce's source, not guessed from the id).
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const TEMPLATES = array(
		'new_order'                 => array( 'emails/admin-new-order.php', 'emails/plain/admin-new-order.php' ),
		'cancelled_order'           => array( 'emails/admin-cancelled-order.php', 'emails/plain/admin-cancelled-order.php' ),
		'failed_order'              => array( 'emails/admin-failed-order.php', 'emails/plain/admin-failed-order.php' ),
		'customer_on_hold_order'    => array( 'emails/customer-on-hold-order.php', 'emails/plain/customer-on-hold-order.php' ),
		'customer_processing_order' => array( 'emails/customer-processing-order.php', 'emails/plain/customer-processing-order.php' ),
		'customer_completed_order'  => array( 'emails/customer-completed-order.php', 'emails/plain/customer-completed-order.php' ),
		'customer_refunded_order'   => array( 'emails/customer-refunded-order.php', 'emails/plain/customer-refunded-order.php' ),
		'customer_invoice'          => array( 'emails/customer-invoice.php', 'emails/plain/customer-invoice.php' ),
		'customer_note'             => array( 'emails/customer-note.php', 'emails/plain/customer-note.php' ),
	);

	/** A partial refund fires under a second id that shares the full refund's template and slot. */
	private const ID_ALIASES = array(
		'customer_partially_refunded_order' => 'customer_refunded_order',
	);

	public function register_hooks(): void {
		add_filter( 'woocommerce_locate_template', array( $this, 'locate_template' ), 10, 2 );
		add_filter( 'woocommerce_email_styles', array( $this, 'skip_default_styles' ), 10, 2 );
		add_action( 'woocommerce_email_sent', array( $this, 'log_sent' ), 10, 3 );

		foreach ( array_merge( array_keys( self::TEMPLATES ), array_keys( self::ID_ALIASES ) ) as $id ) {
			add_filter( 'woocommerce_email_subject_' . $id, array( $this, 'subject' ), 9, 2 );
		}
	}

	/** @return array<string, string> "wc:<id>" => label, in the template list's own order. */
	public static function slots(): array {
		return array(
			self::PREFIX . 'new_order'                 => __( 'Order emails: New order (to the shop)', 'protech-wholesale' ),
			self::PREFIX . 'cancelled_order'            => __( 'Order emails: Cancelled order (to the shop)', 'protech-wholesale' ),
			self::PREFIX . 'failed_order'               => __( 'Order emails: Failed order (to the shop)', 'protech-wholesale' ),
			self::PREFIX . 'customer_on_hold_order'     => __( 'Order emails: On-hold order (to the customer)', 'protech-wholesale' ),
			self::PREFIX . 'customer_processing_order'  => __( 'Order emails: Processing order (to the customer)', 'protech-wholesale' ),
			self::PREFIX . 'customer_completed_order'   => __( 'Order emails: Completed order (to the customer)', 'protech-wholesale' ),
			self::PREFIX . 'customer_refunded_order'    => __( 'Order emails: Refunded order (to the customer)', 'protech-wholesale' ),
			self::PREFIX . 'customer_invoice'           => __( 'Order emails: Invoice / pay for order (to the customer)', 'protech-wholesale' ),
			self::PREFIX . 'customer_note'              => __( 'Order emails: Note added to order (to the customer)', 'protech-wholesale' ),
		);
	}

	public static function is_wc_slot( string $slot ): bool {
		return str_starts_with( $slot, self::PREFIX );
	}

	private static function canonical_id( string $id ): string {
		return self::ID_ALIASES[ $id ] ?? $id;
	}

	/** The template bound to a WooCommerce email id (following the partial-refund alias), or null when nothing is. */
	private static function template_for( string $id ): ?array {
		return EmailTemplates::for_slot( self::PREFIX . self::canonical_id( $id ) );
	}

	/**
	 * Swaps in this plugin's own template file, only for the exact path an
	 * email with a bound design would otherwise use, so a theme's own
	 * override of some unrelated template is never touched.
	 */
	public function locate_template( string $template, string $template_name ): string {
		foreach ( self::TEMPLATES as $id => $paths ) {
			if ( null === self::template_for( $id ) ) {
				continue;
			}

			if ( $template_name === $paths[0] ) {
				return PROTECH_WHOLESALE_DIR . 'templates/wc-email-slot.php';
			}

			if ( $template_name === $paths[1] ) {
				return PROTECH_WHOLESALE_DIR . 'templates/wc-email-slot-plain.php';
			}
		}

		return $template;
	}

	/**
	 * The finished document for a bound WooCommerce email: our own renderer,
	 * no marketing footer (every one of these nine is a transactional
	 * email), real order-aware merge tags. '' when nothing is bound (should
	 * not happen: locate_template() already checked) or there is no order.
	 *
	 * Called from templates/wc-email-slot(-plain).php with the exact
	 * variables WooCommerce's own template would have received.
	 */
	public static function render( \WC_Email $email, ?\WC_Order $order, bool $plain ): string {
		$template = self::template_for( $email->id );

		if ( null === $template || ! $order instanceof \WC_Order ) {
			return '';
		}

		$context  = EmailRenderer::context( (int) $order->get_customer_id(), false, $order );
		$rendered = EmailRenderer::render( $template, $context, array() );

		return $plain ? $rendered['text'] : $rendered['html'];
	}

	/**
	 * WooCommerce's own subject still runs first (so the wholesale-order
	 * prefix in Emails::flag_wholesale_order_subject(), priority 10, still
	 * applies); a bound template's own subject, tags filled from the real
	 * order, replaces it here at priority 9. current_filter() recovers which
	 * email id fired, since every "woocommerce_email_subject_{id}" filter
	 * shares this one callback.
	 *
	 * @param mixed $order
	 */
	public function subject( string $subject, $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return $subject;
		}

		$id       = substr( (string) current_filter(), strlen( 'woocommerce_email_subject_' ) );
		$template = self::template_for( $id );

		if ( null === $template ) {
			return $subject;
		}

		return EmailRenderer::subject( $template, EmailRenderer::context( (int) $order->get_customer_id(), false, $order ) );
	}

	/**
	 * A bound email's HTML is already fully inline-styled by EmailRenderer;
	 * returning no extra CSS keeps WC_Email::style_inline() (which always
	 * runs) from inlining WooCommerce's own email stylesheet onto our
	 * elements. Untouched for any email nothing is bound to, including a
	 * bare `new WC_Email()` (MessageTransport::legacy_document() makes one
	 * just to reuse style_inline(); its `id` is never set, so it must never
	 * reach template_for()'s string parameter as null).
	 *
	 * @param mixed $email
	 */
	public function skip_default_styles( string $css, $email ): string {
		if ( ! $email instanceof \WC_Email || null === self::template_for( (string) $email->id ) ) {
			return $css;
		}

		return '';
	}

	/**
	 * Logs a bound email's real send the same way Emails::log_lifecycle()
	 * logs the plugin's own lifecycle emails: already finished, one row per
	 * send (the anchor carries the order id and a timestamp, so resending an
	 * invoice or a note is its own row, never deduplicated against the
	 * first).
	 *
	 * @param mixed $email
	 */
	public function log_sent( bool $sent, string $id, $email ): void {
		if ( ! $email instanceof \WC_Email ) {
			return;
		}

		$template = self::template_for( $id );

		if ( null === $template || ! $email->object instanceof \WC_Order ) {
			return;
		}

		$order   = $email->object;
		$to      = (string) $email->get_recipient();
		$subject = (string) $email->get_subject();

		$log_id = MessageLog::enqueue(
			array(
				'user_id'   => (int) $order->get_customer_id(),
				'channel'   => MessageLog::CHANNEL_EMAIL,
				'kind'      => MessageLog::KIND_WC,
				'category'  => MessageLog::CATEGORY_TRANSACTIONAL,
				'rule_id'   => self::PREFIX . self::canonical_id( $id ),
				'anchor'    => self::canonical_id( $id ) . ':' . $order->get_id() . ':' . microtime( true ),
				'recipient' => $to,
				'subject'   => $subject,
			)
		);

		if ( ! $log_id ) {
			return;
		}

		MessageLog::claim( $log_id );
		MessageLog::finish(
			$log_id,
			$sent ? MessageLog::STATUS_SENT : MessageLog::STATUS_FAILED,
			array(
				'provider'  => 'wc_mailer',
				'recipient' => $to,
				'subject'   => $subject,
				'error'     => $sent ? '' : __( 'The site\'s mailer reported the send failed.', 'protech-wholesale' ),
			)
		);
	}
}
