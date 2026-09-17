/**
 * Protech Wholesale — wp-admin helpers.
 * Vanilla JS, no jQuery dependency. Loaded only on product edit,
 * user profile, and the Wholesale admin screens (see Plugin::enqueue_admin_assets()).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initPriceOverrideRepeater();
		initBulkWholesalePricePrompt();
	} );

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
			row.innerHTML =
				'<input type="number" name="protech_price_override_ids[]" placeholder="' + addButton.dataset.placeholderId + '" />' +
				'<input type="number" step="0.01" min="0" name="protech_price_override_prices[]" placeholder="' + addButton.dataset.placeholderPrice + '" />' +
				'<button type="button" class="button protech-remove-override-row">&times;</button>';
			container.appendChild( row );
		} );

		container.addEventListener( 'click', function ( event ) {
			if ( event.target && event.target.classList.contains( 'protech-remove-override-row' ) ) {
				event.target.closest( '.protech-price-override-row' ).remove();
			}
		} );
	}

	/**
	 * Variations panel: "Set wholesale prices" bulk action.
	 *
	 * WooCommerce's own variations JS only opens a window.prompt() for a
	 * short hardcoded list of its own bulk actions (regular price, sale
	 * price, etc.) — a custom action added via the
	 * `woocommerce_variable_product_bulk_edit_actions` hook is submitted
	 * with no value at all otherwise. So this intercepts the bulk-action
	 * select in the CAPTURE phase (which always runs before WooCommerce's
	 * own bubble-phase jQuery `.on('change', ...)` handler, regardless of
	 * script load order), prompts for the price, and writes it onto the
	 * select element itself so WooCommerce's own AJAX call picks it up
	 * the same way it would for one of its built-in prompted actions.
	 *
	 * If a future WooCommerce version changes how it reads that value,
	 * the worst case is the prompt simply has no effect — the PHP side
	 * (ProductFields::handle_bulk_edit()) refuses to touch any prices
	 * when no value was submitted, so this can never wipe data.
	 */
	function initBulkWholesalePricePrompt() {
		document.addEventListener(
			'change',
			function ( event ) {
				var select = event.target;

				if ( ! select || 'SELECT' !== select.tagName ) {
					return;
				}

				var isVariableActionSelect = select.classList.contains( 'variable_actions' ) || 'variable_action' === select.name;

				if ( ! isVariableActionSelect || 'protech_set_wholesale_price' !== select.value ) {
					return;
				}

				var price = window.prompt( protechWholesaleAdmin.bulkPricePrompt );

				if ( null === price || '' === price.trim() ) {
					select.value = '';
					event.stopImmediatePropagation();
					event.preventDefault();
					return;
				}

				// WooCommerce's handler reads the prompted value from a
				// data attribute it sets on itself for its own actions;
				// mirroring that here is what lets our custom action
				// piggyback on its existing AJAX submission.
				select.setAttribute( 'data-protech-value', price );
				select.dataset.value = price;
			},
			true
		);
	}
} )();
