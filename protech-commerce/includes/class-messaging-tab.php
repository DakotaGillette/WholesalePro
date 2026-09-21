<?php
/**
 * The top-level "Messaging" section of wp-admin (it lived under
 * WooCommerce → Wholesale until 2.1.0) — five views (Automations, Compose,
 * Log, Compliance, Settings), each its own sub-menu page. Every action
 * that changes something goes through admin-post.php and comes back as
 * a redirect: render_page() has already echoed the page's <h1> by the
 * time a view's body renders, so nothing in here can send its
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

	/** The top-level menu page; the first view (Automations) is its landing page. */
	public const PAGE = 'protech-messaging';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_url' ) );
		add_action( 'admin_post_protech_save_automation', array( $this, 'handle_save_automation' ) );
		add_action( 'admin_post_protech_preview_automation', array( $this, 'handle_preview_automation' ) );
		add_action( 'admin_post_protech_send_test_automation', array( $this, 'handle_send_test_automation' ) );
		add_action( 'admin_post_protech_toggle_automation', array( $this, 'handle_toggle_automation' ) );
		add_action( 'admin_post_protech_delete_automation', array( $this, 'handle_delete_automation' ) );
		add_action( 'admin_post_protech_run_automations_now', array( $this, 'handle_run_automations_now' ) );

		add_action( 'admin_post_protech_message_customers', array( $this, 'handle_message_customers' ) );
		add_action( 'admin_post_protech_review_message', array( $this, 'handle_review_message' ) );
		add_action( 'admin_post_protech_edit_message', array( $this, 'handle_edit_message' ) );
		add_action( 'admin_post_protech_send_message', array( $this, 'handle_send_message' ) );
		add_action( 'admin_post_protech_send_test_message', array( $this, 'handle_send_test_message' ) );

		add_action( 'admin_post_protech_export_consent', array( $this, 'handle_export_consent' ) );
		add_action( 'admin_post_protech_test_brevo_connection', array( $this, 'handle_test_brevo_connection' ) );
	}

	/** The admin page slug of a view: the landing view owns the top-level slug, the rest hang off it. */
	public static function page_slug( string $view ): string {
		return 'automations' === $view ? self::PAGE : self::PAGE . '-' . $view;
	}

	/**
	 * The one place that builds a Messaging URL, so moving the section was a
	 * change here and nowhere else.
	 *
	 * @param array<string, scalar> $args
	 */
	public static function url( string $view, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::page_slug( $view ) ), $args ), admin_url( 'admin.php' ) );
	}

	/** Which view the current request is for, from the admin page slug. */
	private static function current_view(): string {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		foreach ( array_keys( self::get_views() ) as $view ) {
			if ( self::page_slug( $view ) === $page ) {
				return $view;
			}
		}

		return 'automations';
	}

	/** The top-level menu and one sub-menu page per view. */
	public function register_menu(): void {
		add_menu_page(
			__( 'Messaging', 'protech-wholesale' ),
			__( 'Messaging', 'protech-wholesale' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-email-alt',
			56
		);

		foreach ( self::get_views() as $view => $label ) {
			add_submenu_page(
				self::PAGE,
				$label,
				$label,
				'manage_woocommerce',
				self::page_slug( $view ),
				array( __CLASS__, 'render_page' )
			);
		}
	}

	/** The page shell: the heading, then the current view. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Messaging', 'protech-wholesale' ) . '</h1>';
		self::render();
		echo '</div>';
	}

	/**
	 * Bookmarks and old links (WooCommerce → Wholesale → Messaging, with its
	 * `&tab=messaging&view=…`) go to the same view in the new section, with
	 * every other query argument carried across.
	 */
	public function redirect_legacy_url(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only redirect of a GET URL.
		if ( 'protech-wholesale' !== sanitize_key( $_GET['page'] ?? '' ) || 'messaging' !== sanitize_key( $_GET['tab'] ?? '' ) ) {
			return;
		}

		$view = sanitize_key( $_GET['view'] ?? 'automations' );
		$args = array();

		foreach ( $_GET as $key => $value ) {
			if ( ! in_array( $key, array( 'page', 'tab', 'view' ), true ) && is_scalar( $value ) ) {
				$args[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		// phpcs:enable

		if ( ! array_key_exists( $view, self::get_views() ) ) {
			$view = 'automations';
		}

		wp_safe_redirect( self::url( $view, $args ) );
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_views(): array {
		return array(
			'automations' => __( 'Automations', 'protech-wholesale' ),
			'compose'     => __( 'Compose', 'protech-wholesale' ),
			'templates'   => __( 'Email templates', 'protech-wholesale' ),
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

		$view  = self::current_view();
		$views = self::get_views();

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
			case 'templates':
				EmailComposer::render();
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

		self::render_preview_results( self::unstash( 'automation_test' ) );

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
				<?php self::render_template_picker( (string) ( $rule['email']['template_id'] ?? '' ) ); ?>
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

			<?php self::render_preview_box( 'protech_send_test_automation', $stash['input'] ?? array(), true ); ?>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save rule', 'protech-wholesale' ); ?></button>
				<button type="submit" name="action" value="protech_preview_automation" class="button"><?php esc_html_e( 'Preview recipients', 'protech-wholesale' ); ?></button>
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
		$form_stash   = self::unstash( 'compose_form' );
		$review_stash = self::unstash( 'compose_review' );
		$test_stash   = self::unstash( 'compose_test' );

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

		wp_safe_redirect( self::url( 'compose', array( 'sel' => '1' ) ) );
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

		wp_safe_redirect( self::url( 'compose' ) );
		exit;
	}

	/** Back from the review screen to the form, with everything still typed. */
	public function handle_edit_message(): void {
		check_admin_referer( 'protech_wholesale_compose', 'protech_wholesale_compose_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		self::stash( 'compose_form', array( 'input' => wp_unslash( $_POST ), 'errors' => array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shown back in escaped fields only.

		wp_safe_redirect( self::url( 'compose' ) );
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
			self::stash( 'compose_form', array( 'input' => $input, 'errors' => $review['errors'] ) );
			return;
		}

		self::stash( 'compose_review', $review );
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
	private static function hidden_fields( array $data, string $prefix = '' ): void {
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
	 * message below it.
	 */
	private static function render_template_picker( string $selected ): void {
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
				<a href="<?php echo esc_url( self::url( 'templates' ) ); ?>"><?php esc_html_e( 'Manage templates', 'protech-wholesale' ); ?></a>
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

		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$channel  = in_array( $input['channel'] ?? '', array( 'email', 'sms', 'both' ), true ) ? $input['channel'] : 'email';
		$channels = 'both' === $channel ? array( 'email', 'sms' ) : array( $channel );

		self::stash( 'compose_test', array( 'results' => self::send_previews( $input, $channels ), 'input' => $input ) );

		// A preview sent from the review screen returns to it; from the form, to the form.
		if ( ! empty( $input['from_review'] ) ) {
			self::stash_review_or_form( $input );
		} else {
			self::stash( 'compose_form', array( 'input' => $input, 'errors' => array() ) );
		}

		wp_safe_redirect( self::url( 'compose' ) );
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
			? self::url( 'automations', array( 'edit' => $input['id'] ) )
			: self::url( 'automations', array( 'new' => $rule['trigger'] ) );

		$content = array(
			'service_message' => MessageLog::CATEGORY_TRANSACTIONAL === $rule['category'] ? '1' : '',
			'email'           => $rule['email'],
			'sms'             => $rule['sms'],
			'preview_email'   => $input['preview_email'] ?? '',
			'preview_phone'   => $input['preview_phone'] ?? '',
		);

		self::stash( 'automation_form', array( 'input' => $input, 'errors' => array() ) );
		self::stash( 'automation_test', array( 'results' => self::send_previews( $content, Automations::channels( $rule ) ), 'input' => $input ) );

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * One preview per channel, to whoever the admin typed (else themselves).
	 *
	 * @param array<string, mixed> $content Campaigns::send_test() input, without a channel.
	 * @param string[]             $channels
	 * @return array<string, array{ok: bool, error: string, provider: string, recipient: string}>
	 */
	private static function send_previews( array $content, array $channels ): array {
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
	private static function render_preview_box( string $action, array $input, bool $note_order_tags = false ): void {
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
	 * The green/red result line(s) after a preview send.
	 *
	 * @param array<string, mixed>|null $stash {results: array<string, array{ok: bool, error: string, recipient: string}>}
	 */
	private static function render_preview_results( ?array $stash ): void {
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
		echo '<input type="hidden" name="page" value="' . esc_attr( self::page_slug( 'log' ) ) . '" />';
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
		echo '<p>' . esc_html__( 'Email and texts are treated differently on purpose. Marketing email may go to any past customer, always with an unsubscribe link and your postal address, until they unsubscribe. Marketing texts go only to people who have said yes to them, and that is never assumed from being a customer.', 'protech-wholesale' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:800px;"><thead><tr><th></th><th>' . esc_html__( 'Customers', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Can get marketing email', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Unsubscribed', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Said yes to marketing texts', 'protech-wholesale' ) . '</th><th>' . esc_html__( 'Said yes to order-update texts', 'protech-wholesale' ) . '</th></tr></thead><tbody>';

		foreach ( array( Audience::SCOPE_WHOLESALE => __( 'Wholesale', 'protech-wholesale' ), Audience::SCOPE_RETAIL => __( 'Retail', 'protech-wholesale' ) ) as $scope => $scope_label ) {
			$customers = Audience::pool( $scope );
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
				'<tr><th>%s</th><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
				esc_html( $scope_label ),
				count( $customers ),
				count( $customers ) - $email_optout,
				$email_optout,
				$marketing,
				$transactional
			);
		}

		echo '</tbody></table>';

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
