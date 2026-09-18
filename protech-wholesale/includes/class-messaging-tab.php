<?php
/**
 * The "Messaging" tab of the WooCommerce → Wholesale admin screen — five
 * views (Automations, Compose, Log, Compliance, Settings). Every action
 * that changes something goes through admin-post.php and comes back as
 * a redirect: Approval::render_page() has already echoed the page's
 * <h1> by the time a tab's body renders, so nothing in here can send its
 * own redirect header. A brief per-admin transient ("stash") carries
 * validation errors and dry-run results across that redirect, the same
 * way WordPress core carries settings-saved state.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessagingTab
 */
class MessagingTab {

	private const STASH_TTL = 5 * MINUTE_IN_SECONDS;

	public function register_hooks(): void {
		add_action( 'admin_post_protech_save_automation', array( $this, 'handle_save_automation' ) );
		add_action( 'admin_post_protech_preview_automation', array( $this, 'handle_preview_automation' ) );
		add_action( 'admin_post_protech_toggle_automation', array( $this, 'handle_toggle_automation' ) );
		add_action( 'admin_post_protech_delete_automation', array( $this, 'handle_delete_automation' ) );
		add_action( 'admin_post_protech_run_automations_now', array( $this, 'handle_run_automations_now' ) );

		add_action( 'admin_post_protech_message_customers', array( $this, 'handle_message_customers' ) );
		add_action( 'admin_post_protech_preview_message', array( $this, 'handle_preview_message' ) );
		add_action( 'admin_post_protech_send_message', array( $this, 'handle_send_message' ) );
		add_action( 'admin_post_protech_send_test_message', array( $this, 'handle_send_test_message' ) );

		add_action( 'admin_post_protech_export_consent', array( $this, 'handle_export_consent' ) );
		add_action( 'admin_post_protech_test_brevo_connection', array( $this, 'handle_test_brevo_connection' ) );
	}

	public static function url( string $view, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'protech-wholesale', 'tab' => 'messaging', 'view' => $view ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_views(): array {
		return array(
			'automations' => __( 'Automations', 'protech-wholesale' ),
			'compose'     => __( 'Compose', 'protech-wholesale' ),
			'log'         => __( 'Log', 'protech-wholesale' ),
			'compliance'  => __( 'Compliance', 'protech-wholesale' ),
			'settings'    => __( 'Settings', 'protech-wholesale' ),
		);
	}

	private static function stash_key( string $name ): string {
		return 'protech_wholesale_msg_stash_' . $name . '_' . get_current_user_id();
	}

	private static function stash( string $name, array $data ): void {
		set_transient( self::stash_key( $name ), $data, self::STASH_TTL );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function unstash( string $name ): ?array {
		$key  = self::stash_key( $name );
		$data = get_transient( $key );
		delete_transient( $key );

		return is_array( $data ) ? $data : null;
	}

	// -----------------------------------------------------------------
	// Router.
	// -----------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$view  = sanitize_key( $_GET['view'] ?? 'automations' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$views = self::get_views();

		if ( ! isset( $views[ $view ] ) ) {
			$view = 'automations';
		}

		echo '<p>' . esc_html__( 'Send emails and texts to wholesale customers — automatically, on a rule, or on demand — through Brevo.', 'protech-wholesale' ) . '</p>';

		if ( ! MessagingSettings::enabled() && 'settings' !== $view ) {
			echo '<div class="notice notice-info inline"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the Settings view. */
					__( 'Automations are turned off — turn them on from <a href="%s">Settings</a> when you\'re ready. Compose (manual sends) works either way.', 'protech-wholesale' ),
					esc_url( self::url( 'settings' ) )
				)
			) . '</p></div>';
		}

		echo '<ul class="subsubsub">';
		$keys = array_keys( $views );
		foreach ( $views as $slug => $label ) {
			$sep = end( $keys ) === $slug ? '' : ' |';
			printf(
				'<li><a href="%s" class="%s">%s</a>%s</li>',
				esc_url( self::url( $slug ) ),
				$view === $slug ? 'current' : '',
				esc_html( $label ),
				$sep
			);
		}
		echo '</ul><br class="clear" />';

		switch ( $view ) {
			case 'compose':
				self::render_compose();
				break;
			case 'log':
				self::render_log();
				break;
			case 'compliance':
				self::render_compliance();
				break;
			case 'settings':
				self::render_settings();
				break;
			default:
				self::render_automations();
		}
	}

	// -----------------------------------------------------------------
	// Automations.
	// -----------------------------------------------------------------

	private static function render_automations(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) {
			self::render_automation_form();
			return;
		}

		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="updated notice"><p>' . esc_html__( 'Automation saved.', 'protech-wholesale' ) . '</p></div>';
		}

		if ( isset( $_GET['ran'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="updated notice"><p>' . esc_html__( 'Automations are running in the background — check the Log in a moment.', 'protech-wholesale' ) . '</p></div>';
		}

		$rules  = Automations::all();
		$labels = self::trigger_labels();

		if ( empty( $rules ) ) {
			echo '<p>' . esc_html__( 'No automation rules yet.', 'protech-wholesale' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach (
				array(
					__( 'Name', 'protech-wholesale' ),
					__( 'Trigger', 'protech-wholesale' ),
					__( 'Channel', 'protech-wholesale' ),
					__( 'Sent / failed / skipped', 'protech-wholesale' ),
					__( 'Last run', 'protech-wholesale' ),
					__( 'Enabled', 'protech-wholesale' ),
					'',
				) as $heading
			) {
				echo '<th>' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rules as $rule ) {
				$counts = MessageLog::counts_for( (string) $rule['id'] );
				echo '<tr>';
				echo '<td><a href="' . esc_url( self::url( 'automations', array( 'edit' => $rule['id'] ) ) ) . '"><strong>' . esc_html( $rule['name'] ) . '</strong></a></td>';
				echo '<td>' . esc_html( $labels[ $rule['trigger'] ] ?? $rule['trigger'] ) . '</td>';
				echo '<td>' . esc_html( ucfirst( (string) $rule['channel'] ) ) . '</td>';
				echo '<td>' . esc_html( sprintf( '%d / %d / %d', $counts[ MessageLog::STATUS_SENT ], $counts[ MessageLog::STATUS_FAILED ], $counts[ MessageLog::STATUS_SKIPPED ] ) ) . '</td>';
				echo '<td>' . esc_html( ! empty( $rule['last_run_at'] ) ? human_time_diff( (int) $rule['last_run_at'] ) . ' ' . __( 'ago', 'protech-wholesale' ) : '—' ) . '</td>';
				echo '<td>' . wp_kses_post( self::toggle_link( $rule ) ) . '</td>';
				echo '<td>' . wp_kses_post( self::delete_link( $rule ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Add a rule', 'protech-wholesale' ) . '</h3><p>';
		foreach ( $labels as $trigger => $label ) {
			printf( '<a class="button" href="%s">%s</a> ', esc_url( self::url( 'automations', array( 'new' => $trigger ) ) ), esc_html( $label ) );
		}
		echo '</p>';

		self::render_runner_status();
	}

	/**
	 * @return array<string, string>
	 */
	private static function trigger_labels(): array {
		return array(
			Automations::TRIGGER_REORDER_REMINDER => __( 'Reorder reminder', 'protech-wholesale' ),
			Automations::TRIGGER_WINBACK          => __( 'Win-back', 'protech-wholesale' ),
			Automations::TRIGGER_FIRST_ORDER      => __( 'First-order nudge', 'protech-wholesale' ),
			Automations::TRIGGER_ORDER_STATUS     => __( 'Order status', 'protech-wholesale' ),
		);
	}

	private static function toggle_link( array $rule ): string {
		$id  = (string) $rule['id'];
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'protech_toggle_automation', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'protech_toggle_automation_' . $id );

		return ! empty( $rule['enabled'] )
			? '<a href="' . esc_url( $url ) . '" class="button button-small">' . esc_html__( 'On — turn off', 'protech-wholesale' ) . '</a>'
			: '<a href="' . esc_url( $url ) . '" class="button button-small button-primary">' . esc_html__( 'Off — turn on', 'protech-wholesale' ) . '</a>';
	}

	private static function delete_link( array $rule ): string {
		$id  = (string) $rule['id'];
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'protech_delete_automation', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'protech_delete_automation_' . $id );

		return '<a href="' . esc_url( $url ) . '" class="protech-confirm-delete" style="color:#b32d2e;">' . esc_html__( 'Delete', 'protech-wholesale' ) . '</a>';
	}

	private static function render_runner_status(): void {
		$status = AutomationRunner::status();

		echo '<p class="description">';

		if ( $status['next_daily_run'] > 0 ) {
			printf(
				/* translators: %s: date/time. */
				esc_html__( 'Next automated run: %s. ', 'protech-wholesale' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['next_daily_run'] ) )
			);
		}

		printf(
			/* translators: %d: number of queued/sending messages. */
			esc_html( _n( '%d message currently queued.', '%d messages currently queued.', $status['pending'], 'protech-wholesale' ) ) . ' ',
			(int) $status['pending']
		);

		echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=protech-wholesale' ) ) . '">' . esc_html__( 'View in Scheduled Actions', 'protech-wholesale' ) . '</a>';

		if ( MessagingSettings::enabled() ) {
			$run_url = wp_nonce_url( add_query_arg( 'action', 'protech_run_automations_now', admin_url( 'admin-post.php' ) ), 'protech_run_automations_now' );
			echo ' &middot; <a href="' . esc_url( $run_url ) . '">' . esc_html__( 'Run automations now', 'protech-wholesale' ) . '</a>';
		}

		echo '</p>';
	}

	private static function render_automation_form(): void {
		$stash = self::unstash( 'automation_form' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_trigger = isset( $_GET['new'] ) ? sanitize_key( wp_unslash( $_GET['new'] ) ) : '';

		// A stash only belongs on the page it was created for — never let a
		// leftover stash from editing one rule bleed into another rule's
		// form if the admin navigated away before the redirect completed.
		if ( null !== $stash
			&& (string) ( $stash['input']['id'] ?? '' ) !== $editing_id
			&& ( '' !== $editing_id || (string) ( $stash['input']['trigger'] ?? '' ) !== $new_trigger )
		) {
			$stash = null;
		}

		if ( null !== $stash ) {
			$rule   = self::validate_preview( $stash['input'] )['rule'];
			$errors = $stash['errors'] ?? array();
		} elseif ( '' !== $editing_id ) {
			$rule = Automations::get( $editing_id );

			if ( ! $rule ) {
				echo '<p>' . esc_html__( 'That rule no longer exists.', 'protech-wholesale' ) . '</p>';
				return;
			}

			$errors = array();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$trigger = sanitize_key( wp_unslash( $_GET['new'] ?? '' ) );
			$presets = Automations::presets();
			$rule    = $presets[ $trigger ] ?? Automations::defaults( in_array( $trigger, Automations::TRIGGERS, true ) ? $trigger : Automations::TRIGGER_REORDER_REMINDER );
			$errors  = array();
		}

		$preview = self::unstash( 'automation_preview' );

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error inline"><ul style="margin:0.5em 0 0 1.5em;list-style:disc;">';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( null !== $preview ) {
			self::render_preview_summary( $preview );
		}

		$trigger  = (string) $rule['trigger'];
		$channels = Automations::channels( $rule );
		$tiers    = Tiers::get_tier_labels();
		$action_url = admin_url( 'admin-post.php' );
		?>
		<h2><?php echo $editing_id ? esc_html__( 'Edit automation', 'protech-wholesale' ) : esc_html__( 'New automation', 'protech-wholesale' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="protech-automation-form">
			<input type="hidden" name="action" value="protech_save_automation" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>" />
			<input type="hidden" name="trigger" value="<?php echo esc_attr( $trigger ); ?>" />
			<?php wp_nonce_field( 'protech_wholesale_save_automation', 'protech_wholesale_automation_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="protech_rule_name"><?php esc_html_e( 'Name', 'protech-wholesale' ); ?></label></th>
					<td><input type="text" id="protech_rule_name" name="name" class="regular-text" value="<?php echo esc_attr( (string) $rule['name'] ); ?>" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Trigger', 'protech-wholesale' ); ?></th>
					<td><?php echo esc_html( self::trigger_labels()[ $trigger ] ?? $trigger ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Enabled', 'protech-wholesale' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> /> <?php esc_html_e( 'Run this rule', 'protech-wholesale' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Restrict to tiers', 'protech-wholesale' ); ?></th>
					<td>
						<?php foreach ( $tiers as $tier_slug => $tier_label ) : ?>
							<label style="display:inline-block;margin-right:1em;"><input type="checkbox" name="tiers[]" value="<?php echo esc_attr( $tier_slug ); ?>" <?php checked( in_array( $tier_slug, (array) $rule['tiers'], true ) ); ?> /> <?php echo esc_html( $tier_label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Leave all unchecked to include every tier.', 'protech-wholesale' ); ?></p>
					</td>
				</tr>

				<?php self::render_trigger_params( $trigger, $rule['params'] ); ?>

				<tr>
					<th><?php esc_html_e( 'Channel', 'protech-wholesale' ); ?></th>
					<td>
						<?php foreach ( array( 'email' => __( 'Email', 'protech-wholesale' ), 'sms' => __( 'SMS', 'protech-wholesale' ), 'both' => __( 'Both', 'protech-wholesale' ) ) as $value => $label ) : ?>
							<label style="margin-right:1em;"><input type="radio" name="channel" value="<?php echo esc_attr( $value ); ?>" <?php checked( $rule['channel'], $value ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<?php self::render_merge_tag_reference( $trigger ); ?>

			<h3><?php esc_html_e( 'Email', 'protech-wholesale' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="protech_email_subject"><?php esc_html_e( 'Subject', 'protech-wholesale' ); ?></label></th>
					<td><input type="text" id="protech_email_subject" name="email[subject]" class="large-text protech-tag-target" value="<?php echo esc_attr( (string) $rule['email']['subject'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="protech_email_heading"><?php esc_html_e( 'Heading', 'protech-wholesale' ); ?></label></th>
					<td><input type="text" id="protech_email_heading" name="email[heading]" class="large-text protech-tag-target" value="<?php echo esc_attr( (string) $rule['email']['heading'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="protech_email_body"><?php esc_html_e( 'Body', 'protech-wholesale' ); ?></label></th>
					<td><textarea id="protech_email_body" name="email[body]" class="large-text protech-tag-target" rows="8"><?php echo esc_textarea( (string) $rule['email']['body'] ); ?></textarea></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'SMS', 'protech-wholesale' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="protech_sms_body"><?php esc_html_e( 'Text', 'protech-wholesale' ); ?></label></th>
					<td>
						<textarea id="protech_sms_body" name="sms[body]" class="large-text protech-tag-target" rows="4"><?php echo esc_textarea( (string) $rule['sms']['body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'The brand prefix and, for marketing rules, "Reply STOP to opt out" are added automatically.', 'protech-wholesale' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save rule', 'protech-wholesale' ); ?></button>
				<button type="submit" formaction="<?php echo esc_url( add_query_arg( 'action', 'protech_preview_automation', admin_url( 'admin-post.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Preview recipients', 'protech-wholesale' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url( 'automations' ) ); ?>"><?php esc_html_e( 'Cancel', 'protech-wholesale' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function render_trigger_params( string $trigger, array $params ): void {
		if ( in_array( $trigger, array( Automations::TRIGGER_REORDER_REMINDER, Automations::TRIGGER_WINBACK, Automations::TRIGGER_FIRST_ORDER ), true ) ) {
			?>
			<tr>
				<th><label for="protech_params_days"><?php esc_html_e( 'Days', 'protech-wholesale' ); ?></label></th>
				<td><input type="number" id="protech_params_days" name="params[days]" min="1" max="365" value="<?php echo esc_attr( (string) ( $params['days'] ?? 30 ) ); ?>" style="width:90px;" /></td>
			</tr>
			<?php
		}

		if ( Automations::TRIGGER_WINBACK === $trigger ) {
			?>
			<tr>
				<th><label for="protech_params_max_repeats"><?php esc_html_e( 'Repeat up to', 'protech-wholesale' ); ?></label></th>
				<td><input type="number" id="protech_params_max_repeats" name="params[max_repeats]" min="1" max="12" value="<?php echo esc_attr( (string) ( $params['max_repeats'] ?? 3 ) ); ?>" style="width:90px;" /> <?php esc_html_e( 'times, every "Days" period', 'protech-wholesale' ); ?></td>
			</tr>
			<?php
		}

		if ( Automations::TRIGGER_ORDER_STATUS === $trigger ) {
			$statuses = wc_get_order_statuses();
			?>
			<tr>
				<th><label for="protech_params_status"><?php esc_html_e( 'When an order becomes', 'protech-wholesale' ); ?></label></th>
				<td>
					<select id="protech_params_status" name="params[status]">
						<?php foreach ( $statuses as $key => $label ) : ?>
							<?php $slug = preg_replace( '/^wc-/', '', $key ); ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $params['status'] ?? '', $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="protech_params_delay"><?php esc_html_e( 'Delay', 'protech-wholesale' ); ?></label></th>
				<td><input type="number" id="protech_params_delay" name="params[delay_minutes]" min="0" max="1440" value="<?php echo esc_attr( (string) ( $params['delay_minutes'] ?? 10 ) ); ?>" style="width:90px;" /> <?php esc_html_e( 'minutes (lets tracking info catch up before sending)', 'protech-wholesale' ); ?></td>
			</tr>
			<?php
		}
	}

	private static function render_merge_tag_reference( string $trigger ): void {
		echo '<p class="description"><strong>' . esc_html__( 'Merge tags:', 'protech-wholesale' ) . '</strong> ';
		$tags = array();
		foreach ( array_keys( MergeTags::all( $trigger ) ) as $tag ) {
			$tags[] = '<button type="button" class="button button-small protech-insert-tag" data-tag="{' . esc_attr( $tag ) . '}">{' . esc_html( $tag ) . '}</button>';
		}
		echo wp_kses_post( implode( ' ', $tags ) );
		echo '</p>';
	}

	/**
	 * @param array{ok: int, reasons: array<string, int>, audience_label?: string}|array<string,mixed> $preview
	 */
	private static function render_preview_summary( array $preview ): void {
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Preview:', 'protech-wholesale' ) . '</strong> ';

		printf(
			/* translators: %d: number of recipients. */
			esc_html( _n( 'would send to %d recipient right now.', 'would send to %d recipients right now.', (int) $preview['ok'], 'protech-wholesale' ) ),
			(int) $preview['ok']
		);

		if ( ! empty( $preview['reasons'] ) ) {
			$parts = array();
			foreach ( $preview['reasons'] as $reason => $count ) {
				$parts[] = sprintf( '%d %s', $count, self::reason_label( (string) $reason ) );
			}
			echo ' ' . esc_html( sprintf( /* translators: %s: comma-separated skip reasons. */ __( 'Skipped: %s.', 'protech-wholesale' ), implode( ', ', $parts ) ) );
		}

		echo '</p></div>';
	}

	private static function reason_label( string $reason ): string {
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

	/**
	 * @param array<string, mixed> $input Raw $_POST.
	 * @return array{rule: array<string, mixed>, errors: string[], warnings: string[]}
	 */
	private static function validate_preview( array $input ): array {
		return Automations::validate( $input );
	}

	public function handle_save_automation(): void {
		check_admin_referer( 'protech_wholesale_save_automation', 'protech_wholesale_automation_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Automations::validate().
		$result = Automations::validate( $input );

		if ( ! empty( $result['errors'] ) ) {
			self::stash( 'automation_form', array( 'input' => $input, 'errors' => $result['errors'] ) );
			$back = '' !== (string) ( $input['id'] ?? '' )
				? self::url( 'automations', array( 'edit' => $input['id'] ) )
				: self::url( 'automations', array( 'new' => $result['rule']['trigger'] ) );
			wp_safe_redirect( $back );
			exit;
		}

		Automations::save( $result['rule'] );
		Logger::info( sprintf( 'Automation rule "%s" saved by admin #%d.', $result['rule']['name'], get_current_user_id() ) );

		wp_safe_redirect( add_query_arg( 'saved', '1', self::url( 'automations' ) ) );
		exit;
	}

	public function handle_preview_automation(): void {
		check_admin_referer( 'protech_wholesale_save_automation', 'protech_wholesale_automation_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Automations::validate( $input );
		$back   = '' !== (string) ( $input['id'] ?? '' )
			? self::url( 'automations', array( 'edit' => $input['id'] ) )
			: self::url( 'automations', array( 'new' => $result['rule']['trigger'] ) );

		if ( ! empty( $result['errors'] ) ) {
			self::stash( 'automation_form', array( 'input' => $input, 'errors' => $result['errors'] ) );
			wp_safe_redirect( $back );
			exit;
		}

		$user_ids   = array_map( 'intval', get_users( array( 'role' => Roles::CUSTOMER, 'fields' => 'ID' ) ) );
		$candidates = Automations::candidates( $result['rule'], $user_ids, time(), true );

		$ok      = 0;
		$reasons = array();

		foreach ( $candidates as $candidate ) {
			if ( $candidate['ok'] ) {
				++$ok;
			} else {
				$reasons[ $candidate['reason'] ] = ( $reasons[ $candidate['reason'] ] ?? 0 ) + 1;
			}
		}

		self::stash( 'automation_form', array( 'input' => $input, 'errors' => array() ) );
		self::stash( 'automation_preview', array( 'ok' => $ok, 'reasons' => $reasons ) );

		wp_safe_redirect( $back );
		exit;
	}

	public function handle_toggle_automation(): void {
		$id = sanitize_key( wp_unslash( $_GET['id'] ?? '' ) );
		check_admin_referer( 'protech_toggle_automation_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$rule = Automations::get( $id );

		if ( $rule ) {
			Automations::set_enabled( $id, empty( $rule['enabled'] ) );
			Logger::info( sprintf( 'Automation rule "%s" %s by admin #%d.', $rule['name'], empty( $rule['enabled'] ) ? 'enabled' : 'disabled', get_current_user_id() ) );
		}

		wp_safe_redirect( self::url( 'automations' ) );
		exit;
	}

	public function handle_delete_automation(): void {
		$id = sanitize_key( wp_unslash( $_GET['id'] ?? '' ) );
		check_admin_referer( 'protech_delete_automation_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		Automations::delete( $id );
		Logger::info( sprintf( 'Automation rule #%s deleted by admin #%d.', $id, get_current_user_id() ) );

		wp_safe_redirect( self::url( 'automations' ) );
		exit;
	}

	public function handle_run_automations_now(): void {
		check_admin_referer( 'protech_run_automations_now' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		AutomationRunner::run_now();

		wp_safe_redirect( add_query_arg( 'ran', '1', self::url( 'automations' ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Compose.
	// -----------------------------------------------------------------

	private static function render_compose(): void {
		$form_stash    = self::unstash( 'compose_form' );
		$preview_stash = self::unstash( 'compose_preview' );
		$test_stash    = self::unstash( 'compose_test' );

		if ( null !== $test_stash ) {
			$result = $test_stash['result'];
			$class  = $result['ok'] ? 'notice-success' : 'notice-error';
			$msg    = $result['ok']
				? __( 'Test sent.', 'protech-wholesale' )
				: sprintf( /* translators: %s: error message. */ __( 'Test failed: %s', 'protech-wholesale' ), $result['error'] );
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( null !== $preview_stash ) {
			self::render_preview_summary( $preview_stash );
			echo '<p class="description">' . esc_html( $preview_stash['audience_label'] ?? '' ) . '</p>';
		}

		if ( null !== $form_stash && ! empty( $form_stash['errors'] ) ) {
			echo '<div class="notice notice-error inline"><ul style="margin:0.5em 0 0 1.5em;list-style:disc;">';
			foreach ( $form_stash['errors'] as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		$input = $form_stash['input'] ?? $preview_stash['input'] ?? $test_stash['input'] ?? null;

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

		$tiers   = Tiers::get_tier_labels();
		$channel = (string) ( $input['channel'] ?? 'email' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="protech-compose-form">
			<input type="hidden" name="action" value="protech_send_message" />
			<?php wp_nonce_field( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' ); ?>

			<h2><?php esc_html_e( 'Audience', 'protech-wholesale' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Send to', 'protech-wholesale' ); ?></th>
					<td>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_ALL ); ?>" <?php checked( $audience_type, Audience::TYPE_ALL ); ?> /> <?php esc_html_e( 'All wholesale customers', 'protech-wholesale' ); ?></label></p>
						<p>
							<label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_TIER ); ?>" <?php checked( $audience_type, Audience::TYPE_TIER ); ?> /> <?php esc_html_e( 'Specific tiers:', 'protech-wholesale' ); ?></label>
							<?php foreach ( $tiers as $tier_slug => $tier_label ) : ?>
								<label style="margin-left:1em;"><input type="checkbox" name="audience[tiers][]" value="<?php echo esc_attr( $tier_slug ); ?>" <?php checked( in_array( $tier_slug, (array) ( $input['audience']['tiers'] ?? array() ), true ) ); ?> /> <?php echo esc_html( $tier_label ); ?></label>
							<?php endforeach; ?>
						</p>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_INACTIVE ); ?>" <?php checked( $audience_type, Audience::TYPE_INACTIVE ); ?> /> <?php esc_html_e( 'No order in', 'protech-wholesale' ); ?> <input type="number" name="audience[days]" min="1" value="<?php echo esc_attr( (string) ( $input['audience']['days'] ?? 60 ) ); ?>" style="width:70px;" /> <?php esc_html_e( 'days', 'protech-wholesale' ); ?></label></p>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_NEVER_ORDERED ); ?>" <?php checked( $audience_type, Audience::TYPE_NEVER_ORDERED ); ?> /> <?php esc_html_e( 'Approved but never ordered', 'protech-wholesale' ); ?></label></p>
						<p><label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_PREFERS_TEXT ); ?>" <?php checked( $audience_type, Audience::TYPE_PREFERS_TEXT ); ?> /> <?php esc_html_e( 'Said they prefer texts, but haven\'t opted in', 'protech-wholesale' ); ?></label></p>
						<p>
							<label><input type="radio" name="audience[type]" value="<?php echo esc_attr( Audience::TYPE_SELECTED ); ?>" <?php checked( $audience_type, Audience::TYPE_SELECTED ); ?> /> <?php esc_html_e( 'Selected customers', 'protech-wholesale' ); ?></label>
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
				<tr>
					<th><label for="protech_compose_test_phone"><?php esc_html_e( 'Test phone (optional)', 'protech-wholesale' ); ?></label></th>
					<td><input type="tel" id="protech_compose_test_phone" name="test_phone" placeholder="<?php esc_attr_e( 'Only used by "Send test to me"', 'protech-wholesale' ); ?>" /></td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary protech-confirm-send"><?php esc_html_e( 'Send', 'protech-wholesale' ); ?></button>
				<button type="submit" formaction="<?php echo esc_url( add_query_arg( 'action', 'protech_preview_message', admin_url( 'admin-post.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Preview recipients', 'protech-wholesale' ); ?></button>
				<button type="submit" formaction="<?php echo esc_url( add_query_arg( 'action', 'protech_send_test_message', admin_url( 'admin-post.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Send test to me', 'protech-wholesale' ); ?></button>
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

		wp_safe_redirect( self::url( 'compose', array( 'sel' => '1' ) ) );
		exit;
	}

	public function handle_preview_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$audience = Audience::normalize( (array) ( $input['audience'] ?? array() ) );
		$user_ids = Audience::resolve( $audience );
		$channel  = in_array( $input['channel'] ?? '', array( 'email', 'sms', 'both' ), true ) ? $input['channel'] : 'email';
		$channels = 'both' === $channel ? array( 'email', 'sms' ) : array( $channel );
		$category = ! empty( $input['service_message'] ) ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;

		$ok      = 0;
		$reasons = array();

		foreach ( $user_ids as $user_id ) {
			foreach ( $channels as $ch ) {
				$gate = 'sms' === $ch ? SmsConsent::can_receive_sms( $user_id, $category ) : SmsConsent::can_receive_email( $user_id, $category );

				if ( $gate['ok'] ) {
					++$ok;
				} else {
					$reasons[ $gate['reason'] ] = ( $reasons[ $gate['reason'] ] ?? 0 ) + 1;
				}
			}
		}

		self::stash( 'compose_form', array( 'input' => $input, 'errors' => array() ) );
		self::stash( 'compose_preview', array( 'ok' => $ok, 'reasons' => $reasons, 'audience_label' => Audience::describe( $audience ), 'input' => $input ) );

		wp_safe_redirect( self::url( 'compose' ) );
		exit;
	}

	public function handle_send_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Campaigns::create( $input, get_current_user_id() );

		if ( ! empty( $result['errors'] ) ) {
			self::stash( 'compose_form', array( 'input' => $input, 'errors' => $result['errors'] ) );
			wp_safe_redirect( self::url( 'compose' ) );
			exit;
		}

		$launch = Campaigns::launch( $result['campaign'] );

		wp_safe_redirect( self::url( 'log', array( 'rule_id' => 'campaign:' . $result['campaign']['id'], 'queued' => $launch['queued'] ) ) );
		exit;
	}

	public function handle_send_test_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Campaigns::send_test( $input, get_current_user_id() );

		self::stash( 'compose_form', array( 'input' => $input, 'errors' => array() ) );
		self::stash( 'compose_test', array( 'result' => $result, 'input' => $input ) );

		wp_safe_redirect( self::url( 'compose' ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Log.
	// -----------------------------------------------------------------

	private static function render_log(): void {
		if ( ! MessageLog::table_exists() ) {
			echo '<p>' . esc_html__( 'The message log table has not been created yet — it is created automatically on the next page load.', 'protech-wholesale' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		if ( isset( $_GET['queued'] ) ) {
			printf(
				'<div class="updated notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of messages queued. */
						_n( '%d message queued for delivery.', '%d messages queued for delivery.', (int) $_GET['queued'], 'protech-wholesale' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						(int) $_GET['queued'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					)
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'channel' => sanitize_key( wp_unslash( $_GET['channel'] ?? '' ) ),
			'status'  => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
			'rule_id' => sanitize_text_field( wp_unslash( $_GET['rule_id'] ?? '' ) ),
		);
		$filters = array_filter( $filters, static fn( $v ) => '' !== $v );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 25;

		$result = MessageLog::query( $filters, $page, $per_page );

		echo '<form method="get" style="margin-bottom:1em;">';
		echo '<input type="hidden" name="page" value="protech-wholesale" /><input type="hidden" name="tab" value="messaging" /><input type="hidden" name="view" value="log" />';
		echo '<select name="channel"><option value="">' . esc_html__( 'All channels', 'protech-wholesale' ) . '</option>';
		foreach ( array( 'email' => __( 'Email', 'protech-wholesale' ), 'sms' => __( 'SMS', 'protech-wholesale' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['channel'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'protech-wholesale' ) . '</option>';
		foreach ( array( MessageLog::STATUS_QUEUED, MessageLog::STATUS_SENDING, MessageLog::STATUS_SENT, MessageLog::STATUS_FAILED, MessageLog::STATUS_SKIPPED ) as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['status'] ?? '', $value, false ) . '>' . esc_html( ucfirst( $value ) ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'protech-wholesale' ), '', '', false );
		echo '</form>';

		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Date', 'protech-wholesale' ),
				__( 'Customer', 'protech-wholesale' ),
				__( 'Channel', 'protech-wholesale' ),
				__( 'Rule', 'protech-wholesale' ),
				__( 'Status', 'protech-wholesale' ),
				__( 'Detail', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : ( '#' . $row['user_id'] ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['channel'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['rule_id'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['error'] ?: $row['reason'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / $per_page );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) );
			echo '</div></div>';
		}
	}

	// -----------------------------------------------------------------
	// Compliance.
	// -----------------------------------------------------------------

	private static function render_compliance(): void {
		$wording = SmsConsent::wording_variants();

		echo '<h2>' . esc_html__( 'SMS opt-in wording', 'protech-wholesale' ) . '</h2>';
		echo '<table class="widefat" style="max-width:800px;"><tbody>';
		echo '<tr><th style="width:180px;">' . esc_html__( 'Order-update texts', 'protech-wholesale' ) . '</th><td>' . esc_html( $wording['transactional'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Marketing texts', 'protech-wholesale' ) . '</th><td>' . esc_html( $wording['marketing'] ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: 1: application form link, 2: notifications preferences description. */
				__( 'Shown at signup on the <a href="%1$s" target="_blank" rel="noopener">wholesale application form</a>, and self-service under My Account &rarr; Notifications for an existing customer.', 'protech-wholesale' ),
				esc_url( home_url( '/wholesale-application' ) )
			)
		) . '</p>';

		echo '<h2>' . esc_html__( 'Message types configured', 'protech-wholesale' ) . '</h2>';
		$rules = Automations::enabled();

		if ( empty( $rules ) ) {
			echo '<p>' . esc_html__( 'No automation rules are enabled yet.', 'protech-wholesale' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:800px;"><thead><tr><th>' . esc_html__( 'Rule', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Category', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Sample SMS', 'protech-wholesale' ) . '</th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				$sample = ! empty( $rule['sms']['body'] ) ? MessageTransport::finalize_sms_text( $rule['sms']['body'], $rule['category'] ) : '—';
				echo '<tr><td>' . esc_html( $rule['name'] ) . '</td><td>' . esc_html( $rule['category'] ) . '</td><td>' . esc_html( $sample ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Consent on file', 'protech-wholesale' ) . '</h2>';
		$customers = get_users( array( 'role' => Roles::CUSTOMER, 'fields' => 'ID' ) );
		$marketing = $transactional = $email_optout = 0;

		foreach ( $customers as $id ) {
			$state = SmsConsent::state( (int) $id );
			if ( 'yes' === $state['sms_marketing'] ) {
				++$marketing;
			}
			if ( 'yes' === $state['sms_transactional'] ) {
				++$transactional;
			}
			if ( 'no' === $state['email_marketing'] ) {
				++$email_optout;
			}
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: customers consented to marketing SMS, 2: consented to transactional SMS, 3: unsubscribed from marketing email, 4: total wholesale customers. */
					__( '%1$d consented to marketing texts, %2$d to order-update texts, %3$d unsubscribed from marketing email — out of %4$d wholesale customers.', 'protech-wholesale' ),
					$marketing,
					$transactional,
					$email_optout,
					count( $customers )
				)
			)
		);

		$export_url = wp_nonce_url( add_query_arg( 'action', 'protech_export_consent', admin_url( 'admin-post.php' ) ), 'protech_export_consent' );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download consent records (CSV)', 'protech-wholesale' ) . '</a></p>';

		$brevo_check     = SetupChecks::brevo_connected();
		$privacy_check   = SetupChecks::privacy_policy_mentions_sms();

		echo '<h2>' . esc_html__( 'Before submitting to Brevo for toll-free verification', 'protech-wholesale' ) . '</h2><ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . ( false === $brevo_check ? '⚠️ ' : '✅ ' ) . esc_html__( 'Brevo is connected.', 'protech-wholesale' ) . '</li>';
		echo '<li>' . ( false === $privacy_check ? '⚠️ ' : '✅ ' ) . esc_html__( 'Your privacy policy and terms mention SMS, message frequency, rates, and STOP/HELP.', 'protech-wholesale' ) . '</li>';
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Suggested privacy policy / terms text', 'protech-wholesale' ) . '</h3>';
		echo '<textarea readonly rows="10" style="width:100%;max-width:800px;" onclick="this.select();">' . esc_textarea( self::suggested_privacy_text() ) . '</textarea>';
	}

	private static function suggested_privacy_text(): string {
		$brand = MessagingSettings::brand();

		return sprintf(
			/* translators: %s: brand/site name. */
			__(
				"SMS Terms: By providing your phone number and opting in, you agree to receive order-update and/or marketing text messages from %1\$s. Message frequency varies. Message and data rates may apply. Reply STOP to opt out at any time, or HELP for help. Your mobile information will not be shared with third parties or affiliates for marketing or promotional purposes. Consent to receive texts is not a condition of any purchase.",
				'protech-wholesale'
			),
			$brand
		);
	}

	// -----------------------------------------------------------------
	// Settings.
	// -----------------------------------------------------------------

	private static function render_settings(): void {
		$settings = new MessagingSettings();

		if ( isset( $_POST['protech_wholesale_msg_settings_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_msg_settings_nonce'] ) ), 'protech_wholesale_save_msg_settings' )
		) {
			woocommerce_update_options( $settings->get_fields() );
			echo '<div class="updated notice"><p>' . esc_html__( 'Settings saved.', 'protech-wholesale' ) . '</p></div>';
			Logger::info( 'Messaging settings updated by user #' . get_current_user_id() );
		}

		$test = self::unstash( 'brevo_test' );

		if ( null !== $test ) {
			$class = $test['ok'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $test['message'] ) . '</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_save_msg_settings', 'protech_wholesale_msg_settings_nonce' );
		woocommerce_admin_fields( $settings->get_fields() );
		submit_button();
		echo '</form>';

		$test_url = wp_nonce_url( add_query_arg( 'action', 'protech_test_brevo_connection', admin_url( 'admin-post.php' ) ), 'protech_test_brevo_connection' );
		echo '<p><a class="button" href="' . esc_url( $test_url ) . '">' . esc_html__( 'Test Brevo connection', 'protech-wholesale' ) . '</a></p>';
	}

	public function handle_test_brevo_connection(): void {
		check_admin_referer( 'protech_test_brevo_connection' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$result = ( new BrevoClient() )->get_account();

		if ( $result['ok'] ) {
			$email = (string) ( $result['data']['email'] ?? '' );
			self::stash( 'brevo_test', array( 'ok' => true, 'message' => sprintf( /* translators: %s: Brevo account email. */ __( 'Connected as %s.', 'protech-wholesale' ), $email ) ) );
		} else {
			self::stash( 'brevo_test', array( 'ok' => false, 'message' => sprintf( /* translators: %s: error message. */ __( 'Could not connect to Brevo: %s', 'protech-wholesale' ), $result['error'] ) ) );
		}

		wp_safe_redirect( self::url( 'settings' ) );
		exit;
	}

	public function handle_export_consent(): void {
		check_admin_referer( 'protech_export_consent' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wholesale-sms-consent-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, SmsConsent::csv_headers() );

		foreach ( SmsConsent::all_records_csv_rows() as $row ) {
			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}
}
