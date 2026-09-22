<?php
/**
 * The Emails screen's lists (Messaging → Emails, see AutomationsScreen for the tabs): every email the shop can send,
 * in three plain groups, for an admin who is not technical.
 *
 *  - When someone joins: the four emails the shop sends on its own (welcome,
 *    application received, approved, rejected), each either the built-in
 *    wording or a designed template.
 *  - Order emails: the WooCommerce transactional emails, each either its
 *    own default design or a designed template (see class-wc-email-slots.php).
 *  - Sent: past campaigns, with "Duplicate and edit" to send one again.
 *
 * Multi-step flows (since 3.6.0) are their own screen, Messaging → Automations (FlowsScreen).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailsScreen
 */
class EmailsScreen {

	public const DESIGN_ACTION         = 'protech_design_lifecycle';
	public const UNBIND_ACTION         = 'protech_unbind_lifecycle';
	public const DUPLICATE_SEND_ACTION = 'protech_duplicate_campaign';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::DESIGN_ACTION, array( $this, 'handle_design' ) );
		add_action( 'admin_post_' . self::UNBIND_ACTION, array( $this, 'handle_unbind' ) );
		add_action( 'admin_post_' . self::DUPLICATE_SEND_ACTION, array( $this, 'handle_duplicate_send' ) );
	}

	/**
	 * The lifecycle emails: slot => when it is sent, and the starter template
	 * that designs it.
	 *
	 * @return array<string, array{when: string, starter: string}>
	 */
	private static function lifecycle(): array {
		return array(
			EmailTemplates::SLOT_WELCOME              => array(
				'when'    => __( 'Sent when you upgrade an account to wholesale (Customers tab, Send welcome email).', 'protech-wholesale' ),
				'starter' => 'welcome',
			),
			EmailTemplates::SLOT_APPLICATION_RECEIVED => array(
				'when'    => __( 'Sent to someone right after they apply for a wholesale account.', 'protech-wholesale' ),
				'starter' => 'application_received',
			),
			EmailTemplates::SLOT_APPLICATION_APPROVED => array(
				'when'    => __( 'Sent when you approve an application. Includes the link to set a password.', 'protech-wholesale' ),
				'starter' => 'application_approved',
			),
			EmailTemplates::SLOT_APPLICATION_REJECTED => array(
				'when'    => __( 'Sent when you reject an application, with the reason if you gave one.', 'protech-wholesale' ),
				'starter' => 'application_rejected',
			),
		);
	}

	/** A nonce'd admin-post link for one of this screen's actions. */
	private static function link( string $action, string $key ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $action, 'key' => $key ), admin_url( 'admin-post.php' ) ), $action . '_' . $key );
	}

	// -----------------------------------------------------------------
	// Drawing.
	// -----------------------------------------------------------------

	/** "When someone joins": the four emails the shop sends on its own. */
	public static function render_lifecycle(): void {
		echo '<h2>' . esc_html__( 'When someone joins', 'protech-wholesale' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'These go out on their own. Each one is either the built-in wording or a template you have designed.', 'protech-wholesale' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Email', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Wording', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Sent', 'protech-wholesale' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( self::lifecycle() as $slot => $info ) {
			$template = EmailTemplates::for_slot( $slot );
			// Only the welcome email is recorded per customer; the application emails are not logged.
			$sent = EmailTemplates::SLOT_WELCOME === $slot ? (string) count( get_users( array( 'meta_key' => WelcomeEmail::META_SENT_AT, 'fields' => 'ID' ) ) ) : '—'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of wholesale customers.

			echo '<tr><td><strong>' . esc_html( EmailTemplates::slots()[ $slot ] ) . '</strong><br /><span class="description">' . esc_html( $info['when'] ) . '</span></td>';

			if ( null !== $template ) {
				echo '<td>' . esc_html__( 'Designed:', 'protech-wholesale' ) . ' <a href="' . esc_url( EmailComposer::edit_url( (string) $template['id'] ) ) . '">' . esc_html( (string) $template['name'] ) . '</a></td>';
				echo '<td>' . esc_html( $sent ) . '</td>';
				echo '<td><a href="' . esc_url( EmailComposer::edit_url( (string) $template['id'] ) ) . '">' . esc_html__( 'Edit design', 'protech-wholesale' ) . '</a> | <a href="' . esc_url( self::link( self::UNBIND_ACTION, $slot ) ) . '">' . esc_html__( 'Use built-in wording', 'protech-wholesale' ) . '</a></td>';
			} else {
				echo '<td>' . esc_html__( 'Built-in wording', 'protech-wholesale' ) . '</td>';
				echo '<td>' . esc_html( $sent ) . '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( self::link( self::DESIGN_ACTION, $slot ) ) . '">' . esc_html__( 'Design this email', 'protech-wholesale' ) . '</a></td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/** "Order emails": WooCommerce's own order emails, each either its default design or a template designed here. */
	public static function render_order_emails(): void {
		echo '<h2>' . esc_html__( 'Order emails', 'protech-wholesale' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'WooCommerce\'s own order emails. Each is either WooCommerce\'s default design or a template you have designed here, with the real order\'s items and totals.', 'protech-wholesale' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Email', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Wording', 'protech-wholesale' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( WcEmailSlots::slots() as $slot => $label ) {
			$template = EmailTemplates::for_slot( $slot );

			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';

			if ( null !== $template ) {
				echo '<td>' . esc_html__( 'Designed:', 'protech-wholesale' ) . ' <a href="' . esc_url( EmailComposer::edit_url( (string) $template['id'] ) ) . '">' . esc_html( (string) $template['name'] ) . '</a></td>';
				echo '<td><a href="' . esc_url( EmailComposer::edit_url( (string) $template['id'] ) ) . '">' . esc_html__( 'Edit design', 'protech-wholesale' ) . '</a> | <a href="' . esc_url( self::link( self::UNBIND_ACTION, $slot ) ) . '">' . esc_html__( 'Use the WooCommerce design', 'protech-wholesale' ) . '</a></td>';
			} else {
				echo '<td>' . esc_html__( 'The WooCommerce default design', 'protech-wholesale' ) . '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( self::link( self::DESIGN_ACTION, $slot ) ) . '">' . esc_html__( 'Design this email', 'protech-wholesale' ) . '</a></td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/** "Sent": past one-off messages, newest first, each with a way to send it again. */
	public static function render_sent(): void {
		echo '<h2>' . esc_html__( 'Sent', 'protech-wholesale' ) . '</h2>';

		$campaigns = Campaigns::recent( 20 );

		if ( empty( $campaigns ) ) {
			echo '<p>' . esc_html__( 'Nothing sent yet. Use Add new email to write and send one.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Message', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Sent', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'To', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Delivered / failed / left out', 'protech-wholesale' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $campaigns as $campaign ) {
			$id     = (string) $campaign['id'];
			$counts = Campaigns::progress( $id );

			echo '<tr>';
			echo '<td><a href="' . esc_url( MessagingTab::url( 'log', array( 'rule_id' => 'campaign:' . $id ) ) ) . '"><strong>' . esc_html( (string) $campaign['name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( human_time_diff( (int) $campaign['created_at'] ) . ' ' . __( 'ago', 'protech-wholesale' ) ) . '</td>';
			echo '<td>' . esc_html( Audience::describe( (array) $campaign['audience'] ) ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%d / %d / %d', $counts[ MessageLog::STATUS_SENT ], $counts[ MessageLog::STATUS_FAILED ], $counts[ MessageLog::STATUS_SKIPPED ] ) ) . '</td>';
			echo '<td><a href="' . esc_url( self::link( self::DUPLICATE_SEND_ACTION, $id ) ) . '">' . esc_html__( 'Duplicate and edit', 'protech-wholesale' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// -----------------------------------------------------------------
	// Actions.
	// -----------------------------------------------------------------

	private static function authorize( string $action ): string {
		// Not sanitize_key(): a WooCommerce order-email key carries a colon ("wc:customer_..."),
		// which sanitize_key() strips, breaking the nonce link() signed (the raw key, colon and
		// all). Safe either way: every caller looks $key up against a known, finite slot list.
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( $action . '_' . $key );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		return $key;
	}

	/**
	 * "Design this email": binds a template to the lifecycle email and opens it in the
	 * editor. Uses the ready-made starter for it (the one already in the library if
	 * there is one, otherwise a fresh copy), so nothing starts blank.
	 */
	public function handle_design(): void {
		$slot = self::authorize( self::DESIGN_ACTION );

		if ( WcEmailSlots::is_wc_slot( $slot ) ) {
			$this->handle_design_order_email( $slot );
			return;
		}

		$info = self::lifecycle()[ $slot ] ?? null;

		if ( null === $info ) {
			wp_safe_redirect( MessagingTab::url( 'emails', array( 'tab' => 'automatic' ) ) );
			exit;
		}

		$existing = EmailTemplates::for_slot( $slot );
		$id       = null !== $existing ? (string) $existing['id'] : '';

		if ( '' === $id ) {
			foreach ( EmailTemplates::all() as $template ) {
				if ( $info['starter'] === (string) ( $template['seeded'] ?? '' ) ) {
					$id = (string) $template['id'];
					break;
				}
			}
		}

		if ( '' === $id ) {
			$id = EmailTemplates::create_from_starter( $info['starter'] );
		}

		if ( '' !== $id ) {
			EmailTemplates::bind_slot( $id, $slot );
			Logger::info( sprintf( 'Lifecycle email "%s" switched to a designed template by admin #%d.', $slot, get_current_user_id() ) );
		}

		wp_safe_redirect( '' !== $id ? EmailComposer::edit_url( $id ) : MessagingTab::url( 'emails', array( 'tab' => 'automatic' ) ) );
		exit;
	}

	/**
	 * "Design this email" for a WooCommerce order email: no starter exists
	 * for these yet (see DECISIONS.md), so a fresh template opens with a
	 * sensible order-aware starting point instead of a blank canvas.
	 */
	private function handle_design_order_email( string $slot ): void {
		if ( ! array_key_exists( $slot, WcEmailSlots::slots() ) ) {
			wp_safe_redirect( MessagingTab::url( 'emails', array( 'tab' => 'automatic' ) ) );
			exit;
		}

		$existing = EmailTemplates::for_slot( $slot );
		$id       = null !== $existing ? (string) $existing['id'] : '';

		if ( '' === $id ) {
			$result = EmailTemplates::validate(
				array(
					'name'    => WcEmailSlots::slots()[ $slot ],
					'kind'    => EmailTemplates::KIND_TRANSACTIONAL,
					'slot'    => $slot,
					'subject' => __( 'Your order {order_number}', 'protech-wholesale' ),
					'blocks'  => array(
						array(
							'type'  => 'heading',
							'attrs' => array( 'text' => __( 'Thanks, {first_name}', 'protech-wholesale' ), 'size' => 24 ),
						),
						array(
							'type'  => 'text',
							'attrs' => array( 'html' => __( "Here's a summary of order {order_number}.", 'protech-wholesale' ) ),
						),
						array( 'type' => 'order_items' ),
						array( 'type' => 'order_totals' ),
					),
				)
			);

			$id = EmailTemplates::save( $result['template'] );
		}

		if ( '' !== $id ) {
			EmailTemplates::bind_slot( $id, $slot );
			Logger::info( sprintf( 'Order email "%s" switched to a designed template by admin #%d.', $slot, get_current_user_id() ) );
		}

		wp_safe_redirect( '' !== $id ? EmailComposer::edit_url( $id ) : MessagingTab::url( 'emails', array( 'tab' => 'automatic' ) ) );
		exit;
	}

	/** "Use built-in wording": unbinds the template, restoring the original email. */
	public function handle_unbind(): void {
		$slot     = self::authorize( self::UNBIND_ACTION );
		$template = EmailTemplates::for_slot( $slot );

		if ( null !== $template ) {
			EmailTemplates::bind_slot( (string) $template['id'], '' );
			Logger::info( sprintf( 'Lifecycle email "%s" returned to the built-in wording by admin #%d.', $slot, get_current_user_id() ) );
		}

		wp_safe_redirect( MessagingTab::url( 'emails', array( 'tab' => 'automatic' ) ) );
		exit;
	}

	/** "Duplicate and edit": opens Compose filled in with a past message, to change and send again. */
	public function handle_duplicate_send(): void {
		$id       = self::authorize( self::DUPLICATE_SEND_ACTION );
		$campaign = Campaigns::get( $id );

		if ( null === $campaign ) {
			wp_safe_redirect( MessagingTab::url( 'emails' ) );
			exit;
		}

		$audience                = (array) $campaign['audience'];
		$audience['recent_days'] = $audience['days'] ?? 30; // The form reads the "ordered in the last N days" number from its own field.

		MessagingTab::prefill_compose(
			array(
				'name'            => '',
				'channel'         => (string) $campaign['channel'],
				'service_message' => MessageLog::CATEGORY_TRANSACTIONAL === ( $campaign['category'] ?? '' ) ? '1' : '',
				'audience'        => $audience,
				'email'           => (array) ( $campaign['email'] ?? array() ),
				'sms'             => (array) ( $campaign['sms'] ?? array() ),
			)
		);

		wp_safe_redirect( MessagingTab::url( 'compose' ) );
		exit;
	}
}
