/**
 * Protech Wholesale — wp-admin helpers.
 * Loaded only on product edit, user profile, and the Wholesale admin
 * screens (see Plugin::enqueue_admin_assets()). Vanilla JS except where
 * WooCommerce's own admin JS forces a jQuery event contract on us.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initPriceOverrideRepeater();
		initBulkWholesalePriceActions();
		initApplicantActions();
		initMessaging();
		initStarterKitToggle();
	} );

	/**
	 * Product edit screen, Wholesale tab: hides the "Displays in a kit" /
	 * "Make up the difference with" fields while "One of every colour" is
	 * checked, since StarterKit ignores them in that mode. Pure
	 * progressive enhancement — without JS the fields just stay visible,
	 * with their own description explaining they're ignored.
	 */
	function initStarterKitToggle() {
		var checkbox = document.getElementById( '_protech_kit_one_of_each' );
		var fields = document.getElementById( 'protech-kit-target-fields' );

		if ( ! checkbox || ! fields ) {
			return;
		}

		var sync = function () {
			fields.style.display = checkbox.checked ? 'none' : '';
		};

		checkbox.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * User profile screen: "+ Add price override" / remove-row buttons
	 * for the per-customer product/variation ID -> price repeater
	 * (markup rendered by Approval::render_profile_fields()).
	 */
	function initPriceOverrideRepeater() {
		var container = document.getElementById( 'protech-price-overrides' );
		var addButton = document.getElementById( 'protech-add-override-row' );

		if ( ! container || ! addButton ) {
			return;
		}

		addButton.addEventListener( 'click', function () {
			var row = document.createElement( 'p' );
			row.className = 'protech-price-override-row';

			// The product picker is WooCommerce's own enhanced select
			// (wc-enhanced-select, enqueued on the profile screen by
			// Plugin::enqueue_admin_assets()); it initialises any
			// .wc-product-search it finds when asked to via this event.
			row.innerHTML =
				'<select class="wc-product-search protech-override-product" name="protech_price_override_ids[]" style="width:50%;"' +
				' data-placeholder="' + ( addButton.dataset.placeholderProduct || '' ) + '"' +
				' data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select> ' +
				'<input type="number" step="0.01" min="0" name="protech_price_override_prices[]" placeholder="' + ( addButton.dataset.placeholderPrice || '' ) + '" /> ' +
				'<button type="button" class="button protech-remove-override-row">&times;</button>';
			container.appendChild( row );

			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
			}
		} );

		container.addEventListener( 'click', function ( event ) {
			if ( event.target && event.target.classList.contains( 'protech-remove-override-row' ) ) {
				event.target.closest( '.protech-price-override-row' ).remove();
			}
		} );
	}

	/**
	 * Variations panel bulk actions: "Set wholesale prices" and the
	 * Volume/Bulk override equivalents.
	 *
	 * WooCommerce's meta-boxes-product-variation.js handles a bulk action
	 * it doesn't recognise by triggering two jQuery events on the bulk-
	 * action <select> (`select.variation_actions`): first `<action>`, then
	 * `<action>_ajax_data` via triggerHandler(), whose return value becomes
	 * the `data` it sends to admin-ajax — and returning null cancels the
	 * action outright. That is the only contract it offers: it never reads
	 * a data-attribute off the select, and triggerHandler() doesn't bubble,
	 * so the handler has to be bound directly on that element. The PHP side
	 * (ProductFields::handle_bulk_edit()) refuses to touch any prices when
	 * no value arrives, so the worst case remains "nothing happens".
	 */
	function initBulkWholesalePriceActions() {
		if ( ! window.jQuery ) {
			return;
		}

		var $select = window.jQuery( 'select.variation_actions' );

		if ( ! $select.length ) {
			return;
		}

		var strings = window.protechWholesaleAdmin || {};
		var prompts = {
			protech_set_wholesale_price: strings.bulkPricePrompt,
			protech_set_volume_price: strings.bulkVolumePrompt,
			protech_set_bulk_price: strings.bulkBulkPrompt
		};

		Object.keys( prompts ).forEach( function ( action ) {
			$select.on( action + '_ajax_data', function ( event, data ) {
				var value = window.prompt( prompts[ action ] || action );

				if ( null === value || '' === value.trim() ) {
					return null; // Cancels — WooCommerce sends no request at all.
				}

				data = data || {};
				data.value = value.trim();

				return data;
			} );
		} );
	}

	/**
	 * Applicants list: confirm before Approve; ask for an optional reason
	 * before Reject and submit it as a POST via the hidden form rendered by
	 * Approval::render_applicants_tab(). Without JS the links still work as
	 * plain nonce'd GET requests (Reject then carries no reason).
	 */
	function initApplicantActions() {
		var form = document.getElementById( 'protech-reject-form' );
		var strings = window.protechWholesaleAdmin || {};

		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( 'a' ) : null;

			if ( ! link ) {
				return;
			}

			if ( link.classList.contains( 'protech-approve-link' ) ) {
				if ( ! window.confirm( strings.approveConfirm || 'Approve this application?' ) ) {
					event.preventDefault();
				}

				return;
			}

			if ( ! link.classList.contains( 'protech-reject-link' ) ) {
				return;
			}

			var reason = window.prompt( strings.rejectPrompt || 'Reject this application? Optional reason:', '' );

			if ( null === reason ) {
				event.preventDefault(); // Cancelled.
				return;
			}

			if ( ! form ) {
				return; // Fall through to the plain GET link.
			}

			event.preventDefault();
			form.querySelector( '[name="user_id"]' ).value = link.dataset.userId || '';
			form.querySelector( '[name="_wpnonce"]' ).value = link.dataset.nonce || '';
			form.querySelector( '[name="reason"]' ).value = reason.trim();
			form.submit();
		} );
	}

	/**
	 * Messaging tab: the Customers-tab "select all" checkbox, a merge-tag
	 * chip that inserts itself into whichever text field/textarea was
	 * last focused, and the two confirm-before-you-send-this prompts
	 * (a real customer send, and deleting an automation rule).
	 */
	function initMessaging() {
		var selectAll = document.getElementById( 'protech-select-all-customers' );

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				document.querySelectorAll( '.protech-customer-checkbox' ).forEach( function ( checkbox ) {
					checkbox.checked = selectAll.checked;
				} );
			} );
		}

		var lastFocused = null;

		document.querySelectorAll( '.protech-tag-target' ).forEach( function ( field ) {
			field.addEventListener( 'focus', function () {
				lastFocused = field;
			} );
		} );

		document.querySelectorAll( '.protech-insert-tag' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var field = lastFocused;

				if ( ! field ) {
					return;
				}

				var tag   = button.dataset.tag || '';
				var start = field.selectionStart || field.value.length;
				var end   = field.selectionEnd || field.value.length;

				field.value = field.value.slice( 0, start ) + tag + field.value.slice( end );
				field.focus();
				field.selectionStart = field.selectionEnd = start + tag.length;
			} );
		} );

		var strings = window.protechWholesaleAdmin || {};

		document.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( target && target.classList && target.classList.contains( 'protech-confirm-delete' ) ) {
				if ( ! window.confirm( strings.deleteAutomationConfirm || 'Delete this automation rule?' ) ) {
					event.preventDefault();
				}
			}
		} );

		document.querySelectorAll( '.protech-confirm-send' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( strings.sendMessageConfirm || 'Send this message now? This cannot be undone.' ) ) {
					event.preventDefault();
				}
			} );
		} );
	}
} )();
