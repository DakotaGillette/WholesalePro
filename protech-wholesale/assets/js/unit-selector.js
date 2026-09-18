/**
 * Single product page: the "Order by: Display / Case" control
 * (class-case-rules.php's render_unit_selector()) always drives the REAL
 * pack-quantity input WooCommerce actually submits — it never introduces
 * a second data field of its own. Hides that real input once this takes
 * over (so the page shows ONE quantity control, not two), keeps it in
 * sync as the unit/quantity change, and re-syncs on WooCommerce's own
 * `found_variation` event so switching color on a variable product still
 * converts correctly even if that color's case size ever differs from
 * another's.
 *
 * What the customer sees: two radio cards (Display / Case), a −/+ stepper
 * with the unit word beside it, a live "3 displays · 30 packs" read-back,
 * and an Add to Cart button that says what it's about to add. The chosen
 * unit is remembered between visits (localStorage), so a buyer who always
 * orders by the case lands on Case.
 *
 * Every change also dispatches a `protech:qty-preview` DOM event carrying
 * the quantity currently dialled in ({ displays, cases }), which
 * global-tier-bar.js draws as a preview segment on the sticky bar.
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
 * After a successful add it (a) confirms it in place ("Added 3 displays
 * (30 packs) to your cart"), (b) asks WooCommerce's cart-fragments script
 * to refresh the mini-cart, if that script is loaded, and (c) dispatches a
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

	var UNIT_STORAGE_KEY = 'protechOrderUnit';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var selector = document.querySelector( '.protech-unit-selector' );

		if ( ! selector ) {
			return;
		}

		var settings = window.ProtechUnitSelector || {};
		var i18n = settings.i18n || {};

		// Radio cards in the shipped template; a <select> is still honoured
		// so a theme's older override of that markup keeps working.
		var unitRadios = Array.prototype.slice.call( selector.querySelectorAll( 'input[name="protech_unit"]' ) );
		var unitSelect = document.getElementById( 'protech-unit-select' );
		var friendlyQty = document.getElementById( 'protech-unit-qty' );
		var nativeQty = document.querySelector( '.protech-native-qty' );
		var hint = document.getElementById( 'protech-unit-selector-hint' );
		var unitWord = document.getElementById( 'protech-unit-word' );

		if ( ( ! unitRadios.length && ! unitSelect ) || ! friendlyQty || ! nativeQty ) {
			return;
		}

		var nativeWrapper = nativeQty.closest( '.quantity' );

		if ( nativeWrapper ) {
			nativeWrapper.classList.add( 'protech-hidden-qty' );
		}

		selector.classList.add( 'is-active' );

		var form = nativeQty.closest( 'form' );
		var submitButton = form ? form.querySelector( '.single_add_to_cart_button' ) : null;
		// Only ever rewrite a plain-text button: if a theme has put its
		// own markup inside it, leave that alone.
		var originalButtonText = submitButton && 0 === submitButton.children.length ? submitButton.textContent : null;

		var caseSize = parseInt( selector.getAttribute( 'data-case-size' ), 10 ) || 1;
		var displaysPerCase = parseInt( selector.getAttribute( 'data-displays-per-case' ), 10 ) || 1;
		var messageTimer = null;

		// Replaces %d / %s, or numbered %1$d / %2$s placeholders, in order.
		function format( template, values ) {
			var list = Array.isArray( values ) ? values : [ values ];
			var next = 0;

			return String( template || '' ).replace( /%(?:(\d+)\$)?[ds]/g, function ( match, position ) {
				var index = position ? parseInt( position, 10 ) - 1 : next++;

				return undefined === list[ index ] ? match : String( list[ index ] );
			} );
		}

		function plural( count, one, many ) {
			return format( 1 === count ? one : many, count );
		}

		function getUnit() {
			var checked = unitRadios.filter( function ( radio ) {
				return radio.checked;
			} )[ 0 ];

			if ( checked ) {
				return checked.value;
			}

			return unitSelect ? unitSelect.value : 'display';
		}

		function setUnit( unit ) {
			unitRadios.forEach( function ( radio ) {
				radio.checked = radio.value === unit;
			} );

			if ( unitSelect ) {
				unitSelect.value = unit;
			}
		}

		function packsPerUnit() {
			return 'case' === getUnit() ? caseSize * displaysPerCase : caseSize;
		}

		function getCount() {
			var count = parseInt( friendlyQty.value, 10 );

			return count && count > 0 ? count : 0;
		}

		// "3 displays" / "1 case" — the quantity in the unit it was entered in.
		function describeUnits( count ) {
			return 'case' === getUnit()
				? plural( count, i18n.caseOne || '%d case', i18n.caseMany || '%d cases' )
				: plural( count, i18n.displayOne || '%d display', i18n.displayMany || '%d displays' );
		}

		function describePacks( packs ) {
			return plural( packs, i18n.packOne || '%d pack', i18n.packMany || '%d packs' );
		}

		function showMessage( text, state ) {
			if ( ! hint ) {
				return;
			}

			hint.textContent = text;
			hint.classList.toggle( 'protech-unit-selector-hint--error', 'error' === state );
			hint.classList.toggle( 'protech-unit-selector-hint--success', 'success' === state );
		}

		// The read-back line, the unit word beside the stepper, and the
		// button label — everything that restates the current quantity.
		function renderSummary() {
			var count = getCount() || 1;
			var packs = count * packsPerUnit();
			var parts = [ describeUnits( count ) ];

			if ( 'case' === getUnit() ) {
				parts.push( plural( count * displaysPerCase, i18n.displayOne || '%d display', i18n.displayMany || '%d displays' ) );
			}

			parts.push( describePacks( packs ) );

			if ( messageTimer ) {
				clearTimeout( messageTimer );
				messageTimer = null;
			}

			showMessage( parts.join( ' · ' ), null );

			if ( unitWord ) {
				unitWord.textContent = 'case' === getUnit()
					? ( 1 === count ? i18n.wordCaseOne || 'case' : i18n.wordCaseMany || 'cases' )
					: ( 1 === count ? i18n.wordDisplayOne || 'display' : i18n.wordDisplayMany || 'displays' );
			}

			if ( submitButton && null !== originalButtonText ) {
				submitButton.textContent = format( i18n.addButton || 'Add %s to cart', describeUnits( count ) );
			}
		}

		function announcePreview() {
			var packs = ( getCount() || 1 ) * packsPerUnit();

			document.dispatchEvent(
				new CustomEvent( 'protech:qty-preview', {
					detail: {
						displays: packs / caseSize,
						cases: packs / ( caseSize * displaysPerCase ),
					},
				} )
			);
		}

		function sync() {
			var count = getCount();

			if ( ! count ) {
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

			renderSummary();
			announcePreview();
		}

		function refreshUnitLabels() {
			var displayMeta = selector.querySelector( '[data-protech-unit-meta="display"]' );
			var caseMeta = selector.querySelector( '[data-protech-unit-meta="case"]' );

			if ( displayMeta ) {
				displayMeta.textContent = describePacks( caseSize );
			}

			if ( caseMeta ) {
				caseMeta.textContent = format( i18n.caseMeta || '%1$d displays · %2$d packs', [ displaysPerCase, caseSize * displaysPerCase ] );
			}

			if ( unitSelect ) {
				var displayOption = unitSelect.querySelector( 'option[value="display"]' );
				var caseOption = unitSelect.querySelector( 'option[value="case"]' );

				if ( displayOption ) {
					displayOption.textContent = format( i18n.display || 'Display (%d packs)', caseSize );
				}

				if ( caseOption ) {
					caseOption.textContent = format( i18n.case || 'Case (%d packs)', caseSize * displaysPerCase );
				}
			}
		}

		function rememberUnit() {
			try {
				window.localStorage.setItem( UNIT_STORAGE_KEY, getUnit() );
			} catch ( e ) {
				// Storage blocked: the choice simply isn't remembered.
			}
		}

		function restoreUnit() {
			try {
				var stored = window.localStorage.getItem( UNIT_STORAGE_KEY );

				if ( 'case' === stored || 'display' === stored ) {
					setUnit( stored );
				}
			} catch ( e ) {
				// As above.
			}
		}

		function onUnitChange() {
			rememberUnit();
			sync();
		}

		unitRadios.forEach( function ( radio ) {
			radio.addEventListener( 'change', onUnitChange );
		} );

		if ( unitSelect ) {
			unitSelect.addEventListener( 'change', onUnitChange );
		}

		friendlyQty.addEventListener( 'input', function () {
			// Let someone clear the field to retype without it snapping
			// back to 1 mid-keystroke; blur (below) tidies an empty value.
			if ( '' === friendlyQty.value ) {
				return;
			}

			sync();
		} );

		friendlyQty.addEventListener( 'blur', sync );

		Array.prototype.forEach.call( selector.querySelectorAll( '[data-protech-step]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var step = parseInt( button.getAttribute( 'data-protech-step' ), 10 ) || 0;

				friendlyQty.value = String( Math.max( 1, ( getCount() || 1 ) + step ) );
				sync();
			} );
		} );

		// global-tier-bar.js may start listening after our first sync().
		document.addEventListener( 'protech:qty-preview-request', announcePreview );

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

		function flashButton() {
			if ( ! submitButton ) {
				return;
			}

			submitButton.classList.add( 'protech-added' );

			window.setTimeout( function () {
				submitButton.classList.remove( 'protech-added' );
			}, 1600 );
		}

		function handleSubmit( event ) {
			// Defensive re-sync right before submission — belt-and-braces
			// in case some other script's change handler ran after ours
			// and touched the native field in between.
			sync();

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

			// Captured now: the control resets to 1 as soon as the add lands.
			var addedUnits = describeUnits( getCount() || 1 );
			var addedPacks = describePacks( packs );

			if ( submitButton ) {
				submitButton.classList.add( 'loading' );
				submitButton.disabled = true;
			}

			addItem( itemId, packs, false )
				.then( function ( result ) {
					if ( ! result.ok ) {
						showMessage( ( result.body && result.body.message ) || i18n.addFailed || 'Could not add this to your cart.', 'error' );
						return;
					}

					// Mini-cart refresh via WooCommerce's own mechanism when
					// cart-fragments.js is present ...
					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
					}

					// ... and a direct signal to the sticky tier bar either way.
					document.dispatchEvent( new CustomEvent( 'protech:cart-changed' ) );

					// Back to a quantity of 1, but in the unit they were
					// ordering in — then say what just happened, and fall
					// back to the normal read-back line a few seconds later.
					friendlyQty.value = '1';
					sync();

					showMessage( format( i18n.added || 'Added %1$s (%2$s) to your cart.', [ addedUnits, addedPacks ] ), 'success' );
					flashButton();

					messageTimer = window.setTimeout( renderSummary, 4500 );
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

		restoreUnit();
		refreshUnitLabels();
		sync();
	}
} )();
