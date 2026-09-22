<?php
/**
 * The plain-text stand-in for wc-email-slot.php. WooCommerce only renders
 * this when an email's type is "multipart", so most stores never reach it,
 * but a bound design still needs a real plain-text alternative when one is
 * generated.
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

echo WcEmailSlots::render( $email, $order ?? null, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, nothing here is markup.
