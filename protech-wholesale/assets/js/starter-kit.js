/**
 * Starter kit product page (templates/starter-kit.php, class-starter-kit.php).
 *
 * The panel already works as a plain form post. This takes it over to:
 *  - step the number of kits and re-quote it from the server on every
 *    change — the price is never worked out here, because the tier (and so
 *    the per-pack price) depends on what else is in the cart and on how
 *    many kits are being added;
 *  - add the kit in place over admin-ajax, say so beside the button, and
 *    tell the rest of the page the cart changed (`protech:cart-changed`
 *    for the sticky tier bar, `wc_fragment_refresh` for the mini-cart);
 *  - announce the quantity dialled in as `protech:qty-preview`, so the
 *    sticky bar previews where the kit would take the cart — for a
 *    16-display kit, straight onto the Volume marker.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var kit = document.querySelector( '.protech-kit[data-kit-id]' );
		var settings = window.ProtechStarterKit || {};

		if ( ! kit || ! settings.ajaxUrl || ! window.fetch ) {
			return;
		}

		var i18n = settings.i18n || {};
		var form = kit.querySelector( '.protech-kit-form' );
		var qty = document.getElementById( 'protech-kit-qty' );
		var word = document.getElementById( 'protech-kit-word' );
		var button = document.getElementById( 'protech-kit-add' );
		var message = document.getElementById( 'protech-kit-message' );
		var summary = document.getElementById( 'protech-kit-summary' );
		var tierNote = document.getElementById( 'protech-kit-tier-note' );

		if ( ! form || ! qty || ! button ) {
			return;
		}

		var kitId = kit.getAttribute( 'data-kit-id' );
		var displaysPerKit = parseFloat( kit.getAttribute( 'data-displays' ) ) || 0;
		var casesPerKit = parseFloat( kit.getAttribute( 'data-cases' ) ) || 0;
		var maxKits = parseInt( qty.getAttribute( 'max' ), 10 ) || 50;
		var quoteTimer = null;
		var quoteRequest = 0;
		var messageTimer = null;

		function getCount() {
			var count = parseInt( qty.value, 10 );

			return count && count > 0 ? Math.min( count, maxKits ) : 0;
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
			message.classList.toggle( 'protech-kit-message--error', 'error' === state );
			message.classList.toggle( 'protech-kit-message--success', 'success' === state );
		}

		function announcePreview() {
			var count = getCount() || 1;

			document.dispatchEvent(
				new CustomEvent( 'protech:qty-preview', {
					detail: { displays: displaysPerKit * count, cases: casesPerKit * count },
				} )
			);
		}

		function request( action, kits ) {
			var params = new URLSearchParams();

			params.set( 'action', action );
			params.set( 'nonce', settings.nonce || '' );
			params.set( 'kit_id', kitId );
			params.set( 'kits', String( kits ) );

			return fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString(),
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		// Server-formatted (wc_price(), entities intact), hence innerHTML —
		// the same trust as the tier bar's subtotal.
		function applyQuote( quote ) {
			if ( ! quote ) {
				return;
			}

			Array.prototype.forEach.call( document.querySelectorAll( '[data-protech-kit-total]' ), function ( node ) {
				// The price beside the title is always for one kit.
				if ( node.closest( '.protech-kit' ) || 1 === quote.kits ) {
					node.innerHTML = quote.total_html;
				}
			} );

			if ( summary ) {
				summary.textContent = quote.summary;
			}

			if ( tierNote ) {
				tierNote.textContent = quote.note;
			}

			if ( quote.kits && quote.displays ) {
				displaysPerKit = quote.displays / quote.kits;
				casesPerKit = quote.cases / quote.kits;
			}
		}

		function refreshQuote() {
			var ticket = ++quoteRequest;

			request( 'protech_kit_quote', getCount() || 1 )
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

		function onQuantityChange() {
			var count = getCount() || 1;

			if ( word ) {
				word.textContent = 1 === count ? i18n.kitOne || 'kit' : i18n.kitMany || 'kits';
			}

			button.textContent = 1 === count
				? i18n.addOne || 'Add starter kit to cart'
				: String( i18n.addMany || 'Add %d starter kits to cart' ).replace( '%d', String( count ) );

			announcePreview();

			if ( quoteTimer ) {
				clearTimeout( quoteTimer );
			}

			quoteTimer = window.setTimeout( refreshQuote, 250 );
		}

		qty.addEventListener( 'input', function () {
			if ( '' !== qty.value ) {
				onQuantityChange();
			}
		} );

		qty.addEventListener( 'blur', function () {
			qty.value = String( getCount() || 1 );
			onQuantityChange();
		} );

		Array.prototype.forEach.call( kit.querySelectorAll( '[data-protech-step]' ), function ( stepper ) {
			stepper.addEventListener( 'click', function () {
				var step = parseInt( stepper.getAttribute( 'data-protech-step' ), 10 ) || 0;

				qty.value = String( Math.min( maxKits, Math.max( 1, ( getCount() || 1 ) + step ) ) );
				onQuantityChange();
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var count = getCount() || 1;

			button.classList.add( 'loading' );
			button.disabled = true;

			request( 'protech_add_kit', count )
				.then( function ( json ) {
					var data = ( json && json.data ) || {};
					var notes = Array.isArray( data.notes ) && data.notes.length ? ' ' + data.notes.join( ' ' ) : '';

					if ( ! json || ! json.success ) {
						showMessage( ( data.message || i18n.addFailed || 'Could not add the starter kit to your cart.' ) + notes, 'error' );
						return;
					}

					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
					}

					document.dispatchEvent( new CustomEvent( 'protech:cart-changed' ) );

					qty.value = '1';
					onQuantityChange();
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
					// which adds the kit server-side and lands on the cart.
					form.submit();
				} )
				.then( function () {
					button.classList.remove( 'loading' );
					button.disabled = false;
				} );
		} );

		// The tier bar may have started listening after our first announce,
		// and a cart change anywhere else on the page re-prices the kit.
		document.addEventListener( 'protech:qty-preview-request', announcePreview );
		document.addEventListener( 'protech:tier-state', function () {
			if ( quoteTimer ) {
				clearTimeout( quoteTimer );
			}

			quoteTimer = window.setTimeout( refreshQuote, 150 );
		} );

		announcePreview();
	}
} )();
