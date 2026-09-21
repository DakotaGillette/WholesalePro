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
 * On top of that refresh loop this script owns the bar's motion:
 *  - a one-off slide-in per browser session;
 *  - a short "tick" on any number that changed, and a bump on the cart
 *    button;
 *  - the tier-unlock celebration (marker pop, sheen, confetti) whenever
 *    the tier goes UP — including when that happened through a full page
 *    reload (reorder, a non-AJAX add), tracked in sessionStorage so it
 *    fires exactly once and never replays on the next page;
 *  - a paler "ghost" segment previewing where the quantity currently
 *    dialled into unit-selector.js would land (`protech:qty-preview`).
 *    That preview is the ONE piece of math mirrored here (scalePercent(),
 *    from VolumePricing::scale_percent()); it is purely visual, and the
 *    real fill always comes from the server.
 * Everything animated is skipped under prefers-reduced-motion.
 *
 * And its placement: the dock's edges are measured off the site header's
 * inner container so the two are the same width, and the room reserved
 * for it at the end of the page is a spacer colored to match whatever
 * the page ends with (see alignToHeader() / paintSpacer()).
 *
 * It also re-broadcasts each new state as a `protech:tier-state` DOM
 * event and keeps the product page's price table (templates/
 * tier-ladder.php) pointing at the right row, which used to go stale
 * after an AJAX add until the next reload.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	var TIER_RANK = { standard: 0, volume: 1, bulk: 2 };
	var SEEN_TIER_KEY = 'protechTierSeen';
	var ENTERED_KEY = 'protechTierBarEntered';
	var CONFETTI_COLORS = [ '#ffffff', '#a9c2ea', '#ffd166', '#dbe7f8', '#ffe9a8' ];

	document.addEventListener( 'DOMContentLoaded', init );

	function rank( tier ) {
		return Object.prototype.hasOwnProperty.call( TIER_RANK, tier ) ? TIER_RANK[ tier ] : 0;
	}

	function storageGet( key ) {
		try {
			return window.sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function storageSet( key, value ) {
		try {
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {
			// Private mode / blocked storage: the bar just forgets between pages.
		}
	}

	function init() {
		var bar = document.getElementById( 'protech-global-tier-bar' );

		if ( ! bar ) {
			return;
		}

		var reducedMotion = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

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

		document.body.classList.add( 'protech-has-tier-bar' );

		var fill = document.getElementById( 'protech-global-tier-bar-fill' );
		var ghost = document.getElementById( 'protech-global-tier-bar-ghost' );
		var message = document.getElementById( 'protech-global-tier-bar-message' );
		var stats = document.getElementById( 'protech-global-tier-bar-stats' );
		var tierLabel = document.getElementById( 'protech-global-tier-bar-tier' );
		var savings = document.getElementById( 'protech-global-tier-bar-savings' );
		var savingsAmount = document.getElementById( 'protech-global-tier-bar-savings-amount' );
		var cta = document.getElementById( 'protech-global-tier-bar-cta' );

		// Last state the server gave us (the template seeds the first one).
		var current = readInitialState();
		// The add unit-selector.js currently has dialled in, if any.
		var pending = null;

		function readInitialState() {
			var parsed = {};

			try {
				parsed = JSON.parse( bar.getAttribute( 'data-state' ) || '{}' ) || {};
			} catch ( e ) {
				parsed = {};
			}

			parsed.tier = parsed.tier || bar.getAttribute( 'data-active-tier' ) || 'standard';

			return parsed;
		}

		var barSettings = window.ProtechGlobalTierBar || {};

		// -- Same width as the site header -------------------------------
		// Two kinds of header, two answers:
		//  - a header that is itself a floating card narrower than the
		//    viewport (this site: Salient sizes #header-outer from its
		//    --container-width/--container-padding variables) — match the
		//    card's outer edges exactly;
		//  - a full-bleed header — match the content box of the container
		//    inside it (logo's left edge to the cart icon's right edge).
		// Measured rather than assumed, so it follows whatever the theme
		// does at each breakpoint. The stylesheet carries a variables-based
		// fallback of the same width for before this runs. No match, a
		// hidden header, or a phone-width screen (where the stylesheet makes
		// the bar edge-to-edge) all leave that fallback alone.
		//
		// The default lives here as well as in PHP on purpose: the first
		// version took it only from the localized settings and quietly did
		// nothing when that key was missing. Only an explicit '' turns
		// alignment off.
		var DEFAULT_ALIGN_TO = '#header-outer, .site-header, #masthead, header[role="banner"], body > header';
		var alignSelector = 'string' === typeof barSettings.alignTo ? barSettings.alignTo : DEFAULT_ALIGN_TO;
		var alignTarget = null;

		try {
			alignTarget = alignSelector ? document.querySelector( alignSelector ) : null;
		} catch ( e ) {
			alignTarget = null; // A filtered-in selector that doesn't parse.
		}

		function contentBox( element ) {
			var rect = element.getBoundingClientRect();
			var style = window.getComputedStyle( element );
			var padLeft = ( parseFloat( style.paddingLeft ) || 0 ) + ( parseFloat( style.borderLeftWidth ) || 0 );
			var padRight = ( parseFloat( style.paddingRight ) || 0 ) + ( parseFloat( style.borderRightWidth ) || 0 );

			return { left: rect.left + padLeft, width: rect.width - padLeft - padRight };
		}

		function headerBox() {
			if ( ! alignTarget ) {
				return null;
			}

			var viewport = document.documentElement.clientWidth || window.innerWidth;
			var rect = alignTarget.getBoundingClientRect();

			if ( rect.width <= 0 ) {
				return null;
			}

			if ( rect.width < viewport - 8 ) {
				return { left: rect.left, width: rect.width };
			}

			var inner = contentBox( alignTarget.querySelector( '.container' ) || alignTarget );

			// Still edge to edge: there is nothing narrower to line up with.
			return inner.width < viewport - 8 ? inner : null;
		}

		function alignToHeader() {
			var phone = !! ( window.matchMedia && window.matchMedia( '(max-width: 680px)' ).matches );
			var box = phone ? null : headerBox();
			var left = box ? box.left : 0;
			var width = box ? box.width : 0;

			if ( width < 320 ) {
				bar.style.left = '';
				bar.style.right = '';
				bar.style.width = '';
				bar.style.maxWidth = '';
				bar.style.margin = '';

				return;
			}

			bar.style.left = Math.round( left ) + 'px';
			bar.style.right = 'auto';
			bar.style.width = Math.round( width ) + 'px';
			bar.style.maxWidth = 'none';
			bar.style.margin = '0';
		}

		// -- Room at the end of the page ----------------------------------
		// So the bar never permanently covers the last thing on a page
		// (footer links, a theme's own mobile sticky Add to Cart). The bar
		// itself is never dismissible — deliberate, so a wholesale customer
		// can't lose track of their progress toward better pricing/free
		// shipping.
		//
		// This used to be padding-bottom on <body>, which paints the BODY's
		// background: a white band under this site's black footer. It is
		// now a spacer element that takes the color of whatever the page
		// actually ends with. That is sampled from the live page
		// (elementsFromPoint just above the spacer) rather than read off a
		// known footer element, because the visible footer here is a
		// page-builder "global section" whose black comes from an inner
		// background layer — the theme's own #footer-outer is empty — and
		// other pages and themes will differ again.
		var spacer = document.createElement( 'div' );

		spacer.className = 'protech-tier-bar-spacer';
		spacer.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( spacer );

		// A fully opaque background color, or '' — a see-through layer
		// (a 30% overlay, say) isn't what the eye reads as the page color.
		function solidBackground( element ) {
			var style = window.getComputedStyle( element );

			if ( 'hidden' === style.visibility || parseFloat( style.opacity ) < 0.95 ) {
				return '';
			}

			var match = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s\/]+([\d.]+)(%?))?\s*\)$/.exec( style.backgroundColor );

			if ( ! match ) {
				return '';
			}

			var alpha = undefined === match[ 4 ] ? 1 : parseFloat( match[ 4 ] ) / ( match[ 5 ] ? 100 : 1 );

			return alpha >= 0.95 ? 'rgb(' + match[ 1 ] + ', ' + match[ 2 ] + ', ' + match[ 3 ] + ')' : '';
		}

		function paintSpacer() {
			if ( barSettings.spacerColor ) {
				spacer.style.backgroundColor = barSettings.spacerColor;
				return;
			}

			if ( 'function' !== typeof document.elementsFromPoint ) {
				return;
			}

			var top = spacer.getBoundingClientRect().top;

			// Only points inside the viewport can be sampled. While the
			// spacer is still just below the fold, sample the viewport's
			// bottom edge instead — by then that is already the footer — so
			// the color is in place before the spacer scrolls into view.
			if ( top - window.innerHeight > 150 ) {
				return;
			}

			var y = Math.min( window.innerHeight - 2, top - 3 );

			if ( y < 0 ) {
				return;
			}

			var columns = [ 0.5, 0.25, 0.75 ];
			var c;
			var s;

			for ( c = 0; c < columns.length; c++ ) {
				var stack = document.elementsFromPoint( Math.round( window.innerWidth * columns[ c ] ), y );

				for ( s = 0; s < stack.length; s++ ) {
					if ( stack[ s ] === spacer || bar.contains( stack[ s ] ) ) {
						continue;
					}

					var color = solidBackground( stack[ s ] );

					if ( color ) {
						spacer.style.backgroundColor = color;
						return;
					}
				}
			}
		}

		// --protech-bar-h publishes the reserved height for anything else
		// fixed to the bottom of the viewport that needs to clear the dock.
		function reserveSpace() {
			var rect = bar.getBoundingClientRect();
			var reserved = Math.max( 0, Math.round( window.innerHeight - rect.top ) );

			// Mid-entrance the bar is translated off-screen; fall back to
			// its own height so the page doesn't jump once it settles.
			if ( bar.classList.contains( 'is-entering' ) || reserved > bar.offsetHeight * 2 ) {
				reserved = bar.offsetHeight + 14;
			}

			spacer.style.height = reserved + 'px';
			document.documentElement.style.setProperty( '--protech-bar-h', reserved + 'px' );
		}

		function layout() {
			alignToHeader();
			reserveSpace();
			paintSpacer();
		}

		layout();
		window.addEventListener( 'resize', layout );

		if ( 'function' === typeof window.ResizeObserver ) {
			var layoutObserver = new window.ResizeObserver( layout );

			layoutObserver.observe( bar );

			if ( alignTarget ) {
				layoutObserver.observe( alignTarget );
			}
		}

		// Keep re-sampling the spacer's color while the end of the page is
		// in or near view, AND for a couple of seconds after the last
		// scroll event. That tail matters: this site's footer has a
		// parallax effect that is still gliding into place after scrolling
		// stops, so a sample taken on the final scroll event saw the light
		// strip above the footer instead — caught by rendering the real
		// page in headless Chrome, not by reasoning about it.
		var nearPageEnd = 'function' !== typeof window.IntersectionObserver;
		var lastScrollAt = 0;
		var sampling = false;

		function sampleWhileSettling() {
			paintSpacer();

			if ( Date.now() - lastScrollAt < 2200 ) {
				window.setTimeout( sampleWhileSettling, 250 );
			} else {
				sampling = false;
			}
		}

		function startSampling() {
			lastScrollAt = Date.now();

			if ( nearPageEnd && ! sampling ) {
				sampling = true;
				sampleWhileSettling();
			}
		}

		if ( ! nearPageEnd ) {
			new window.IntersectionObserver(
				function ( entries ) {
					nearPageEnd = entries[ entries.length - 1 ].isIntersecting;
					startSampling();
				},
				{ rootMargin: '0px 0px 150px 0px' }
			).observe( spacer );
		}

		window.addEventListener( 'scroll', startSampling, { passive: true } );
		window.addEventListener( 'load', startSampling );

		// -- Entrance: once per browser session, not on every page. --------
		if ( ! reducedMotion && ! storageGet( ENTERED_KEY ) ) {
			bar.classList.add( 'is-entering' );
			bar.addEventListener( 'animationend', function onEntered( event ) {
				if ( event.target === bar ) {
					bar.classList.remove( 'is-entering' );
					bar.removeEventListener( 'animationend', onEntered );
					reserveSpace();
				}
			} );
		}

		storageSet( ENTERED_KEY, '1' );

		// -- A tier reached through a full page reload still gets its moment.
		var seenTier = storageGet( SEEN_TIER_KEY );

		if ( null !== seenTier && rank( current.tier ) > rank( seenTier ) ) {
			window.setTimeout( function () {
				celebrate( current.tier );
			}, 700 );
		}

		storageSet( SEEN_TIER_KEY, current.tier );

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

		// Restartable one-shot CSS animation: drop the class, force a
		// reflow, add it back, and clean up when it finishes.
		function replay( element, className ) {
			if ( ! element || reducedMotion ) {
				return;
			}

			element.classList.remove( className );
			void element.offsetWidth;
			element.classList.add( className );

			element.addEventListener( 'animationend', function done() {
				element.classList.remove( className );
				element.removeEventListener( 'animationend', done );
			} );
		}

		// Pre-formatted server-side (wc_price(), entities intact) so this
		// stays correct for the store's actual currency/decimal format
		// without duplicating that formatting logic in JS — hence innerHTML
		// for the price nodes. Returns whether anything actually changed.
		function setContent( element, value, asHtml ) {
			if ( ! element || 'string' !== typeof value ) {
				return false;
			}

			if ( asHtml ) {
				// Compare parsed-to-parsed: the server sends entities
				// (&#36;) that the DOM hands back decoded.
				var probe = document.createElement( 'span' );
				probe.innerHTML = value;

				if ( probe.innerHTML === element.innerHTML ) {
					return false;
				}

				element.innerHTML = value;
			} else {
				if ( element.textContent === value ) {
					return false;
				}

				element.textContent = value;
			}

			replay( element, 'is-ticking' );

			return true;
		}

		function applyState( state ) {
			var previousTier = current.tier || bar.getAttribute( 'data-active-tier' ) || 'standard';
			// Raw numbers, not the formatted strings: the seeded copy came
			// through an HTML attribute, which decodes wc_price()'s entities.
			var cartChanged = Number( state.displays ) !== Number( current.displays ) || Number( state.subtotal ) !== Number( current.subtotal );

			current = state;
			bar.setAttribute( 'data-active-tier', state.tier );

			if ( fill ) {
				fill.style.width = state.fill_percent + '%';
			}

			if ( 'string' === typeof state.message_html ) {
				setContent( message, state.message_html, true );
			} else {
				setContent( message, state.message, false );
			}

			setContent( stats, state.stats, false );
			setContent( tierLabel, state.tier_label, false );

			Array.prototype.forEach.call( bar.querySelectorAll( '[data-protech-subtotal]' ), function ( node ) {
				setContent( node, state.subtotal_html, true );
			} );

			if ( savings && 'string' === typeof state.savings_html ) {
				savings.hidden = '' === state.savings_html;
				setContent( savingsAmount, state.savings_html, true );
			}

			if ( cartChanged ) {
				replay( cta, 'is-bumping' );
			}

			if ( rank( state.tier ) > rank( previousTier ) ) {
				celebrate( state.tier );
			}

			storageSet( SEEN_TIER_KEY, state.tier );

			renderGhost();
			updateLadder( state.tier );
			reserveSpace();

			document.dispatchEvent( new CustomEvent( 'protech:tier-state', { detail: state } ) );
		}

		// -- Tier unlock -------------------------------------------------
		var celebrationTimer = null;

		function celebrate( tier ) {
			var marker = bar.querySelector( '.protech-tier-marker--' + tier );

			if ( celebrationTimer ) {
				clearTimeout( celebrationTimer );
			}

			bar.classList.remove( 'is-celebrating' );
			void bar.offsetWidth;
			bar.classList.add( 'is-celebrating' );

			if ( marker ) {
				replay( marker, 'is-popping' );
			}

			if ( ! reducedMotion ) {
				burstConfetti( marker );
			}

			celebrationTimer = window.setTimeout( function () {
				bar.classList.remove( 'is-celebrating' );
			}, 2600 );
		}

		function burstConfetti( marker ) {
			var barRect = bar.getBoundingClientRect();
			var origin = marker ? marker.getBoundingClientRect() : null;
			var originVisible = origin && ( origin.width > 0 || origin.height > 0 || origin.left > 0 );
			var x = originVisible ? origin.left - barRect.left : barRect.width / 2;
			var y = originVisible ? origin.top - barRect.top : barRect.height / 2;
			var layer = document.createElement( 'div' );
			var pieces = 18;
			var i;

			layer.className = 'protech-tier-bar-confetti';
			layer.setAttribute( 'aria-hidden', 'true' );
			layer.style.left = x + 'px';
			layer.style.top = y + 'px';

			for ( i = 0; i < pieces; i++ ) {
				var piece = document.createElement( 'span' );
				// Fan upwards: -160deg .. -20deg, so nothing fires down into
				// the bottom edge of the viewport.
				var angle = ( -160 + ( 140 * i ) / ( pieces - 1 ) + ( Math.random() * 14 - 7 ) ) * ( Math.PI / 180 );
				var distance = 70 + Math.random() * 90;

				piece.style.setProperty( '--protech-dx', Math.round( Math.cos( angle ) * distance ) + 'px' );
				piece.style.setProperty( '--protech-dy', Math.round( Math.sin( angle ) * distance ) + 'px' );
				piece.style.setProperty( '--protech-rot', Math.round( Math.random() * 540 - 270 ) + 'deg' );
				piece.style.animationDelay = Math.round( Math.random() * 120 ) + 'ms';
				piece.style.backgroundColor = CONFETTI_COLORS[ i % CONFETTI_COLORS.length ];

				if ( 0 === i % 3 ) {
					piece.style.borderRadius = '50%';
				}

				layer.appendChild( piece );
			}

			bar.appendChild( layer );

			window.setTimeout( function () {
				if ( layer.parentNode ) {
					layer.parentNode.removeChild( layer );
				}
			}, 1800 );
		}

		// -- Ghost preview of the quantity dialled into the unit selector -
		// Mirrors VolumePricing::scale_percent(). Visual only.
		function scalePercent( displays ) {
			var scale = current.scale || {};
			var volume = Number( scale.volume_displays ) || 0;
			var bulk = Number( scale.bulk_displays ) || 0;
			var markerPercent = Number( scale.marker_percent ) || 0;

			if ( bulk <= 0 || displays <= 0 ) {
				return 0;
			}

			if ( volume <= 0 || volume >= bulk ) {
				return Math.min( 100, ( displays / bulk ) * 100 );
			}

			if ( displays <= volume ) {
				return ( displays / volume ) * markerPercent;
			}

			return Math.min( 100, markerPercent + ( ( displays - volume ) / ( bulk - volume ) ) * ( 100 - markerPercent ) );
		}

		function renderGhost() {
			var volumeMarker = bar.querySelector( '.protech-tier-marker--volume' );
			var bulkMarker = bar.querySelector( '.protech-tier-marker--bulk' );
			var scale = current.scale || {};
			var active = !! ( pending && pending.displays > 0 && scale.bulk_displays );
			var projectedDisplays = active ? ( Number( current.displays ) || 0 ) + pending.displays : 0;
			var projectedCases = active ? ( Number( current.cases ) || 0 ) + ( pending.cases || 0 ) : 0;

			if ( ghost ) {
				ghost.style.width = active ? scalePercent( projectedDisplays ) + '%' : '0%';
			}

			if ( volumeMarker ) {
				volumeMarker.classList.toggle(
					'is-within-reach',
					active && rank( current.tier ) < 1 && projectedDisplays >= Number( scale.volume_displays )
				);
			}

			if ( bulkMarker ) {
				bulkMarker.classList.toggle(
					'is-within-reach',
					active && rank( current.tier ) < 2 && projectedCases >= Number( current.bulk_threshold_cases )
				);
			}
		}

		document.addEventListener( 'protech:qty-preview', function ( event ) {
			pending = event.detail || null;
			renderGhost();
		} );

		// unit-selector.js may have announced its starting quantity before
		// this listener existed (script order isn't ours to control) — ask
		// it to say so again.
		document.dispatchEvent( new CustomEvent( 'protech:qty-preview-request' ) );

		// -- Product page price table ------------------------------------
		function updateLadder( tier ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.protech-tier-ladder-row[data-tier]' ), function ( row ) {
				var isActive = row.getAttribute( 'data-tier' ) === tier;

				if ( isActive && ! row.classList.contains( 'is-active' ) ) {
					replay( row, 'is-arriving' );
				}

				row.classList.toggle( 'is-active', isActive );
			} );
		}

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'added_to_cart removed_from_cart wc_fragments_refreshed', scheduleRefresh );
		}

		// Fired by unit-selector.js after a successful Store API add on the
		// single product page — that path doesn't go through cart-fragments,
		// which WooCommerce no longer loads on product pages by default.
		document.addEventListener( 'protech:cart-changed', scheduleRefresh );

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
