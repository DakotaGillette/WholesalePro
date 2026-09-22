<?php
/**
 * Messaging → Emails (the "automations" view, still on the landing/top-level
 * slug so old bookmarks keep working, see MessagingTab::get_views()): the
 * lifecycle emails list (EmailsScreen), the rule table, and the rule form.
 * The rule form's email/SMS fields, its merge-tag reference, template
 * picker, "Send a preview" box and skip-reason labels are the same UI
 * Compose uses, so those live on ComposeScreen and are called from here.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutomationsScreen
 */
class AutomationsScreen {

	public function register_hooks(): void {
		add_action( 'admin_post_protech_save_automation', array( $this, 'handle_save_automation' ) );
		add_action( 'admin_post_protech_preview_automation', array( $this, 'handle_preview_automation' ) );
		add_action( 'admin_post_protech_send_test_automation', array( $this, 'handle_send_test_automation' ) );
		add_action( 'admin_post_protech_toggle_automation', array( $this, 'handle_toggle_automation' ) );
		add_action( 'admin_post_protech_delete_automation', array( $this, 'handle_delete_automation' ) );
		add_action( 'admin_post_protech_run_automations_now', array( $this, 'handle_run_automations_now' ) );
	}

	public static function render(): void {
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

		EmailsScreen::render_lifecycle();

		$rules  = Automations::all();
		$labels = self::trigger_labels();

		echo '<h2>' . esc_html__( 'Automatic', 'protech-wholesale' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Rules that send on their own when something happens. Each one is switched off until you turn it on.', 'protech-wholesale' ) . '</p>';

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
				echo '<td><a href="' . esc_url( MessagingTab::url( 'automations', array( 'edit' => $rule['id'] ) ) ) . '"><strong>' . esc_html( $rule['name'] ) . '</strong></a><br /><span class="description">' . esc_html( Automations::describe( $rule ) ) . '</span></td>';
				echo '<td>' . esc_html( $labels[ $rule['trigger'] ] ?? $rule['trigger'] ) . '</td>';
				echo '<td>' . esc_html( ucfirst( (string) $rule['channel'] ) ) . '</td>';
				echo '<td>' . esc_html( sprintf( '%d / %d / %d', $counts[ MessageLog::STATUS_SENT ], $counts[ MessageLog::STATUS_FAILED ], $counts[ MessageLog::STATUS_SKIPPED ] ) ) . '</td>';
				echo '<td>' . esc_html( ! empty( $rule['last_run_at'] ) ? human_time_diff( (int) $rule['last_run_at'] ) . ' ' . __( 'ago', 'protech-wholesale' ) : '—' ) . '</td>';
				echo '<td>' . wp_kses_post( self::toggle_link( $rule ) ) . '</td>';
				echo '<td>' . wp_kses_post( EmailsScreen::duplicate_rule_link( (string) $rule['id'] ) ) . ' | ' . wp_kses_post( self::delete_link( $rule ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Add a rule', 'protech-wholesale' ) . '</h3><p>';
		foreach ( $labels as $trigger => $label ) {
			printf( '<a class="button" href="%s">%s</a> ', esc_url( MessagingTab::url( 'automations', array( 'new' => $trigger ) ) ), esc_html( $label ) );
		}
		echo '</p>';

		EmailsScreen::render_sent();

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
		$stash = AdminStash::unstash( 'automation_form' );
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

		$preview = AdminStash::unstash( 'automation_preview' );

		ComposeScreen::render_preview_results( AdminStash::unstash( 'automation_test' ) );

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

			<?php ComposeScreen::render_merge_tag_reference( $trigger ); ?>

			<h3><?php esc_html_e( 'Email', 'protech-wholesale' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php ComposeScreen::render_template_picker( (string) ( $rule['email']['template_id'] ?? '' ) ); ?>
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

			<?php ComposeScreen::render_preview_box( 'protech_send_test_automation', $stash['input'] ?? array(), true ); ?>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save rule', 'protech-wholesale' ); ?></button>
				<button type="submit" name="action" value="protech_preview_automation" class="button"><?php esc_html_e( 'Preview recipients', 'protech-wholesale' ); ?></button>
				<a class="button" href="<?php echo esc_url( MessagingTab::url( 'automations' ) ); ?>"><?php esc_html_e( 'Cancel', 'protech-wholesale' ); ?></a>
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
				$parts[] = sprintf( '%d %s', $count, ComposeScreen::reason_label( (string) $reason ) );
			}
			echo ' ' . esc_html( sprintf( /* translators: %s: comma-separated skip reasons. */ __( 'Skipped: %s.', 'protech-wholesale' ), implode( ', ', $parts ) ) );
		}

		echo '</p></div>';
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
			AdminStash::stash( 'automation_form', array( 'input' => $input, 'errors' => $result['errors'] ) );
			$back = '' !== (string) ( $input['id'] ?? '' )
				? MessagingTab::url( 'automations', array( 'edit' => $input['id'] ) )
				: MessagingTab::url( 'automations', array( 'new' => $result['rule']['trigger'] ) );
			wp_safe_redirect( $back );
			exit;
		}

		Automations::save( $result['rule'] );
		Logger::info( sprintf( 'Automation rule "%s" saved by admin #%d.', $result['rule']['name'], get_current_user_id() ) );

		wp_safe_redirect( add_query_arg( 'saved', '1', MessagingTab::url( 'automations' ) ) );
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
			? MessagingTab::url( 'automations', array( 'edit' => $input['id'] ) )
			: MessagingTab::url( 'automations', array( 'new' => $result['rule']['trigger'] ) );

		if ( ! empty( $result['errors'] ) ) {
			AdminStash::stash( 'automation_form', array( 'input' => $input, 'errors' => $result['errors'] ) );
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

		AdminStash::stash( 'automation_form', array( 'input' => $input, 'errors' => array() ) );
		AdminStash::stash( 'automation_preview', array( 'ok' => $ok, 'reasons' => $reasons ) );

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

		wp_safe_redirect( MessagingTab::url( 'automations' ) );
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

		wp_safe_redirect( MessagingTab::url( 'automations' ) );
		exit;
	}

	public function handle_run_automations_now(): void {
		check_admin_referer( 'protech_run_automations_now' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		AutomationRunner::run_now();

		wp_safe_redirect( add_query_arg( 'ran', '1', MessagingTab::url( 'automations' ) ) );
		exit;
	}

	/** Preview send from the automation form: the rule's own channels, content and category, exactly as typed (saved or not). */
	public function handle_send_test_automation(): void {
		check_admin_referer( 'protech_wholesale_save_automation', 'protech_wholesale_automation_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rule  = Automations::validate( $input )['rule']; // Errors (a missing name, say) do not matter for a preview.
		$back  = '' !== (string) ( $input['id'] ?? '' )
			? MessagingTab::url( 'automations', array( 'edit' => $input['id'] ) )
			: MessagingTab::url( 'automations', array( 'new' => $rule['trigger'] ) );

		$content = array(
			'service_message' => MessageLog::CATEGORY_TRANSACTIONAL === $rule['category'] ? '1' : '',
			'email'           => $rule['email'],
			'sms'             => $rule['sms'],
			'preview_email'   => $input['preview_email'] ?? '',
			'preview_phone'   => $input['preview_phone'] ?? '',
		);

		AdminStash::stash( 'automation_form', array( 'input' => $input, 'errors' => array() ) );
		AdminStash::stash( 'automation_test', array( 'results' => ComposeScreen::send_previews( $content, Automations::channels( $rule ) ), 'input' => $input ) );

		wp_safe_redirect( $back );
		exit;
	}
}
