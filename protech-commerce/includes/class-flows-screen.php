<?php
/**
 * Messaging → Automatic (flows): a list of flows and a plain-form editor.
 * A flow's steps are a fixed number of slots (STEP_SLOTS top-level, each
 * a condition getting BRANCH_SLOTS of its own for yes/no) rather than a
 * free "add/remove/reorder" list — a deliberate scope cut from the
 * roadmap's own dedicated Preact flow builder; see DECISIONS.md. A slot
 * left as "Not used" is dropped when the flow is validated, so a flow
 * with three real steps and five empty slots saves with exactly three.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FlowsScreen
 */
class FlowsScreen {

	public const SAVE_ACTION      = 'protech_save_flow';
	public const DELETE_ACTION    = 'protech_delete_flow';
	public const TOGGLE_ACTION    = 'protech_toggle_flow';
	public const DUPLICATE_ACTION = 'protech_duplicate_flow';

	/** Top-level step slots offered in the editor. */
	private const STEP_SLOTS = 8;
	/** Slots offered inside a condition's yes/no branch. */
	private const BRANCH_SLOTS = 4;

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_' . self::DUPLICATE_ACTION, array( $this, 'handle_duplicate' ) );
	}

	/** @return array<string, string> */
	private static function trigger_labels(): array {
		return array(
			Flows::TRIGGER_DAYS_SINCE_LAST_ORDER => __( 'Days since their last order', 'protech-wholesale' ),
			Flows::TRIGGER_DAYS_SINCE_APPROVAL   => __( "Days since their account was approved, if they haven't ordered", 'protech-wholesale' ),
			Flows::TRIGGER_ORDER_STATUS          => __( 'An order reaches a status', 'protech-wholesale' ),
			Flows::TRIGGER_ORDER_PLACED          => __( 'Any order is placed (reaches processing or completed)', 'protech-wholesale' ),
			Flows::TRIGGER_FIRST_ORDER           => __( 'Their first order is placed', 'protech-wholesale' ),
			Flows::TRIGGER_WHOLESALE_APPROVED    => __( 'Their wholesale account is approved', 'protech-wholesale' ),
			Flows::TRIGGER_CONTACT_SUBSCRIBED    => __( 'They confirm a signup form', 'protech-wholesale' ),
			Flows::TRIGGER_ACCOUNT_CREATED       => __( 'Their account is created', 'protech-wholesale' ),
			Flows::TRIGGER_TAG_ADDED             => __( 'A tag is added to them', 'protech-wholesale' ),
			Flows::TRIGGER_MANUAL                => __( 'Only when added manually', 'protech-wholesale' ),
		);
	}

	/** One plain sentence describing a flow's trigger, for the list. */
	private static function describe_trigger( array $flow ): string {
		$type   = (string) ( $flow['trigger']['type'] ?? '' );
		$params = (array) ( $flow['trigger']['params'] ?? array() );

		switch ( $type ) {
			case Flows::TRIGGER_DAYS_SINCE_LAST_ORDER:
				$repeats = (int) ( $params['repeats'] ?? 1 );
				/* translators: 1: number of days, 2: number of times. */
				return $repeats > 1
					? sprintf( __( 'Every %1$d days since their last order, up to %2$d times', 'protech-wholesale' ), (int) ( $params['days'] ?? 30 ), $repeats )
					/* translators: %d: number of days. */
					: sprintf( __( '%d days after their last order, if they have not ordered since', 'protech-wholesale' ), (int) ( $params['days'] ?? 30 ) );

			case Flows::TRIGGER_DAYS_SINCE_APPROVAL:
				/* translators: %d: number of days. */
				return sprintf( __( "%d days after approval, if they haven't ordered", 'protech-wholesale' ), (int) ( $params['days'] ?? 7 ) );

			case Flows::TRIGGER_ORDER_STATUS:
				$statuses = wc_get_order_statuses();
				/* translators: %s: order status name. */
				return sprintf( __( 'When an order becomes "%s"', 'protech-wholesale' ), (string) ( $statuses[ 'wc-' . ( $params['status'] ?? '' ) ] ?? $params['status'] ?? '' ) );

			case Flows::TRIGGER_TAG_ADDED:
				/* translators: %s: tag name. */
				return sprintf( __( 'When the tag "%s" is added', 'protech-wholesale' ), (string) ( $params['tag'] ?? '' ) );

			default:
				return self::trigger_labels()[ $type ] ?? $type;
		}
	}

	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';

		if ( '' !== $edit ) {
			self::render_editor( 'new' === $edit ? Flows::defaults() : ( Flows::get( $edit ) ?? Flows::defaults() ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="updated notice inline"><p>' . esc_html__( 'Flow saved.', 'protech-wholesale' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Automatic (flows)', 'protech-wholesale' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Multi-step automations: a trigger, then a list of steps (send, wait, tag, or branch). Every flow ships switched off.', 'protech-wholesale' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( MessagingTab::url( 'flows', array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'New flow', 'protech-wholesale' ) . '</a></p>';

		$flows = Flows::all();

		if ( empty( $flows ) ) {
			echo '<p>' . esc_html__( 'No flows yet.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'protech-wholesale' ), __( 'Trigger', 'protech-wholesale' ), __( 'Active / waiting / done', 'protech-wholesale' ), __( 'Enabled', 'protech-wholesale' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $flows as $id => $flow ) {
			$counts     = FlowRunner::counts_for( (string) $id );
			$toggle_url = wp_nonce_url( add_query_arg( array( 'action' => self::TOGGLE_ACTION, 'flow_id' => $id ), admin_url( 'admin-post.php' ) ), self::TOGGLE_ACTION . '_' . $id );
			$dupe_url   = wp_nonce_url( add_query_arg( array( 'action' => self::DUPLICATE_ACTION, 'flow_id' => $id ), admin_url( 'admin-post.php' ) ), self::DUPLICATE_ACTION . '_' . $id );
			$delete_url = wp_nonce_url( add_query_arg( array( 'action' => self::DELETE_ACTION, 'flow_id' => $id ), admin_url( 'admin-post.php' ) ), self::DELETE_ACTION . '_' . $id );

			echo '<tr>';
			echo '<td><a href="' . esc_url( MessagingTab::url( 'flows', array( 'edit' => $id ) ) ) . '"><strong>' . esc_html( (string) $flow['name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( self::describe_trigger( $flow ) ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%d / %d / %d', $counts[ Flows::RUN_ACTIVE ], $counts[ Flows::RUN_WAITING ], $counts[ Flows::RUN_COMPLETED ] ) ) . '</td>';
			echo '<td><a href="' . esc_url( $toggle_url ) . '">' . ( ! empty( $flow['enabled'] ) ? esc_html__( 'On (turn off)', 'protech-wholesale' ) : esc_html__( 'Off (turn on)', 'protech-wholesale' ) ) . '</a></td>';
			echo '<td><a href="' . esc_url( $dupe_url ) . '">' . esc_html__( 'Duplicate', 'protech-wholesale' ) . '</a> | <a href="' . esc_url( $delete_url ) . '" style="color:#b32d2e;">' . esc_html__( 'Delete', 'protech-wholesale' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/** @param array<string, mixed> $flow */
	private static function render_editor( array $flow ): void {
		echo '<p><a href="' . esc_url( MessagingTab::url( 'flows' ) ) . '">&larr; ' . esc_html__( 'All flows', 'protech-wholesale' ) . '</a></p>';
		echo '<h2>' . esc_html( '' !== (string) $flow['id'] ? (string) $flow['name'] : __( 'New flow', 'protech-wholesale' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $flow['id'] ) . '" />';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="flow-name">' . esc_html__( 'Name', 'protech-wholesale' ) . '</label></th><td><input type="text" id="flow-name" name="name" value="' . esc_attr( (string) $flow['name'] ) . '" class="regular-text" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Enabled', 'protech-wholesale' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $flow['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Ships off by default; turn on once you have checked it.', 'protech-wholesale' ) . '</label></td></tr>';

		echo '<tr><th><label for="flow-trigger">' . esc_html__( 'Trigger', 'protech-wholesale' ) . '</label></th><td>';
		echo '<select id="flow-trigger" name="trigger[type]">';
		foreach ( self::trigger_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $flow['trigger']['type'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		self::render_trigger_params( (string) ( $flow['trigger']['type'] ?? '' ), (array) ( $flow['trigger']['params'] ?? array() ) );
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Only for tiers', 'protech-wholesale' ) . '</th><td>';
		foreach ( Tiers::get_tier_labels() as $key => $label ) {
			echo '<label style="margin-right:12px;"><input type="checkbox" name="tiers[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, (array) $flow['tiers'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Leave all unchecked for every tier.', 'protech-wholesale' ) . '</p></td></tr>';

		echo '<tr><th><label for="flow-tags">' . esc_html__( 'Only with any of these tags', 'protech-wholesale' ) . '</label></th><td><input type="text" id="flow-tags" name="tags_any" value="' . esc_attr( implode( ', ', (array) $flow['tags_any'] ) ) . '" class="regular-text" /><p class="description">' . esc_html__( 'Comma-separated. Leave blank to not filter by tag.', 'protech-wholesale' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Steps', 'protech-wholesale' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Set a slot to "Not used" to leave it out. Steps run top to bottom; a condition\'s two branches each run their own steps and then the flow is done.', 'protech-wholesale' ) . '</p>';

		$steps = (array) $flow['steps'];

		for ( $i = 0; $i < self::STEP_SLOTS; $i++ ) {
			self::render_step_slot( "steps[{$i}]", $steps[ $i ] ?? array(), true );
		}

		submit_button( __( 'Save flow', 'protech-wholesale' ) );
		echo '</form>';

		self::render_step_slot_script();
	}

	private static function render_trigger_params( string $type, array $params ): void {
		switch ( $type ) {
			case Flows::TRIGGER_DAYS_SINCE_LAST_ORDER:
				echo ' <input type="number" name="trigger[params][days]" value="' . esc_attr( (string) ( $params['days'] ?? 30 ) ) . '" min="1" max="365" style="width:70px;" /> ' . esc_html__( 'days', 'protech-wholesale' );
				echo ', ' . esc_html__( 'repeat up to', 'protech-wholesale' ) . ' <input type="number" name="trigger[params][repeats]" value="' . esc_attr( (string) ( $params['repeats'] ?? 1 ) ) . '" min="1" max="12" style="width:60px;" /> ' . esc_html__( 'times', 'protech-wholesale' );
				break;

			case Flows::TRIGGER_DAYS_SINCE_APPROVAL:
				echo ' <input type="number" name="trigger[params][days]" value="' . esc_attr( (string) ( $params['days'] ?? 7 ) ) . '" min="1" max="365" style="width:70px;" /> ' . esc_html__( 'days', 'protech-wholesale' );
				break;

			case Flows::TRIGGER_ORDER_STATUS:
				echo ' <select name="trigger[params][status]">';
				foreach ( wc_get_order_statuses() as $key => $label ) {
					$value = str_replace( 'wc-', '', $key );
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( $params['status'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case Flows::TRIGGER_TAG_ADDED:
				echo ' <input type="text" name="trigger[params][tag]" value="' . esc_attr( (string) ( $params['tag'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'tag name', 'protech-wholesale' ) . '" />';
				break;
		}
	}

	/**
	 * @param array<string, mixed> $step
	 */
	private static function render_step_slot( string $name, array $step, bool $allow_condition ): void {
		$type = (string) ( $step['type'] ?? '' );

		echo '<div class="pw-step-slot" style="border:1px solid #dcdcde;padding:12px;margin-bottom:8px;max-width:640px;">';
		echo '<select class="pw-step-type" name="' . esc_attr( $name ) . '[type]" data-slot="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( 'Not used', 'protech-wholesale' ) . '</option>';

		$labels = array(
			'send_email'  => __( 'Send an email', 'protech-wholesale' ),
			'send_sms'    => __( 'Send a text', 'protech-wholesale' ),
			'delay'       => __( 'Wait', 'protech-wholesale' ),
			'add_tag'     => __( 'Add a tag', 'protech-wholesale' ),
			'remove_tag'  => __( 'Remove a tag', 'protech-wholesale' ),
			'end'         => __( 'Stop the flow here', 'protech-wholesale' ),
		);

		if ( $allow_condition ) {
			$labels['condition'] = __( 'Branch on a condition', 'protech-wholesale' );
		}

		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $type, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';

		echo '<div class="pw-step-fields" data-fields-for="' . esc_attr( $name ) . '">';

		echo '<p class="pw-step-field pw-step-send_email" ' . ( 'send_email' !== $type ? 'hidden' : '' ) . '>';
		echo '<label>' . esc_html__( 'Template', 'protech-wholesale' ) . ' <select name="' . esc_attr( $name ) . '[email][template_id]"><option value="">' . esc_html__( 'Write it here instead', 'protech-wholesale' ) . '</option>';
		foreach ( EmailTemplates::all() as $tid => $template ) {
			echo '<option value="' . esc_attr( (string) $tid ) . '" ' . selected( $step['email']['template_id'] ?? '', $tid, false ) . '>' . esc_html( (string) $template['name'] ) . '</option>';
		}
		echo '</select></label><br />';
		echo '<label>' . esc_html__( 'Subject', 'protech-wholesale' ) . ' <input type="text" name="' . esc_attr( $name ) . '[email][subject]" value="' . esc_attr( (string) ( $step['email']['subject'] ?? '' ) ) . '" class="regular-text" /></label><br />';
		echo '<label>' . esc_html__( 'Body (used without a template)', 'protech-wholesale' ) . '<br /><textarea name="' . esc_attr( $name ) . '[email][body]" rows="3" class="large-text">' . esc_textarea( (string) ( $step['email']['body'] ?? '' ) ) . '</textarea></label>';
		echo '</p>';

		echo '<p class="pw-step-field pw-step-send_sms" ' . ( 'send_sms' !== $type ? 'hidden' : '' ) . '>';
		echo '<label>' . esc_html__( 'Text', 'protech-wholesale' ) . '<br /><textarea name="' . esc_attr( $name ) . '[sms][body]" rows="2" class="large-text">' . esc_textarea( (string) ( $step['sms']['body'] ?? '' ) ) . '</textarea></label>';
		echo '</p>';

		echo '<p class="pw-step-field pw-step-delay" ' . ( 'delay' !== $type ? 'hidden' : '' ) . '>';
		echo '<input type="number" name="' . esc_attr( $name ) . '[amount]" value="' . esc_attr( (string) ( $step['amount'] ?? 1 ) ) . '" min="1" max="365" style="width:70px;" /> ';
		echo '<select name="' . esc_attr( $name ) . '[unit]"><option value="hours" ' . selected( $step['unit'] ?? 'days', 'hours', false ) . '>' . esc_html__( 'hours', 'protech-wholesale' ) . '</option><option value="days" ' . selected( $step['unit'] ?? 'days', 'days', false ) . '>' . esc_html__( 'days', 'protech-wholesale' ) . '</option></select>';
		echo '</p>';

		echo '<p class="pw-step-field pw-step-add_tag" ' . ( 'add_tag' !== $type ? 'hidden' : '' ) . '>';
		echo '<input type="text" name="' . esc_attr( $name ) . '[tag]" value="' . esc_attr( 'add_tag' === $type ? (string) ( $step['tag'] ?? '' ) : '' ) . '" placeholder="' . esc_attr__( 'tag name', 'protech-wholesale' ) . '" />';
		echo '</p>';

		echo '<p class="pw-step-field pw-step-remove_tag" ' . ( 'remove_tag' !== $type ? 'hidden' : '' ) . '>';
		echo '<input type="text" name="' . esc_attr( $name ) . '[tag]" value="' . esc_attr( 'remove_tag' === $type ? (string) ( $step['tag'] ?? '' ) : '' ) . '" placeholder="' . esc_attr__( 'tag name', 'protech-wholesale' ) . '" />';
		echo '</p>';

		if ( $allow_condition ) {
			echo '<div class="pw-step-field pw-step-condition" ' . ( 'condition' !== $type ? 'hidden' : '' ) . '>';
			$rule = (array) ( $step['rule'] ?? array() );
			echo '<select name="' . esc_attr( $name ) . '[rule][field]">';
			foreach ( self::condition_field_labels() as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $rule['field'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ' . esc_html__( 'equals', 'protech-wholesale' ) . ' <input type="text" name="' . esc_attr( $name ) . '[rule][value]" value="' . esc_attr( (string) ( $rule['value'] ?? '' ) ) . '" style="width:120px;" />';
			echo '<p class="description">' . esc_html__( 'For "tier", the value is a tier key. For amounts, a number. Yes/no fields ignore the value.', 'protech-wholesale' ) . '</p>';

			echo '<strong>' . esc_html__( 'If yes:', 'protech-wholesale' ) . '</strong>';
			for ( $i = 0; $i < self::BRANCH_SLOTS; $i++ ) {
				self::render_step_slot( "{$name}[yes][{$i}]", $step['yes'][ $i ] ?? array(), false );
			}

			echo '<strong>' . esc_html__( 'If no:', 'protech-wholesale' ) . '</strong>';
			for ( $i = 0; $i < self::BRANCH_SLOTS; $i++ ) {
				self::render_step_slot( "{$name}[no][{$i}]", $step['no'][ $i ] ?? array(), false );
			}

			echo '</div>';
		}

		echo '</div></div>';
	}

	/** @return array<string, string> */
	private static function condition_field_labels(): array {
		return array(
			'is_wholesale'            => __( 'Is a wholesale customer (yes/no)', 'protech-wholesale' ),
			'tier'                    => __( 'Tier is', 'protech-wholesale' ),
			'has_tag'                 => __( 'Has the tag', 'protech-wholesale' ),
			'total_spent_gt'          => __( 'Lifetime spend is over', 'protech-wholesale' ),
			'has_ordered_since_entry' => __( 'Has ordered since entering this flow (yes/no)', 'protech-wholesale' ),
		);
	}

	/** Pure show/hide: no build step, no external file, matching assets/js/admin.js's own inline-enough scale elsewhere on this screen. */
	private static function render_step_slot_script(): void {
		?>
		<script>
		( function () {
			document.querySelectorAll( '.pw-step-type' ).forEach( function ( select ) {
				var update = function () {
					var wrap = select.closest( '.pw-step-slot' );
					wrap.querySelectorAll( ':scope > .pw-step-fields > .pw-step-field' ).forEach( function ( field ) {
						field.hidden = ! field.classList.contains( 'pw-step-' + select.value );
					} );
				};
				select.addEventListener( 'change', update );
			} );
		} )();
		</script>
		<?php
	}

	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$input = array(
			'id'         => sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ),
			'name'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'enabled'    => isset( $_POST['enabled'] ),
			'trigger'    => wp_unslash( $_POST['trigger'] ?? array() ),
			'tiers'      => wp_unslash( $_POST['tiers'] ?? array() ),
			'tags_any'   => array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['tags_any'] ?? '' ) ) ) ),
			'steps'      => wp_unslash( $_POST['steps'] ?? array() ),
		);

		$result = Flows::validate( $input );

		if ( ! empty( $result['errors'] ) ) {
			wp_die( esc_html( implode( ' ', $result['errors'] ) ), esc_html__( 'Could not save', 'protech-wholesale' ), array( 'back_link' => true ) );
		}

		Flows::save( $result['flow'] );

		wp_safe_redirect( add_query_arg( 'saved', 1, MessagingTab::url( 'flows' ) ) );
		exit;
	}

	public function handle_toggle(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['flow_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::TOGGLE_ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$flow = Flows::get( $id );

		if ( null !== $flow ) {
			$turning_off = ! empty( $flow['enabled'] );
			Flows::set_enabled( $id, ! $turning_off );

			if ( $turning_off ) {
				FlowRunner::cancel_runs_for_flow( $id );
			}
		}

		wp_safe_redirect( MessagingTab::url( 'flows' ) );
		exit;
	}

	public function handle_duplicate(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['flow_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::DUPLICATE_ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$copy = Flows::duplicate( $id );

		wp_safe_redirect( '' !== $copy ? MessagingTab::url( 'flows', array( 'edit' => $copy ) ) : MessagingTab::url( 'flows' ) );
		exit;
	}

	public function handle_delete(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['flow_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.

		check_admin_referer( self::DELETE_ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		FlowRunner::cancel_runs_for_flow( $id );
		Flows::delete( $id );

		wp_safe_redirect( MessagingTab::url( 'flows' ) );
		exit;
	}
}
