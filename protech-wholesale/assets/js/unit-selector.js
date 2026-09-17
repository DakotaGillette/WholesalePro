/**
 * Single product page: the "Order by: Display / Case" convenience
 * control (class-case-rules.php's render_unit_selector()) always drives
 * the REAL pack-quantity input WooCommerce actually submits — it never
 * introduces a second data field of its own. Hides that real input once
 * this takes over, keeps it in sync as the friendly unit/quantity fields
 * change, and re-syncs on WooCommerce's own `found_variation` event so
 * switching color on a variable product still converts correctly even
 * if that color's case size ever differs from another's.
 *
 * Also AJAXifies this form's Add to Cart submission, via the WooCommerce
 * Store API (`/wp-json/wc/store/v1/cart/add-item`) rather than the
 * classic `?wc-ajax=add_to_cart` endpoint — that older endpoint only
 * ever reads `product_id`/`quantity` from the request and has no concept
 * of `variation_id` at all, so it can't actually add a specific color of
 * a variable product like this one (confirmed live: it returns a bare
 * `{error:true}` with no notice, for exactly that reason). WooCommerce's
 * single product page does a full page reload on submit by default
 * (unlike the shop loop's own Add to Cart buttons, which are AJAX out of
 * the box) — so without this, the sticky global tier bar
 * (global-tier-bar.js) would only ever see the new cart state after a
 * full navigation, not update in place the way it does everywhere else.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var selector = document.querySelector( '.protech-unit-selector' );

		if ( ! selector ) {
			return;
		}

		var unitSelect = document.getElementById( 'protech-unit-select' );
		var friendlyQty = document.getElementById( 'protech-unit-qty' );
		var nativeQty = document.querySelector( '.protech-native-qty' );
		var hint = document.getElementById( 'protech-unit-selector-hint' );

		if ( ! unitSelect || ! friendlyQty || ! nativeQty ) {
			return;
		}

		var nativeWrapper = nativeQty.closest( '.quantity' );

		if ( nativeWrapper ) {
			nativeWrapper.classList.add( 'protech-hidden-qty' );
		}

		var caseSize = parseInt( selector.getAttribute( 'data-case-size' ), 10 ) || 1;
		var displaysPerCase = parseInt( selector.getAttribute( 'data-displays-per-case' ), 10 ) || 1;

		function packsPerUnit() {
			return 'case' === unitSelect.value ? caseSize * displaysPerCase : caseSize;
		}

		function sync() {
			var count = parseInt( friendlyQty.value, 10 );

			if ( ! count || count < 1 ) {
				count = 1;
				friendlyQty.value = '1';
			}

			var packs = count * packsPerUnit();

			nativeQty.value = String( packs );
			nativeQty.setAttribute( 'min', String( packsPerUnit() ) );
			nativeQty.setAttribute( 'step', String( packsPerUnit() ) );

			// A plain change event so any other script watching the
			// native field (WooCommerce's own totals preview, if the
			// theme adds one) still notices the new value.
			nativeQty.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			if ( hint ) {
				hint.textContent = packs + ( 1 === packs
					? ' pack total'
					: ' packs total' );
			}
		}

		function refreshUnitLabels() {
			var displayOption = unitSelect.querySelector( 'option[value="display"]' );
			var caseOption = unitSelect.querySelector( 'option[value="case"]' );

			if ( displayOption ) {
				displayOption.textContent = 'Display (' + caseSize + ' packs)';
			}

			if ( caseOption ) {
				caseOption.textContent = 'Case (' + ( caseSize * displaysPerCase ) + ' packs)';
			}
		}

		unitSelect.addEventListener( 'change', sync );
		friendlyQty.addEventListener( 'input', sync );

		// Variable products: WooCommerce's own add-to-cart-variation.js
		// fires this jQuery event on the surrounding .variations_form
		// once a full attribute combination (e.g. a color) resolves to a
		// specific variation. add_unit_data_to_variation() put our own
		// case-size fields on that same variation payload, so this stays
		// correct per color without any extra request.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'found_variation', '.variations_form', function ( event, variation ) {
				if ( variation && variation.protech_case_size ) {
					caseSize = parseInt( variation.protech_case_size, 10 ) || caseSize;
					displaysPerCase = parseInt( variation.protech_displays_per_case, 10 ) || displaysPerCase;
					selector.setAttribute( 'data-case-size', String( caseSize ) );
					selector.setAttribute( 'data-displays-per-case', String( displaysPerCase ) );
					refreshUnitLabels();
					sync();
				}
			} );
		}

		var form = nativeQty.closest( 'form' );

		if ( form ) {
			form.addEventListener( 'submit', handleSubmit );
		}

		function showMessage( text, isError ) {
			if ( ! hint ) {
				return;
			}

			hint.textContent = text;
			hint.classList.toggle( 'protech-unit-selector-hint--error', !! isError );
		}

		function handleSubmit( event ) {
			// Defensive re-sync right before submission — belt-and-braces
			// in case some other script's change handler ran after ours
			// and touched the native field in between.
			sync();

			if ( ! window.jQuery ) {
				return; // No AJAX contract available — fall back to the normal full-page submit.
			}

			event.preventDefault();

			var submitButton = form.querySelector( '.single_add_to_cart_button' );
			var variationField = form.querySelector( 'input[name="variation_id"]' );
			var productField = form.querySelector( '[name="add-to-cart"], [name="product_id"]' );
			var itemId = variationField && parseInt( variationField.value, 10 )
				? parseInt( variationField.value, 10 )
				: ( productField ? parseInt( productField.value, 10 ) : 0 );
			var packs = parseInt( nativeQty.value, 10 );

			if ( ! itemId || ! packs ) {
				return;
			}

			if ( submitButton ) {
				submitButton.classList.add( 'loading' );
				submitButton.disabled = true;
			}

			fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.headers.get( 'Nonce' ) || '';
				} )
				.then( function ( nonce ) {
					return fetch( '/wp-json/wc/store/v1/cart/add-item', {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							Nonce: nonce,
						},
						body: JSON.stringify( { id: itemId, quantity: packs } ),
					} );
				} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						showMessage( ( result.body && result.body.message ) || 'Could not add this to your cart.', true );
						return;
					}

					// Tells WooCommerce's own cart-fragments.js to refresh
					// the mini-cart, etc.; it fires `wc_fragments_refreshed`
					// once done, which global-tier-bar.js already listens
					// for to refresh itself with the new cart state.
					window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );

					// No separate "added" message here — the sticky tier
					// bar updating live, plus the reset quantity below, is
					// already clear confirmation, and avoids this getting
					// immediately overwritten by sync()'s own hint text.
					// Reset back to a fresh 1-Display default, matching
					// what a new page load would have started at.
					unitSelect.value = 'display';
					friendlyQty.value = '1';
					sync();
				} )
				.catch( function () {
					// Network/parsing failure — fall back to an ordinary
					// (non-AJAX) submit so the add-to-cart still goes
					// through rather than silently doing nothing.
					form.submit();
				} )
				.then( function () {
					if ( submitButton ) {
						submitButton.classList.remove( 'loading' );
						submitButton.disabled = false;
					}
				} );
		}

		sync();
	}
} )();
