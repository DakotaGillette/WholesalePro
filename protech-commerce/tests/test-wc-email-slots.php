<?php
/**
 * Binding a template to one of WooCommerce's own order emails: the swapped
 * template file, order-aware merge tags (including for a guest order), the
 * subject override, no WooCommerce CSS inlined onto our own markup, and the
 * log row a real send writes.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailBlocks;
use ProtechWholesale\EmailRenderer;
use ProtechWholesale\EmailTemplates;
use ProtechWholesale\MergeTags;
use ProtechWholesale\MessageLog;
use ProtechWholesale\WcEmailSlots;

/**
 * Class Test_Wc_Email_Slots
 */
class Test_Wc_Email_Slots extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		WC()->mailer(); // Registers every default WC_Email subclass, including the ones this test binds.
	}

	private function bind_template( string $slot, array $blocks ): string {
		$result = EmailTemplates::validate(
			array(
				'name'    => 'Test order email',
				'kind'    => EmailTemplates::KIND_TRANSACTIONAL,
				'slot'    => $slot,
				'subject' => 'Order {order_number} for {first_name}',
				'blocks'  => $blocks,
			)
		);

		$this->assertSame( array(), $result['errors'] );

		$id = EmailTemplates::save( $result['template'] );
		EmailTemplates::bind_slot( $id, $slot );

		return $id;
	}

	public function test_an_unbound_woocommerce_email_is_untouched(): void {
		$slots = new WcEmailSlots();

		$this->assertSame(
			'/theme/emails/customer-completed-order.php',
			$slots->locate_template( '/theme/emails/customer-completed-order.php', 'emails/customer-completed-order.php' )
		);
	}

	public function test_a_bound_order_email_sends_the_designed_template_with_the_items_table(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'buyer@example.com', 'first_name' => 'Ada' ) );
		$product = Protech_Test_Factory::simple_product();
		$product->set_name( 'Sleeve pack' );
		$product->save();
		$order = Protech_Test_Factory::order_for( $user_id, $product, 2, 'processing' );

		$this->bind_template(
			'wc:customer_processing_order',
			array(
				array( 'type' => 'heading', 'attrs' => array( 'text' => 'Thanks, {first_name}' ) ),
				array( 'type' => 'order_items' ),
				array( 'type' => 'order_totals' ),
			)
		);

		WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id(), $order );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertNotFalse( $mail, 'trigger() should have sent through wp_mail().' );
		$this->assertStringContainsString( 'Order ' . $order->get_order_number() . ' for Ada', $mail->subject, 'The bound template\'s own subject, tags filled from the real order.' );
		$this->assertStringContainsString( 'Thanks, Ada', $mail->body );
		$this->assertStringContainsString( 'Sleeve pack', $mail->body, 'The order_items block lists the real line item.' );
		$this->assertStringNotContainsString( 'data-pw-block', $mail->body, 'The click-to-select marker is preview-only and must never reach a real send.' );
	}

	public function test_a_guest_order_fills_customer_tags_from_billing(): void {
		$product = Protech_Test_Factory::simple_product();
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Grace' );
		$order->set_billing_last_name( 'Hopper' );
		$order->set_billing_email( 'grace@example.com' );
		$order->calculate_totals();
		$order->save();

		$context = MergeTags::context_for_order( $order );

		$this->assertSame( 'Grace', $context['first_name'] );
		$this->assertSame( 'Grace Hopper', $context['name'] );
		$this->assertSame( 'grace@example.com', $context['email'] );
		$this->assertSame( (string) $order->get_order_number(), $context['order_number'] );
	}

	public function test_order_items_and_totals_blocks_show_nothing_outside_an_order_context(): void {
		$style = EmailRenderer::style( EmailTemplates::defaults() );
		$ctx   = EmailRenderer::context( 0, false, null );

		$items  = EmailBlocks::render( array( 'type' => 'order_items', 'attrs' => array() ), $ctx, $style );
		$totals = EmailBlocks::render( array( 'type' => 'order_totals', 'attrs' => array() ), $ctx, $style );

		$this->assertSame( '', $items );
		$this->assertSame( '', $totals );
	}

	public function test_order_totals_renders_the_orders_own_line_items(): void {
		$product = Protech_Test_Factory::simple_product();
		$order   = Protech_Test_Factory::order_for( 0, $product, 3, 'processing' );
		$style   = EmailRenderer::style( EmailTemplates::defaults() );
		$ctx     = EmailRenderer::context( 0, false, $order );

		$html = EmailBlocks::render( array( 'type' => 'order_totals', 'attrs' => array() ), $ctx, $style );

		// The amount, not the exact markup: wc_price()'s currency-symbol entity can differ in digit
		// padding (&#36; vs &#036;) between two separately formatted strings for the same value.
		$this->assertStringContainsString( 'Subtotal', $html );
		$this->assertStringContainsString( 'Total', $html );
		$this->assertSame( 2, substr_count( wp_strip_all_tags( $html ), '60.00' ), 'The order total appears once in the subtotal row and once in the total row.' );
	}

	public function test_order_tags_validate_only_for_an_order_bound_slot(): void {
		$unbound = EmailTemplates::validate(
			array(
				'name'    => 'Not an order email',
				'subject' => 'Your order {order_number}',
				'blocks'  => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Hi' ) ) ),
			)
		);

		$this->assertNotEmpty( $unbound['errors'], 'An order tag outside an order slot should be reported, same as any unknown tag.' );

		$bound = EmailTemplates::validate(
			array(
				'name'    => 'An order email',
				'slot'    => 'wc:customer_invoice',
				'subject' => 'Your order {order_number}',
				'blocks'  => array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Hi' ) ) ),
			)
		);

		$this->assertSame( array(), $bound['errors'] );
	}

	public function test_the_html_is_not_run_through_woocommerces_email_styles_when_bound(): void {
		$email = new WC_Email_Customer_Invoice();

		$this->bind_template( 'wc:customer_invoice', array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Hi' ) ) ) );

		$slots = new WcEmailSlots();

		$this->assertSame( '', $slots->skip_default_styles( 'body{color:red;}', $email ) );
		$this->assertSame( 'body{color:red;}', $slots->skip_default_styles( 'body{color:red;}', new WC_Email_Customer_Note() ) );
	}

	public function test_a_sent_order_email_is_logged_with_kind_wc(): void {
		$product = Protech_Test_Factory::simple_product();
		$order   = Protech_Test_Factory::order_for( 0, $product, 1, 'processing' );

		$this->bind_template( 'wc:customer_processing_order', array( array( 'type' => 'text', 'attrs' => array( 'html' => 'Hi' ) ) ) );

		// A fresh instance, not the shared WC()->mailer() singleton: this test only needs a
		// WC_Email with the right id, object and recipient, never a real send.
		$email            = new WC_Email_Customer_Processing_Order();
		$email->object    = $order;
		$email->recipient = $order->get_billing_email();

		$slots = new WcEmailSlots();
		$slots->log_sent( true, 'customer_processing_order', $email );

		global $wpdb;
		$table = MessageLog::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE rule_id = %s ORDER BY id DESC LIMIT 1", 'wc:customer_processing_order' ), ARRAY_A );

		$this->assertNotNull( $row );
		$this->assertSame( MessageLog::KIND_WC, $row['kind'] );
		$this->assertSame( MessageLog::STATUS_SENT, $row['status'] );
	}
}
