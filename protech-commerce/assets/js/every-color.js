/**
 * "Add one display of every color" (templates/add-every-color.php,
 * StarterKit::render_every_color()).
 *
 * The block already works as a plain form post. This takes it over to add in
 * place over admin-ajax, say what happened beside the button, and tell the
 * rest of the page the cart changed (`protech:cart-changed` for the sticky
 * tier bar, `wc_fragment_refresh` for the mini-cart). It also re-quotes the
 * price when the cart's tier changes, because the per-pack price for these
 * displays depends on what else is in the cart. The price is never worked out
 * here.
 *
 * Shares the kit endpoints (protech_kit_quote, protech_add_kit): the product
 * itself is the "kit".
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var block = document.querySelector( '.protech-everycolor[data-product-id]' );
		var settings = window.ProtechEveryColor || {};

		if ( ! block || ! settings.ajaxUrl || ! window.fetch ) {
			return;
		}

		var i18n = settings.i18n || {};
		var form = block.querySelector( '.protech-everycolor-form' );
		var button = block.querySelector( '.protech-everycolor-add' );
		var message = block.querySelector( '.protech-everycolor-message' );
		var total = block.querySelector( '[data-protech-everycolor-total]' );
		var note = block.querySelector( '[data-protech-everycolor-note]' );
		var productId = block.getAttribute( 'data-product-id' );
		var messageTimer = null;
		var quoteTimer = null;
		var quoteRequest = 0;

		if ( ! form || ! button ) {
			return;
		}

		function request( action ) {
			var params = new URLSearchParams();

			params.set( 'action', action );
			params.set( 'nonce', settings.nonce || '' );
			params.set( 'kit_id', productId );
			params.set( 'kits', '1' );

			return fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString(),
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		function showMessage( text, state ) {
			if ( ! message ) {
				return;
			}

			if ( messageTimer ) {
				clearTimeout( messageTimer );
				messageTimer = null;
			}

			message.textContent = text;
			message.classList.toggle( 'protech-everycolor-message--error', 'error' === state );
			message.classList.toggle( 'protech-everycolor-message--success', 'success' === state );
		}

		// Server-formatted (wc_price(), entities intact), hence innerHTML, the
		// same trust as the tier bar's subtotal.
		function applyQuote( quote ) {
			if ( ! quote ) {
				return;
			}

			if ( total ) {
				total.innerHTML = quote.total_html;
			}

			if ( note ) {
				note.textContent = quote.note;
			}
		}

		function refreshQuote() {
			var ticket = ++quoteRequest;

			request( 'protech_kit_quote' )
				.then( function ( json ) {
					// A slower, older answer must not overwrite a newer one.
					if ( ticket === quoteRequest && json && json.success ) {
						applyQuote( json.data );
					}
				} )
				.catch( function () {
					// The last quote stays on screen; the cart is the authority anyway.
				} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			button.classList.add( 'loading' );
			button.disabled = true;

			request( 'protech_add_kit' )
				.then( function ( json ) {
					var data = ( json && json.data ) || {};
					var notes = Array.isArray( data.notes ) && data.notes.length ? ' ' + data.notes.join( ' ' ) : '';

					if ( ! json || ! json.success ) {
						showMessage( ( data.message || i18n.addFailed || 'Could not add these to your cart.' ) + notes, 'error' );
						return;
					}

					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
					}

					document.dispatchEvent( new CustomEvent( 'protech:cart-changed' ) );

					applyQuote( data.quote );
					showMessage( data.message + notes, 'success' );
					button.classList.add( 'protech-added' );

					messageTimer = window.setTimeout( function () {
						showMessage( '', null );
						button.classList.remove( 'protech-added' );
					}, 6000 );
				} )
				.catch( function () {
					// Anything unexpected: fall back to the ordinary form post,
					// which adds them server-side and lands on the cart.
					form.submit();
				} )
				.then( function () {
					button.classList.remove( 'loading' );
					button.disabled = false;
				} );
		} );

		// A cart change anywhere else on the page can move the tier, and with it the price.
		document.addEventListener( 'protech:tier-state', function () {
			if ( quoteTimer ) {
				clearTimeout( quoteTimer );
			}

			quoteTimer = window.setTimeout( refreshQuote, 150 );
		} );
	}
} )();
