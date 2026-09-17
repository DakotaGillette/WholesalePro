/**
 * Sticky global tier bar: refreshes via AJAX whenever the cart changes
 * anywhere on the site — WooCommerce's classic AJAX add-to-cart (shop
 * loop, and the single product page once unit-selector.js AJAXifies it)
 * fires a jQuery `added_to_cart`/`removed_from_cart` event on
 * document.body; the WooCommerce Blocks cart data store (used by the
 * Cart/Checkout/Mini-Cart blocks) is polled via `wp.data.subscribe()`
 * when present. Either path calls the same server-computed state
 * (VolumePricing::get_tier_bar_state()) so the math only lives in one
 * place. Vanilla JS except for the jQuery event listeners WooCommerce
 * itself requires jQuery for. Never dismissible — always visible once
 * rendered, by design.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var bar = document.getElementById( 'protech-global-tier-bar' );

		if ( ! bar ) {
			return;
		}

		// Salient wraps the whole page (#ajax-content-wrap / .ocm-effect-wrap)
		// for its AJAX page-transition/scroll effects, which apply a CSS
		// transform to that wrapper — and a `position: fixed` descendant of
		// a transformed ancestor is positioned relative to THAT ancestor
		// instead of the viewport, not truly fixed on screen. Re-parenting
		// straight onto <body> sidesteps that regardless of exactly which
		// ancestor (now, or after a future theme update) is transformed.
		if ( bar.parentElement !== document.body ) {
			document.body.appendChild( bar );
		}

		// Reserve space at the bottom of the page so the bar doesn't
		// permanently cover a theme's own fixed footer content (e.g. a
		// mobile sticky Add to Cart button). The bar itself is never
		// dismissible — deliberate, so a wholesale customer can't lose
		// track of their progress toward better pricing/free shipping.
		function reserveSpace() {
			document.body.style.paddingBottom = bar.offsetHeight + 'px';
		}

		reserveSpace();
		window.addEventListener( 'resize', reserveSpace );

		var refreshTimer = null;

		function scheduleRefresh() {
			// Debounce — WooCommerce can fire its cart events more than
			// once per user action (e.g. fragment refresh + the trigger
			// itself).
			if ( refreshTimer ) {
				clearTimeout( refreshTimer );
			}

			refreshTimer = setTimeout( refresh, 250 );
		}

		function refresh() {
			var settings = window.ProtechGlobalTierBar || {};

			if ( ! settings.ajaxUrl ) {
				return;
			}

			var params = new URLSearchParams();
			params.set( 'action', 'protech_global_tier_bar_state' );
			params.set( 'nonce', settings.nonce || '' );

			fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: params.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success && json.data ) {
						applyState( json.data );
					}
				} )
				.catch( function () {
					// A missed refresh just leaves the bar at its last known
					// state; the next cart action will retry.
				} );
		}

		function applyState( state ) {
			bar.setAttribute( 'data-active-tier', state.tier );

			var fill = document.getElementById( 'protech-global-tier-bar-fill' );
			var message = document.getElementById( 'protech-global-tier-bar-message' );
			var stats = document.getElementById( 'protech-global-tier-bar-stats' );
			var subtotal = document.getElementById( 'protech-global-tier-bar-subtotal' );

			if ( fill ) {
				fill.style.width = state.fill_percent + '%';
			}

			if ( message ) {
				message.textContent = state.message;
			}

			if ( stats ) {
				stats.textContent = state.stats;
			}

			if ( subtotal ) {
				// Pre-formatted server-side (wc_price()) so this stays
				// correct for the store's actual currency/decimal format
				// without duplicating that formatting logic in JS.
				subtotal.innerHTML = state.subtotal_html;
			}

			reserveSpace();
		}

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'added_to_cart removed_from_cart wc_fragments_refreshed', scheduleRefresh );
		}

		// WooCommerce Blocks (Mini-Cart/Cart/Checkout) manage cart state
		// through a wp.data store instead of the classic jQuery events —
		// subscribe when it's present so add/remove actions taken through
		// a block also update this bar.
		if ( window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function' ) {
			var lastCartToken = null;

			window.wp.data.subscribe( function () {
				var store = window.wp.data.select( 'wc/store/cart' );

				if ( ! store ) {
					return;
				}

				var cartData = store.getCartData ? store.getCartData() : null;
				var token = cartData ? JSON.stringify( cartData.itemsCount ) + cartData.cartHash : null;

				if ( token && token !== lastCartToken ) {
					lastCartToken = token;
					scheduleRefresh();
				}
			} );
		}
	}
} )();
