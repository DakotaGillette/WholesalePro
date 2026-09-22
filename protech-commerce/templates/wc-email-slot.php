<?php
/**
 * Stands in for a WooCommerce order email's own HTML template, reached only
 * through WcEmailSlots::locate_template(), which already confirmed a design
 * is bound to $email->id. $order and $email are exactly what WooCommerce's
 * own wc_get_template() extracts for its own template.
 *
 * @package ProtechWholesale
 *
 * @var \WC_Order|null $order
 * @var \WC_Email      $email
 */

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo WcEmailSlots::render( $email, $order ?? null, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a finished email document, escaped where EmailRenderer builds it.
