<?php
/**
 * The "Welcome to Protech Wholesale" email: how to log in, how displays and
 * cases work, what the quantity tiers unlock. For accounts that were
 * ordinary customers and get upgraded to wholesale, who never went through
 * an application (and so never got the "approved" email), and for anyone
 * an admin wants to re-welcome.
 *
 * Triggered three ways, all from WooCommerce → Wholesale → Customers:
 * "Send welcome email" on ticked rows, the Send/Resend link on a row, and a
 * checkbox on "Add existing customers to wholesale". A preview can be sent
 * to any address first. It goes out through MessageTransport like every
 * other message (Brevo when connected, the WooCommerce mailer otherwise),
 * is written to the message log, and stamps the customer so the table can
 * show who has been welcomed.
 *
 * It says "the password you already use": these accounts exist already, so
 * nothing here resets or creates a password. The one link that touches
 * passwords is the ordinary WooCommerce reset page.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WelcomeEmail
 */
class WelcomeEmail {

	public const META_SENT_AT = '_protech_wholesale_welcome_sent_at';
	public const RULE_ID      = 'welcome';

	public function register_hooks(): void {
		add_action( 'admin_post_protech_send_welcome', array( $this, 'handle_send_selected' ) );
		add_action( 'admin_post_protech_send_welcome_one', array( $this, 'handle_send_one' ) );
		add_action( 'admin_post_protech_send_welcome_preview', array( $this, 'handle_send_preview' ) );
	}

	public static function subject(): string {
		return (string) apply_filters( 'protech_wholesale_welcome_email_subject', __( 'Your Protech Sleeves wholesale account is ready', 'protech-wholesale' ) );
	}

	public static function heading(): string {
		return __( 'Welcome to Protech Wholesale', 'protech-wholesale' );
	}

	/** The wholesale login page: the portal page if there is one, else /wholesale/. */
	public static function login_url(): string {
		$page_id = SetupChecks::portal_page_id();
		$url     = $page_id ? get_permalink( $page_id ) : '';

		return $url ? (string) $url : home_url( '/wholesale/' );
	}

	/** The HTML body for $user_id (their name, their login email), before the WooCommerce header and footer. */
	public static function body_html( int $user_id ): string {
		$user    = get_userdata( $user_id );
		$context = MergeTags::context_for_customer( $user_id );

		$displays_per_case = max( 1, Settings::get_default_displays_per_case() );
		$volume_threshold  = Settings::get_volume_threshold_displays();

		$html = wc_get_template_html(
			'welcome-email.php',
			array(
				'first_name'        => (string) ( $context['first_name'] ?? '' ),
				'email'             => $user ? (string) $user->user_email : '',
				'login_url'         => self::login_url(),
				'lost_password_url' => wc_lostpassword_url(),
				'case_size'         => max( 1, Settings::get_default_case_size() ),
				'displays_per_case' => $displays_per_case,
				'case_packs'        => max( 1, Settings::get_default_case_size() ) * $displays_per_case,
				'volume_threshold'  => $volume_threshold,
				'threshold_cases'   => VolumePricing::format_quantity( $volume_threshold / $displays_per_case ),
				'bulk_cases'        => Settings::get_bulk_threshold_cases(),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);

		/**
		 * The welcome email's HTML body.
		 *
		 * @param string $html
		 * @param int    $user_id
		 */
		return (string) apply_filters( 'protech_wholesale_welcome_email_body', $html, $user_id );
	}

	/**
	 * Sends the welcome email.
	 *
	 * With $preview_to it is a preview: it goes to that address, filled in
	 * from $user_id's account (the admin's own, from the preview form), and
	 * leaves no "welcomed" stamp. Without it, it goes to the customer, and
	 * only if they are an approved wholesale customer with a usable address.
	 *
	 * @return array{ok: bool, error: string, recipient: string}
	 */
	public static function send( int $user_id, string $preview_to = '' ): array {
		$user    = get_userdata( $user_id );
		$preview = '' !== $preview_to;

		if ( ! $user ) {
			return array( 'ok' => false, 'error' => __( 'That account no longer exists.', 'protech-wholesale' ), 'recipient' => '' );
		}

		if ( ! $preview ) {
			if ( ! Roles::is_wholesale_customer( $user_id ) ) {
				return array( 'ok' => false, 'error' => __( 'Not an approved wholesale customer.', 'protech-wholesale' ), 'recipient' => $user->user_email );
			}

			$gate = SmsConsent::can_receive_email( $user_id, MessageLog::CATEGORY_TRANSACTIONAL );

			if ( ! $gate['ok'] ) {
				return array( 'ok' => false, 'error' => __( 'No valid email address on the account.', 'protech-wholesale' ), 'recipient' => $user->user_email );
			}
		}

		$to     = $preview ? $preview_to : $user->user_email;
		$log_id = MessageLog::enqueue(
			array(
				'user_id'   => $user_id,
				'channel'   => MessageLog::CHANNEL_EMAIL,
				'kind'      => $preview ? MessageLog::KIND_TEST : MessageLog::KIND_WELCOME,
				'category'  => MessageLog::CATEGORY_TRANSACTIONAL,
				'rule_id'   => self::RULE_ID,
				'anchor'    => 'welcome:' . microtime( true ),
				'recipient' => $to,
			)
		);

		if ( $log_id ) {
			MessageLog::claim( $log_id );
		}

		$result = MessageTransport::send_email( $user_id, $to, self::subject(), self::heading(), self::body_html( $user_id ), MessageLog::CATEGORY_TRANSACTIONAL, array( 'welcome' ) );

		if ( $log_id ) {
			MessageLog::finish(
				$log_id,
				$result['status'],
				array(
					'provider'    => $result['provider'],
					'provider_id' => $result['provider_id'],
					'recipient'   => $result['recipient'],
					'subject'     => $result['subject'],
					'error'       => $result['error'],
					'reason'      => $result['reason'],
				)
			);
		}

		$ok = 'sent' === $result['status'];

		if ( $ok && ! $preview ) {
			update_user_meta( $user_id, self::META_SENT_AT, time() );
		}

		return array(
			'ok'        => $ok,
			'error'     => $result['error'],
			'recipient' => '' !== $result['recipient'] ? $result['recipient'] : $to,
		);
	}

	// -----------------------------------------------------------------
	// Customers tab pieces.
	// -----------------------------------------------------------------

	/** The "Welcome" cell of a Customers-tab row: when it was sent, and a Send / Resend link. */
	public static function row_cell( \WP_User $user ): string {
		$sent_at = (int) get_user_meta( $user->ID, self::META_SENT_AT, true );
		$url     = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'protech_send_welcome_one',
					'user_id' => $user->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'protech_send_welcome_' . $user->ID
		);

		$status = $sent_at > 0
			/* translators: %s: date the welcome email was sent. */
			? sprintf( __( 'Sent %s', 'protech-wholesale' ), wp_date( get_option( 'date_format' ), $sent_at ) )
			: __( 'Not sent', 'protech-wholesale' );

		return '<td><span class="description">' . esc_html( $status ) . '</span><br /><a href="' . esc_url( $url ) . '">' . esc_html( $sent_at > 0 ? __( 'Resend', 'protech-wholesale' ) : __( 'Send', 'protech-wholesale' ) ) . '</a></td>';
	}

	/** The collapsed "Welcome email" box above the table: what it is, and a preview to any address. */
	public static function render_preview_box(): void {
		$me = wp_get_current_user();

		echo '<details class="protech-quickadd"><summary>' . esc_html__( 'Welcome email', 'protech-wholesale' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0.75em 0 1.5em;">';
		wp_nonce_field( 'protech_welcome_preview', 'protech_welcome_preview_nonce' );
		echo '<input type="hidden" name="action" value="protech_send_welcome_preview" />';
		echo '<p>' . esc_html__( 'Tells a customer how to log in, how displays and cases work, and what each quantity level unlocks. Send it to a customer from the Welcome column below, tick several and use "Send welcome email", or tick the box when you add existing customers.', 'protech-wholesale' ) . '</p>';
		echo '<p><label for="protech_welcome_preview_email"><strong>' . esc_html__( 'Send a preview to', 'protech-wholesale' ) . '</strong></label><br />';
		echo '<input type="email" id="protech_welcome_preview_email" name="preview_email" class="regular-text" placeholder="' . esc_attr( $me->user_email ) . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Send preview', 'protech-wholesale' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'The preview is filled in with your own name and email. Leave the address blank to send it to yourself.', 'protech-wholesale' ) . '</p>';
		echo '</form></details>';
	}

	/** The notice after a send, from the query args the handlers redirect with. */
	public static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, only selects fixed notice text.
		$result = isset( $_GET['protech_welcome'] ) ? sanitize_key( wp_unslash( $_GET['protech_welcome'] ) ) : '';
		$sent   = absint( $_GET['sent'] ?? 0 );
		$failed = absint( $_GET['failed'] ?? 0 );
		$to     = sanitize_email( wp_unslash( $_GET['to'] ?? '' ) );
		// phpcs:enable

		if ( '' === $result ) {
			return;
		}

		if ( 'preview' === $result ) {
			$ok  = $sent > 0;
			$msg = $ok
				/* translators: %s: email address. */
				? sprintf( __( 'Preview sent to %s.', 'protech-wholesale' ), $to )
				: __( 'The preview could not be sent. Check the address and the mail settings.', 'protech-wholesale' );
		} elseif ( 'none' === $result ) {
			$ok  = false;
			$msg = __( 'No customers were selected.', 'protech-wholesale' );
		} else {
			$ok  = 0 === $failed;
			$msg = sprintf(
				/* translators: %d: number of customers. */
				_n( 'Welcome email sent to %d customer.', 'Welcome email sent to %d customers.', $sent, 'protech-wholesale' ),
				$sent
			);

			if ( $failed > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: number of customers. */
					_n( '%d could not be sent; see the message log.', '%d could not be sent; see the message log.', $failed, 'protech-wholesale' ),
					$failed
				);
			}
		}

		echo '<div class="notice ' . ( $ok ? 'updated' : 'notice-warning' ) . '"><p>' . esc_html( $msg ) . '</p></div>';
	}

	// -----------------------------------------------------------------
	// Handlers.
	// -----------------------------------------------------------------

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=protech-wholesale&tab=customers' ) ) );
		exit;
	}

	private static function require_cap(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}
	}

	/**
	 * @param int[] $user_ids
	 */
	private static function send_to( array $user_ids ): void {
		$sent   = 0;
		$failed = 0;

		foreach ( $user_ids as $user_id ) {
			if ( self::send( $user_id )['ok'] ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		Logger::info( sprintf( 'Welcome email: %d sent, %d failed, by admin #%d.', $sent, $failed, get_current_user_id() ) );

		self::back( array( 'protech_welcome' => 'sent', 'sent' => $sent, 'failed' => $failed ) );
	}

	/** "Send welcome email" under the table, for the ticked rows (the same form as "Send message to selected"). */
	public function handle_send_selected(): void {
		check_admin_referer( 'protech_wholesale_customer_tiers', 'protech_wholesale_customer_tiers_nonce' );
		self::require_cap();

		$ids = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $_POST['protech_customer_ids'] ?? array() ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() sanitizes each value.

		if ( empty( $ids ) ) {
			self::back( array( 'protech_welcome' => 'none' ) );
		}

		self::send_to( $ids );
	}

	/** The Send / Resend link on one row. */
	public function handle_send_one(): void {
		$user_id = absint( $_GET['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( 'protech_send_welcome_' . $user_id );
		self::require_cap();

		self::send_to( array( $user_id ) );
	}

	/** The preview box: to the typed address, else the admin's own. */
	public function handle_send_preview(): void {
		check_admin_referer( 'protech_welcome_preview', 'protech_welcome_preview_nonce' );
		self::require_cap();

		$typed = sanitize_email( wp_unslash( $_POST['preview_email'] ?? '' ) );
		$to    = is_email( $typed ) ? $typed : (string) wp_get_current_user()->user_email;
		$ok    = self::send( get_current_user_id(), $to )['ok'];

		self::back( array( 'protech_welcome' => 'preview', 'sent' => $ok ? 1 : 0, 'to' => $to ) );
	}
}
