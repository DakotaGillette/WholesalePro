<?php
/**
 * Messaging → Compose: a one-off message to an audience, with a
 * review-before-send screen in between the form and the actual send.
 * Also holds the small rendering helpers (the merge-tag reference, the
 * template picker, the "Send a preview" box, and the preview-result and
 * skip-reason labels) that the Automations rule form reuses, since a rule's
 * email/SMS fields are the same kind of form as Compose's.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ComposeScreen
 */
class ComposeScreen {

	public function register_hooks(): void {
		add_action( 'admin_post_protech_message_customers', array( $this, 'handle_message_customers' ) );
		add_action( 'admin_post_protech_review_message', array( $this, 'handle_review_message' ) );
		add_action( 'admin_post_protech_edit_message', array( $this, 'handle_edit_message' ) );
		add_action( 'admin_post_protech_send_message', array( $this, 'handle_send_message' ) );
		add_action( 'admin_post_protech_send_test_message', array( $this, 'handle_send_test_message' ) );
	}

	public static function render(): void {
		$form_stash   = AdminStash::unstash( 'compose_form' );
		$review_stash = AdminStash::unstash( 'compose_review' );
		$test_stash   = AdminStash::unstash( 'compose_test' );

		if ( null !== $review_stash ) {
			self::render_review( $review_stash, $test_stash );
			return;
		}

		self::render_preview_results( $test_stash );

		if ( null !== $form_stash && ! empty( $form_stash['errors'] ) ) {
			echo '<div class="notice notice-error inline"><ul style="margin:0.5em 0 0 1.5em;list-style:disc;">';
			foreach ( $form_stash['errors'] as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		$input = $form_stash['input'] ?? $test_stash['input'] ?? null;

		if ( null === $input ) {
			$input = self::default_compose_input();
		}

		$audience_type = (string) ( $input['audience']['type'] ?? Audience::TYPE_ALL );
		$selected_ids  = (array) ( $input['audience']['user_ids'] ?? array() );
		$selected_names = array();

		foreach ( $selected_ids as $selected_id ) {
			$user = get_userdata( (int) $selected_id );
			if ( $user ) {
				$selected_names[] = $user->display_name . ' (' . $user->user_email . ')';
			}
		}

		$tiers          = Tiers::get_tier_labels();
		$channel        = (string) ( $input['channel'] ?? 'email' );
		$scope          = (string) ( $input['audience']['scope'] ?? Audience::SCOPE_WHOLESALE );
		$days           = (int) ( $input['audience']['days'] ?? 60 );
		$recent_days    = (int) ( $input['audience']['recent_days'] ?? 30 );
		$picked_product = ! empty( $input['audience']['product_id'] ) ? wc_get_product( (int) $input['audience']['product_id'] ) : null;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="protech-compose-form">
			<?php // The default action only ever leads to the review screen; the send is its own button there. ?>
			<input type="hidden" name="action" value="protech_review_message" />
			<?php wp_nonce_field( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' ); ?>

			<h2><?php esc_html_e( 'Audience', 'protech-wholesale' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="protech_audience_scope"><?php esc_html_e( 'Customers', 'protech-wholesale' ); ?></label></th>
					<td>
						<select id="protech_audience_scope" name="audience[scope]">
							<option value="<?php echo esc_attr( Audience::SCOPE_WHOLESALE ); ?>" <?php selected( $scope, Audience::SCOPE_WHOLESALE ); ?>><?php esc_html_e( 'Wholesale customers', 'protech-wholesale' ); ?></option>
							<option value="<?php echo esc_attr( Audience::SCOPE_RETAIL ); ?>" <?php selected( $scope, Audience::SCOPE_RETAIL ); ?>><?php esc_html_e( 'Retail customers', 'protech-wholesale' ); ?></option>
							<option value="<?php echo esc_attr( Audience::SCOPE_EVERYONE ); ?>" <?php selected( $scope, Audience::SCOPE_EVERYONE ); ?>><?php esc_html_e( 'Everyone (wholesale and retail)', 'protech-wholesale' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Retail customers are people with a shop account who are not wholesale accounts. Marketing email always carries an unsubscribe link and your address, and anyone who unsubscribed is left out. Texts only go to people who opted in to them.', 'protech-wholesale' ); ?></p>
						<p class="description"><?php esc_html_e( 'Tip: for a first email to retail customers, choose "Ordered in the last ... days". People who no longer remember you are the ones who mark email as spam, which hurts delivery of every email you send.', 'protech-wholesale' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Send to', 'protech-wholesale' ); ?></th>
					<td>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_ALL ); ?>" <?php checked( $audience_type, Audience::TYPE_ALL ); ?> /> <?php esc_html_e( 'All of them', 'protech-wholesale' ); ?></label></p>
						<p>
							<label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_RECENT ); ?>" <?php checked( $audience_type, Audience::TYPE_RECENT ); ?> /> <?php esc_html_e( 'Ordered in the last', 'protech-wholesale' ); ?> <input type="number" name="audience[recent_days]" min="1" value="<?php echo esc_attr( (string) $recent_days ); ?>" style="width:70px;" /> <?php esc_html_e( 'days', 'protech-wholesale' ); ?></label>
						</p>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_INACTIVE ); ?>" <?php checked( $audience_type, Audience::TYPE_INACTIVE ); ?> /> <?php esc_html_e( 'No order in', 'protech-wholesale' ); ?> <input type="number" name="audience[days]" min="1" value="<?php echo esc_attr( (string) $days ); ?>" style="width:70px;" /> <?php esc_html_e( 'days', 'protech-wholesale' ); ?></label></p>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_NEVER_ORDERED ); ?>" <?php checked( $audience_type, Audience::TYPE_NEVER_ORDERED ); ?> /> <?php esc_html_e( 'Have never ordered', 'protech-wholesale' ); ?></label></p>
						<p>
							<label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_BOUGHT_PRODUCT ); ?>" <?php checked( $audience_type, Audience::TYPE_BOUGHT_PRODUCT ); ?> /> <?php esc_html_e( 'Bought this product:', 'protech-wholesale' ); ?></label>
							<select class="wc-product-search" name="audience[product_id]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'protech-wholesale' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true" style="width:320px;">
								<?php if ( $picked_product instanceof WC_Product ) : ?>
									<option value="<?php echo esc_attr( (string) $picked_product->get_id() ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $picked_product->get_formatted_name() ) ); ?></option>
								<?php endif; ?>
							</select>
						</p>
						<p>
							<label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_TIER ); ?>" <?php checked( $audience_type, Audience::TYPE_TIER ); ?> /> <?php esc_html_e( 'Specific tiers (wholesale only):', 'protech-wholesale' ); ?></label>
							<?php foreach ( $tiers as $tier_slug => $tier_label ) : ?>
								<label style="margin-left:1em;"><input type="checkbox" name="audience[tiers][]" value="<?php echo esc_attr( $tier_slug ); ?>" <?php checked( in_array( $tier_slug, (array) ( $input['audience']['tiers'] ?? array() ), true ) ); ?> /> <?php echo esc_html( $tier_label ); ?></label>
							<?php endforeach; ?>
						</p>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_PREFERS_TEXT ); ?>" <?php checked( $audience_type, Audience::TYPE_PREFERS_TEXT ); ?> /> <?php esc_html_e( 'Wholesale customers who said they prefer texts, but haven\'t opted in', 'protech-wholesale' ); ?></label></p>
						<p>
							<label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_SELECTED ); ?>" <?php checked( $audience_type, Audience::TYPE_SELECTED ); ?> /> <?php esc_html_e( 'Selected wholesale customers', 'protech-wholesale' ); ?></label>
							<?php if ( ! empty( $selected_names ) ) : ?>
								<span class="description"> — <?php echo esc_html( implode( ', ', $selected_names ) ); ?></span>
							<?php else : ?>
								<span class="description"> — <?php esc_html_e( 'pick these from the Customers tab', 'protech-wholesale' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $selected_ids as $selected_id ) : ?>
								<input type="hidden" name="audience[user_ids][]" value="<?php echo esc_attr( (string) (int) $selected_id ); ?>" />
							<?php endforeach; ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Message', 'protech-wholesale' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Channel', 'protech-wholesale' ); ?></th>
					<td>
						<?php foreach ( array( 'email' => __( 'Email', 'protech-wholesale' ), 'sms' => __( 'SMS', 'protech-wholesale' ), 'both' => __( 'Both', 'protech-wholesale' ) ) as $value => $label ) : ?>
							<label style="margin-right:1em;"><input type="radio" name="channel" value="<?php echo esc_attr( $value ); ?>" <?php checked( $channel, $value ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Message type', 'protech-wholesale' ); ?></th>
					<td>
						<label><input type="checkbox" name="service_message" value="1" <?php checked( ! empty( $input['service_message'] ) ); ?> /> <?php esc_html_e( 'This is a service message, not marketing', 'protech-wholesale' ); ?></label>
						<p class="description"><?php esc_html_e( 'A service message has no unsubscribe footer and ignores marketing opt-out — reserve it for things like an account or order issue, not offers.', 'protech-wholesale' ); ?></p>
					</td>
				</tr>
			</table>

			<?php self::render_merge_tag_reference( '' ); ?>

			<h3><?php esc_html_e( 'Email', 'protech-wholesale' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php self::render_template_picker( (string) ( $input['email']['template_id'] ?? '' ) ); ?>
				<tr>
					<th><label for="protech_compose_subject"><?php esc_html_e( 'Subject', 'protech-wholesale' ); ?></label></th>
					<td><input type="text" id="protech_compose_subject" name="email[subject]" class="large-text protech-tag-target" value="<?php echo esc_attr( (string) ( $input['email']['subject'] ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="protech_compose_heading"><?php esc_html_e( 'Heading', 'protech-wholesale' ); ?></label></th>
					<td><input type="text" id="protech_compose_heading" name="email[heading]" class="large-text protech-tag-target" value="<?php echo esc_attr( (string) ( $input['email']['heading'] ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="protech_compose_body"><?php esc_html_e( 'Body', 'protech-wholesale' ); ?></label></th>
					<td><textarea id="protech_compose_body" name="email[body]" class="large-text protech-tag-target" rows="8"><?php echo esc_textarea( (string) ( $input['email']['body'] ?? '' ) ); ?></textarea></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'SMS', 'protech-wholesale' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="protech_compose_sms"><?php esc_html_e( 'Text', 'protech-wholesale' ); ?></label></th>
					<td><textarea id="protech_compose_sms" name="sms[body]" class="large-text protech-tag-target" rows="4"><?php echo esc_textarea( (string) ( $input['sms']['body'] ?? '' ) ); ?></textarea></td>
				</tr>
			</table>

			<?php self::render_preview_box( 'protech_send_test_message', $input ); ?>

			<p>
				<button type="submit" name="action" value="protech_review_message" class="button button-primary"><?php esc_html_e( 'Review and send', 'protech-wholesale' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function default_compose_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids = isset( $_GET['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $ids ) && isset( $_GET['sel'] ) ) {
			$stashed = get_transient( 'protech_wholesale_msg_selected_' . get_current_user_id() );
			$ids     = is_array( $stashed ) ? $stashed : array();
		}

		return array(
			'audience' => array(
				'type'     => empty( $ids ) ? Audience::TYPE_ALL : Audience::TYPE_SELECTED,
				'user_ids' => $ids,
			),
			'channel'  => 'email',
			'email'    => array( 'subject' => '', 'heading' => '', 'body' => '' ),
			'sms'      => array( 'body' => '' ),
		);
	}

	public function handle_message_customers(): void {
		check_admin_referer( 'protech_wholesale_customer_tiers', 'protech_wholesale_customer_tiers_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$ids = array_map( 'absint', (array) ( $_POST['protech_customer_ids'] ?? array() ) );
		$ids = array_values( array_filter( $ids, static fn( int $id ): bool => Roles::is_wholesale_customer( $id ) ) );

		set_transient( 'protech_wholesale_msg_selected_' . get_current_user_id(), $ids, 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( MessagingTab::url( 'compose', array( 'sel' => '1' ) ) );
		exit;
	}

	/**
	 * Checks the message and, if it is sendable, shows the review screen (who it
	 * goes to, who is left out, the email as it will look). Nothing is sent from
	 * here. Anything wrong sends the admin back to the form with what they typed.
	 */
	public function handle_review_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		self::stash_review_or_form( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Campaigns::create().

		wp_safe_redirect( MessagingTab::url( 'compose' ) );
		exit;
	}

	/** Back from the review screen to the form, with everything still typed. */
	public function handle_edit_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		AdminStash::stash( 'compose_form', array( 'input' => wp_unslash( $_POST ), 'errors' => array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shown back in escaped fields only.

		wp_safe_redirect( MessagingTab::url( 'compose' ) );
		exit;
	}

	/**
	 * Stores what the review screen needs, or, when the message is not sendable,
	 * what the form needs to show its errors.
	 *
	 * @param array<string, mixed> $input Raw form input.
	 */
	private static function stash_review_or_form( array $input ): void {
		$review = self::build_review( $input );

		if ( ! empty( $review['errors'] ) ) {
			AdminStash::stash( 'compose_form', array( 'input' => $input, 'errors' => $review['errors'] ) );
			return;
		}

		AdminStash::stash( 'compose_review', $review );
	}

	/**
	 * Who would get this message right now and who would be left out, the same
	 * gate a real send applies, without sending or saving anything.
	 *
	 * @param array<string, mixed> $input Raw form input.
	 * @return array<string, mixed>
	 */
	private static function build_review( array $input ): array {
		$created  = Campaigns::create( $input, get_current_user_id() );
		$campaign = $created['campaign'];
		$channels = 'both' === $campaign['channel'] ? array( 'email', 'sms' ) : array( (string) $campaign['channel'] );
		$category = (string) $campaign['category'];
		$user_ids = Audience::resolve( $campaign['audience'] );
		$sent_to  = array_fill_keys( $channels, 0 );
		$reasons  = array();

		foreach ( $user_ids as $user_id ) {
			foreach ( $channels as $channel ) {
				// Email is counted from the local unsubscribe record only: asking Brevo about every person in a big audience would time the page out, and each message is checked against Brevo again as it goes out.
				$gate = 'sms' === $channel ? SmsConsent::can_receive_sms( $user_id, $category ) : SmsConsent::can_receive_email( $user_id, $category, false );

				if ( $gate['ok'] ) {
					++$sent_to[ $channel ];
				} else {
					$reasons[ $gate['reason'] ] = ( $reasons[ $gate['reason'] ] ?? 0 ) + 1;
				}
			}
		}

		return array(
			'input'          => $input,
			'campaign'       => $campaign,
			'errors'         => $created['errors'],
			'audience_label' => Audience::describe( $campaign['audience'] ),
			'audience_total' => count( $user_ids ),
			'channels'       => $channels,
			'sent_to'        => $sent_to,
			'reasons'        => $reasons,
		);
	}

	/**
	 * The screen between "Review and send" and the send itself.
	 *
	 * @param array<string, mixed>      $review From build_review().
	 * @param array<string, mixed>|null $test   The result of a preview sent from this screen.
	 */
	private static function render_review( array $review, ?array $test ): void {
		$campaign = (array) $review['campaign'];
		$input    = (array) $review['input'];
		$category = (string) $campaign['category'];
		$channels = (array) $review['channels'];
		$total    = (int) array_sum( (array) $review['sent_to'] );
		$admin_id = get_current_user_id();
		$email    = (array) $campaign['email'];
		$template = '' !== (string) ( $email['template_id'] ?? '' ) ? EmailTemplates::get( (string) $email['template_id'] ) : null;

		self::render_preview_results( $test );

		echo '<h2>' . esc_html__( 'Review before sending', 'protech-wholesale' ) . '</h2>';

		// Who.
		echo '<div class="protech-review-box" style="max-width:820px;margin:1em 0;padding:1em 1.25em;background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . ( $total > 0 ? '#00a32a' : '#d63638' ) . ';">';
		echo '<p style="margin-top:0;font-size:15px;"><strong>' . esc_html( $review['audience_label'] ) . '</strong> ';
		/* translators: %d: number of customers in the audience. */
		echo '<span class="description">(' . esc_html( sprintf( _n( '%d customer', '%d customers', (int) $review['audience_total'], 'protech-wholesale' ), (int) $review['audience_total'] ) ) . ')</span></p>';

		$parts = array();

		foreach ( (array) $review['sent_to'] as $channel => $count ) {
			$parts[] = 'sms' === $channel
				/* translators: %d: number of text messages. */
				? sprintf( _n( '%d text message', '%d text messages', (int) $count, 'protech-wholesale' ), (int) $count )
				/* translators: %d: number of emails. */
				: sprintf( _n( '%d email', '%d emails', (int) $count, 'protech-wholesale' ), (int) $count );
		}

		if ( $total > 0 ) {
			/* translators: %s: for example "12 emails and 4 text messages". */
			echo '<p style="margin:0 0 .5em;">' . esc_html( sprintf( __( 'This will send %s.', 'protech-wholesale' ), implode( ' ' . __( 'and', 'protech-wholesale' ) . ' ', $parts ) ) ) . '</p>';
		} else {
			echo '<p style="margin:0 0 .5em;color:#b32d2e;"><strong>' . esc_html__( 'Nobody would receive this message, so there is nothing to send.', 'protech-wholesale' ) . '</strong></p>';
		}

		if ( ! empty( $review['reasons'] ) ) {
			$left_out = array();

			foreach ( (array) $review['reasons'] as $reason => $count ) {
				$left_out[] = sprintf( '%d %s', (int) $count, self::reason_label( (string) $reason ) );
			}

			/* translators: %s: comma-separated reasons, for example "3 unsubscribed, 1 no phone number". */
			echo '<p style="margin:0 0 .5em;">' . esc_html( sprintf( __( 'Left out: %s.', 'protech-wholesale' ), implode( ', ', $left_out ) ) ) . '</p>';
		}

		echo '<p class="description" style="margin:0;">';

		if ( MessageLog::CATEGORY_MARKETING === $category ) {
			esc_html_e( 'A marketing message: every email carries an unsubscribe link and the store address, and anyone who unsubscribed is left out automatically.', 'protech-wholesale' );
		} else {
			esc_html_e( 'A service message: it is sent even to people who unsubscribed from marketing, and carries no unsubscribe link. Use it only for an account or order matter.', 'protech-wholesale' );
		}

		echo '</p></div>';

		// The email as it will look.
		if ( in_array( 'email', $channels, true ) ) {
			$built = MessageTransport::content_email_preview( $admin_id, $email, $category );

			echo '<h3>' . esc_html__( 'The email', 'protech-wholesale' ) . '</h3>';

			if ( null === $built ) {
				echo '<p style="color:#b32d2e;">' . esc_html__( 'The email template for this message no longer exists.', 'protech-wholesale' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html(
					null !== $template
						/* translators: %s: template name. */
						? sprintf( __( 'Designed with the "%s" template. Personal details are filled in with your own account.', 'protech-wholesale' ), (string) $template['name'] )
						: __( 'Personal details are filled in with your own account.', 'protech-wholesale' )
				) . '</p>';
				echo '<p><strong>' . esc_html__( 'Subject:', 'protech-wholesale' ) . '</strong> ' . esc_html( $built['subject'] ) . '</p>';
				echo '<iframe sandbox="" title="' . esc_attr__( 'Email preview', 'protech-wholesale' ) . '" srcdoc="' . esc_attr( $built['html'] ) . '" style="width:100%;max-width:820px;height:560px;border:1px solid #c3c4c7;background:#fff;"></iframe>';
			}
		}

		// The text message as it will look.
		if ( in_array( 'sms', $channels, true ) ) {
			$context = MergeTags::context_for_customer( $admin_id );
			$text    = MessageTransport::finalize_sms_text( MergeTags::render( (string) ( $campaign['sms']['body'] ?? '' ), $context, 'text' ), $category );
			$size    = MergeTags::sms_segments( $text );

			echo '<h3>' . esc_html__( 'The text message', 'protech-wholesale' ) . '</h3>';
			echo '<p style="max-width:420px;padding:.75em 1em;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:12px;white-space:pre-wrap;">' . esc_html( $text ) . '</p>';
			/* translators: 1: number of characters, 2: number of text segments. */
			echo '<p class="description">' . esc_html( sprintf( __( '%1$d characters, sent as %2$d text segment(s).', 'protech-wholesale' ), (int) $size['chars'], (int) $size['segments'] ) ) . '</p>';
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="protech-review-form">
			<?php wp_nonce_field( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' ); ?>
			<input type="hidden" name="action" value="protech_edit_message" />
			<input type="hidden" name="from_review" value="1" />
			<?php self::hidden_fields( $input ); ?>

			<?php self::render_preview_box( 'protech_send_test_message', $input ); ?>

			<p>
				<button type="submit" name="action" value="protech_send_message" class="button button-primary button-large" <?php disabled( 0 === $total ); ?>>
					<?php
					echo esc_html(
						$total > 0
							/* translators: %s: for example "12 emails and 4 text messages". */
							? sprintf( __( 'Send %s now', 'protech-wholesale' ), implode( ' ' . __( 'and', 'protech-wholesale' ) . ' ', $parts ) )
							: __( 'Send', 'protech-wholesale' )
					);
					?>
				</button>
				<button type="submit" name="action" value="protech_edit_message" class="button"><?php esc_html_e( 'Back to edit', 'protech-wholesale' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Sending cannot be undone. Each person is checked again at the moment their message goes out.', 'protech-wholesale' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Every value in $data as a hidden input, nested names intact, so a form can
	 * carry a whole earlier submission forward. The nonce, action and the
	 * preview address fields belong to the form that carries it, not the data.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function hidden_fields( array $data, string $prefix = '' ): void {
		foreach ( $data as $key => $value ) {
			if ( '' === $prefix && in_array( (string) $key, array( 'action', 'protech_wholesale_compose_nonce', '_wp_http_referer', 'preview_email', 'preview_phone', 'from_review' ), true ) ) {
				continue;
			}

			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				self::hidden_fields( $value, $name );
				continue;
			}

			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
	}

	/**
	 * The Design row of the email form: a designed template, or the typed
	 * message below it. Shared with the Automations rule form.
	 */
	public static function render_template_picker( string $selected ): void {
		?>
		<tr>
			<th><label for="protech_email_template"><?php esc_html_e( 'Design', 'protech-wholesale' ); ?></label></th>
			<td>
				<select id="protech_email_template" name="email[template_id]">
					<option value=""><?php esc_html_e( 'Plain message (written below)', 'protech-wholesale' ); ?></option>
					<?php foreach ( EmailTemplates::choices() as $template_id => $template_name ) : ?>
						<option value="<?php echo esc_attr( (string) $template_id ); ?>" <?php selected( $selected, (string) $template_id ); ?>><?php echo esc_html( $template_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<a href="<?php echo esc_url( MessagingTab::url( 'templates' ) ); ?>"><?php esc_html_e( 'Manage templates', 'protech-wholesale' ); ?></a>
				<p class="description"><?php esc_html_e( 'A designed template brings its own layout and wording, so the Heading and Body below are ignored. A Subject typed below still replaces the template\'s own.', 'protech-wholesale' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function handle_send_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Campaigns::create( $input, get_current_user_id() );

		if ( ! empty( $result['errors'] ) ) {
			AdminStash::stash( 'compose_form', array( 'input' => $input, 'errors' => $result['errors'] ) );
			wp_safe_redirect( MessagingTab::url( 'compose' ) );
			exit;
		}

		$launch = Campaigns::launch( $result['campaign'] );

		wp_safe_redirect( MessagingTab::url( 'log', array( 'rule_id' => 'campaign:' . $result['campaign']['id'], 'queued' => $launch['queued'] ) ) );
		exit;
	}

	public function handle_send_test_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$channel  = in_array( $input['channel'] ?? '', array( 'email', 'sms', 'both' ), true ) ? $input['channel'] : 'email';
		$channels = 'both' === $channel ? array( 'email', 'sms' ) : array( $channel );

		AdminStash::stash( 'compose_test', array( 'results' => self::send_previews( $input, $channels ), 'input' => $input ) );

		// A preview sent from the review screen returns to it; from the form, to the form.
		if ( ! empty( $input['from_review'] ) ) {
			self::stash_review_or_form( $input );
		} else {
			AdminStash::stash( 'compose_form', array( 'input' => $input, 'errors' => array() ) );
		}

		wp_safe_redirect( MessagingTab::url( 'compose' ) );
		exit;
	}

	/**
	 * One preview per channel, to whoever the admin typed (else themselves).
	 * Shared with the Automations rule form's own "Send a preview" button.
	 *
	 * @param array<string, mixed> $content Campaigns::send_test() input, without a channel.
	 * @param string[]             $channels
	 * @return array<string, array{ok: bool, error: string, provider: string, recipient: string}>
	 */
	public static function send_previews( array $content, array $channels ): array {
		$results = array();

		foreach ( $channels as $channel ) {
			$results[ $channel ] = Campaigns::send_test( array_merge( $content, array( 'channel' => $channel ) ), get_current_user_id() );
		}

		return $results;
	}

	/**
	 * The "Send a preview" block, inside a compose or automation form: any
	 * address to send to, one button. $action is the admin-post action the
	 * button submits to; the form's own nonce covers it. The button carries the
	 * action as its own name and value: a `formaction` query string would lose to
	 * the form's hidden `action` field, because PHP lets POST beat GET.
	 *
	 * @param array<string, mixed> $input Last submitted values, so a second preview needs no retyping.
	 */
	public static function render_preview_box( string $action, array $input, bool $note_order_tags = false ): void {
		$me = wp_get_current_user();
		?>
		<div class="protech-preview-box" style="max-width:640px;margin:1.5em 0;padding:1em 1.25em;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #42649d;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Send a preview', 'protech-wholesale' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Sends this message as written to any address before you send it for real. Merge tags fill in with your own account.', 'protech-wholesale' ); ?>
				<?php if ( $note_order_tags ) : ?>
					<?php esc_html_e( 'Order details (order number, tracking) show as blank in a preview.', 'protech-wholesale' ); ?>
				<?php endif; ?>
			</p>
			<p>
				<label for="protech_preview_email"><strong><?php esc_html_e( 'Email address', 'protech-wholesale' ); ?></strong></label><br />
				<input type="email" id="protech_preview_email" name="preview_email" class="regular-text" value="<?php echo esc_attr( (string) ( $input['preview_email'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $me->user_email ); ?>" />
			</p>
			<p>
				<label for="protech_preview_phone"><strong><?php esc_html_e( 'Phone number for text messages', 'protech-wholesale' ); ?></strong></label><br />
				<input type="tel" id="protech_preview_phone" name="preview_phone" class="regular-text" value="<?php echo esc_attr( (string) ( $input['preview_phone'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Only used when the channel includes SMS', 'protech-wholesale' ); ?>" />
			</p>
			<p class="description"><?php esc_html_e( 'Leave the email blank to send to yourself.', 'protech-wholesale' ); ?></p>
			<button type="submit" name="action" value="<?php echo esc_attr( $action ); ?>" class="button button-secondary"><?php esc_html_e( 'Send preview', 'protech-wholesale' ); ?></button>
		</div>
		<?php
	}

	/**
	 * The green/red result line(s) after a preview send. Shared with the
	 * Automations rule form.
	 *
	 * @param array<string, mixed>|null $stash {results: array<string, array{ok: bool, error: string, recipient: string}>}
	 */
	public static function render_preview_results( ?array $stash ): void {
		if ( null === $stash || empty( $stash['results'] ) ) {
			return;
		}

		foreach ( $stash['results'] as $channel => $result ) {
			$label = 'sms' === $channel ? __( 'Text', 'protech-wholesale' ) : __( 'Email', 'protech-wholesale' );
			$class = $result['ok'] ? 'notice-success' : 'notice-error';
			$msg   = $result['ok']
				/* translators: 1: Email or Text, 2: where it was sent. */
				? sprintf( __( '%1$s preview sent to %2$s.', 'protech-wholesale' ), $label, $result['recipient'] )
				/* translators: 1: Email or Text, 2: error message. */
				: sprintf( __( '%1$s preview failed: %2$s', 'protech-wholesale' ), $label, '' !== $result['error'] ? $result['error'] : __( 'no address or number to send to.', 'protech-wholesale' ) );

			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * The {tag} chip row under a trigger's (or Compose's, with '') email
	 * fields. Shared with the Automations rule form.
	 */
	public static function render_merge_tag_reference( string $trigger ): void {
		echo '<p class="description"><strong>' . esc_html__( 'Merge tags:', 'protech-wholesale' ) . '</strong> ';
		$tags = array();
		foreach ( array_keys( MergeTags::all( $trigger ) ) as $tag ) {
			$tags[] = '<button type="button" class="button button-small protech-insert-tag" data-tag="{' . esc_attr( $tag ) . '}">{' . esc_html( $tag ) . '}</button>';
		}
		echo wp_kses_post( implode( ' ', $tags ) );
		echo '</p>';
	}

	/** The plain-language reason a candidate was left out of a preview or a review. Shared with the Automations rule form. */
	public static function reason_label( string $reason ): string {
		$labels = array(
			'window'              => __( 'not in the trigger window yet', 'protech-wholesale' ),
			'no_orders'           => __( 'no qualifying order', 'protech-wholesale' ),
			'has_orders'          => __( 'already ordered', 'protech-wholesale' ),
			'not_approved'        => __( 'no approval date on record', 'protech-wholesale' ),
			'tier'                => __( 'different tier', 'protech-wholesale' ),
			'already_sent'        => __( 'already sent', 'protech-wholesale' ),
			'frequency_cap'       => __( 'frequency cap', 'protech-wholesale' ),
			'no_phone'            => __( 'no phone number', 'protech-wholesale' ),
			'no_email'            => __( 'no email address', 'protech-wholesale' ),
			'no_consent'          => __( 'not opted in', 'protech-wholesale' ),
			'unsubscribed'        => __( 'unsubscribed', 'protech-wholesale' ),
			'consent_unverified'  => __( 'consent status unverified (Brevo unreachable)', 'protech-wholesale' ),
			'unsupported_trigger' => __( 'unsupported trigger', 'protech-wholesale' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
