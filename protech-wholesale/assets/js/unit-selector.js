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
 * Store API (`cart/add-item`) rather than the classic `?wc-ajax=add_to_cart`
 * endpoint — that older endpoint only ever reads `product_id`/`quantity`
 * and has no concept of `variation_id`, so it can't add a specific color
 * of a variable product. The REST root and a Store API nonce come from
 * wp_localize_script() (Plugin::enqueue_frontend_assets()), so there's no
 * hardcoded /wp-json/ path and no extra round trip per add; a stale nonce
 * (cached page) is refreshed once from the cart endpoint and retried.
 *
 * After a successful add it (a) asks WooCommerce's cart-fragments script
 * to refresh the mini-cart, if that script is loaded, and (b) dispatches a
 * `protech:cart-changed` DOM event that global-tier-bar.js listens for —
 * so the sticky tier bar updates even on sites where cart-fragments isn't
 * loaded on product pages (WooCommerce stopped loading it there by
 * default in 7.8 unless the classic mini-cart widget is active).
 *
 * If Salient's own "AJAX add to cart" theme option is on, its click
 * handler takes over the button (it prevents the form's submit event) and
 * fires `added_to_cart` itself, which the tier bar also listens for.
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

		var settings = window.ProtechUnitSelector || {};
		var i18n = settings.i18n || {};

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

		function format( template, n ) {
			return String( template || '%d' ).replace( '%d', String( n ) );
		}

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

			showMessage( format( 1 === packs ? i18n.packTotal : i18n.packsTotal, packs ), false );
		}

		function refreshUnitLabels() {
			var displayOption = unitSelect.querySelector( 'option[value="display"]' );
			var caseOption = unitSelect.querySelector( 'option[value="case"]' );

			if ( displayOption ) {
				displayOption.textContent = format( i18n.display, caseSize );
			}

			if ( caseOption ) {
				caseOption.textContent = format( i18n.case, caseSize * displaysPerCase );
			}
		}

		function showMessage( text, isError ) {
			if ( ! hint ) {
				return;
			}

			hint.textContent = text;
			hint.classList.toggle( 'protech-unit-selector-hint--error', !! isError );
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

		if ( form && window.fetch ) {
			form.addEventListener( 'submit', handleSubmit );
		}

		var storeApiNonce = settings.nonce || '';

		function restUrl( path ) {
			return ( settings.restRoot || '/wp-json/wc/store/v1/' ) + path;
		}

		function refreshNonce() {
			return fetch( restUrl( 'cart' ), { credentials: 'same-origin' } ).then( function ( response ) {
				storeApiNonce = response.headers.get( 'Nonce' ) || storeApiNonce;
			} );
		}

		function addItem( itemId, packs, retried ) {
			return fetch( restUrl( 'cart/add-item' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Nonce: storeApiNonce,
				},
				body: JSON.stringify( { id: itemId, quantity: packs } ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, status: response.status, body: body };
					} );
				} )
				.then( function ( result ) {
					var code = ( result.body && result.body.code ) || '';
					var nonceProblem = ! result.ok && ( 401 === result.status || 403 === result.status ) && -1 !== code.indexOf( 'nonce' );

					if ( nonceProblem && ! retried ) {
						return refreshNonce().then( function () {
							return addItem( itemId, packs, true );
						} );
					}

					return result;
				} );
		}

		function handleSubmit( event ) {
			// Defensive re-sync right before submission — belt-and-braces
			// in case some other script's change handler ran after ours
			// and touched the native field in between.
			sync();

			var submitButton = form.querySelector( '.single_add_to_cart_button' );
			var variationField = form.querySelector( 'input[name="variation_id"]' );
			var productField = form.querySelector( '[name="add-to-cart"], [name="product_id"]' );
			var itemId = variationField && parseInt( variationField.value, 10 )
				? parseInt( variationField.value, 10 )
				: ( productField ? parseInt( productField.value, 10 ) : 0 );
			var packs = parseInt( nativeQty.value, 10 );

			if ( ! itemId || ! packs ) {
				return; // Let WooCommerce's own validation handle it.
			}

			event.preventDefault();

			if ( submitButton ) {
				submitButton.classList.add( 'loading' );
				submitButton.disabled = true;
			}

			addItem( itemId, packs, false )
				.then( function ( result ) {
					if ( ! result.ok ) {
						showMessage( ( result.body && result.body.message ) || i18n.addFailed || 'Could not add this to your cart.', true );
						return;
					}

					// Mini-cart refresh via WooCommerce's own mechanism when
					// cart-fragments.js is present ...
					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
					}

					// ... and a direct signal to the sticky tier bar either way.
					document.dispatchEvent( new CustomEvent( 'protech:cart-changed' ) );

					// Reset back to a fresh 1-Display default, matching what
					// a new page load would have started at.
					unitSelect.value = 'display';
					friendlyQty.value = '1';
					sync();
				} )
				.catch( function () {
					// Network/parsing failure — fall back to an ordinary
					// (non-AJAX) submit so the add-to-cart still goes
					// through rather than silently doing nothing.
					form.removeEventListener( 'submit', handleSubmit );
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
