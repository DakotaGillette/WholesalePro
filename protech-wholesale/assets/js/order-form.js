/**
 * Quick Order grid behaviour: live line-total/summary math and the
 * "Add all to cart" AJAX action. Vanilla JS, no build step.
 *
 * DOM/data contract comes from templates/order-form.php and the
 * `ProtechWholesale` global localized in includes/class-plugin.php.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var form = document.getElementById( 'protech-order-form' );

		if ( ! form ) {
			return;
		}

		var rows = Array.prototype.slice.call( form.querySelectorAll( 'tr.protech-order-row' ) );

		if ( ! rows.length ) {
			return;
		}

		// Derive the store's money format (symbol, decimals, separators)
		// from a price cell WooCommerce already rendered on this page,
		// rather than hardcoding a currency.
		var moneySample = getMoneySample( rows );

		rows.forEach( function ( row ) {
			var qtyInput = row.querySelector( '.protech-case-qty' );

			if ( ! qtyInput ) {
				return;
			}

			qtyInput.addEventListener( 'input', function () {
				updateRowTotal( row, moneySample );
				updateSummary( rows, moneySample );
			} );
		} );

		// Compute once on load too — quantities may already be pre-filled
		// (reorder prefill).
		rows.forEach( function ( row ) {
			updateRowTotal( row, moneySample );
		} );
		updateSummary( rows, moneySample );

		var addAllButton = document.getElementById( 'protech-add-all-to-cart' );

		if ( addAllButton ) {
			addAllButton.addEventListener( 'click', function () {
				handleAddAllToCart( addAllButton, rows, moneySample );
			} );
		}
	}

	/**
	 * Grabs the "Price / case" cell text from the first row that has one,
	 * to use as a formatting template for every amount we render.
	 */
	function getMoneySample( rows ) {
		for ( var i = 0; i < rows.length; i++ ) {
			var qtyInput = rows[ i ].querySelector( '.protech-case-qty' );
			var qtyCell = qtyInput ? closestCell( qtyInput ) : null;
			var priceCell = qtyCell ? qtyCell.previousElementSibling : null;

			if ( priceCell && priceCell.textContent.trim() ) {
				return priceCell.textContent;
			}
		}

		return '$0.00';
	}

	function closestCell( el ) {
		while ( el && 'TD' !== el.tagName ) {
			el = el.parentElement;
		}

		return el;
	}

	/**
	 * Reads prefix/suffix/decimal-separator/thousands-separator out of an
	 * already-formatted wc_price() string, e.g. "$5.00" or "5,00 €".
	 */
	function parseMoneyFormat( sample ) {
		var text = String( sample || '' );
		var match = text.match( /([0-9][0-9.,\s ]*[0-9]|[0-9])/ );

		if ( ! match ) {
			return null;
		}

		var numberStr = match[ 0 ];
		var lastComma = numberStr.lastIndexOf( ',' );
		var lastDot = numberStr.lastIndexOf( '.' );
		var decimalSep = '.';
		var thousandSep = ',';

		if ( lastComma > lastDot ) {
			decimalSep = ',';
			thousandSep = '.';
		} else if ( lastDot > lastComma ) {
			decimalSep = '.';
			thousandSep = ',';
		}

		var lastSepIndex = Math.max( lastComma, lastDot );
		var decimals = lastSepIndex >= 0 ? ( numberStr.length - lastSepIndex - 1 ) : 0;

		if ( decimals < 0 || decimals > 4 ) {
			decimals = 2;
		}

		return {
			prefix: text.slice( 0, match.index ),
			suffix: text.slice( match.index + numberStr.length ),
			decimalSep: decimalSep,
			thousandSep: thousandSep,
			decimals: decimals,
		};
	}

	/**
	 * Formats `amount` using the same prefix/suffix/separators as
	 * `sample` (an already-formatted wc_price() string). Falls back to a
	 * plain "$X.XX" if the sample can't be parsed.
	 */
	function formatMoney( amount, sample ) {
		var num = Number( amount );

		if ( ! isFinite( num ) ) {
			num = 0;
		}

		var fmt = parseMoneyFormat( sample );

		if ( ! fmt ) {
			return '$' + num.toFixed( 2 );
		}

		var fixed = Math.abs( num ).toFixed( fmt.decimals );
		var pieces = fixed.split( '.' );
		var intPart = pieces[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, fmt.thousandSep );
		var numberStr = ( pieces.length > 1 && fmt.decimals > 0 ) ? intPart + fmt.decimalSep + pieces[ 1 ] : intPart;

		if ( num < 0 ) {
			numberStr = '-' + numberStr;
		}

		return fmt.prefix + numberStr + fmt.suffix;
	}

	function getRowQty( row ) {
		var qtyInput = row.querySelector( '.protech-case-qty' );
		var qty = qtyInput ? parseInt( qtyInput.value, 10 ) : 0;

		return ( qty > 0 ) ? qty : 0;
	}

	function getRowPricePerCase( row ) {
		return parseFloat( row.getAttribute( 'data-price-per-case' ) ) || 0;
	}

	function updateRowTotal( row, moneySample ) {
		var lineTotal = getRowQty( row ) * getRowPricePerCase( row );
		var cell = row.querySelector( '.protech-line-total' );

		if ( cell ) {
			cell.textContent = formatMoney( lineTotal, moneySample );
		}

		return lineTotal;
	}

	function updateSummary( rows, moneySample ) {
		var summary = document.getElementById( 'protech-order-summary' );

		if ( ! summary ) {
			return;
		}

		var totalCases = 0;
		var subtotal = 0;

		rows.forEach( function ( row ) {
			var qty = getRowQty( row );

			totalCases += qty;
			subtotal += qty * getRowPricePerCase( row );
		} );

		var minimum = parseFloat( summary.getAttribute( 'data-minimum' ) ) || 0;

		setText( 'protech-summary-cases', String( totalCases ) );
		setText( 'protech-summary-subtotal', formatMoney( subtotal, moneySample ) );

		var bar = document.getElementById( 'protech-summary-progress-bar' );

		if ( bar ) {
			var pct = minimum > 0 ? Math.min( 100, ( subtotal / minimum ) * 100 ) : 100;

			bar.style.width = pct + '%';
		}

		var remainingEl = document.getElementById( 'protech-summary-remaining' );

		if ( remainingEl ) {
			if ( minimum <= 0 || subtotal >= minimum ) {
				remainingEl.textContent = '';
			} else {
				remainingEl.textContent = 'Add ' + formatMoney( minimum - subtotal, moneySample ) +
					' more to reach your ' + formatMoney( minimum, moneySample ) + ' minimum.';
			}
		}
	}

	function setText( id, text ) {
		var el = document.getElementById( id );

		if ( el ) {
			el.textContent = text;
		}
	}

	function handleAddAllToCart( button, rows ) {
		var settings = window.ProtechWholesale || {};
		var i18n = settings.i18n || {};
		var notice = document.getElementById( 'protech-order-form-notice' );

		var params = new URLSearchParams();
		params.set( 'action', 'protech_add_all_to_cart' );
		params.set( 'nonce', settings.nonce || '' );

		var hasItems = false;

		rows.forEach( function ( row ) {
			var qty = getRowQty( row );

			if ( qty > 0 ) {
				params.set( 'cases[' + row.getAttribute( 'data-item-id' ) + ']', String( qty ) );
				hasItems = true;
			}
		} );

		if ( ! hasItems ) {
			return;
		}

		setButtonLoading( button, true );
		clearNotice( notice );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: params.toString(),
		} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, json: json };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.json || ! result.json.success ) {
					var message = ( result.json && result.json.data && result.json.data.message ) || i18n.error;

					showNotice( notice, escapeHtml( message ), true );

					return;
				}

				var data = result.json.data || {};

				if ( data.errors && data.errors.length ) {
					var items = data.errors.map( function ( error ) {
						return '<li>' + escapeHtml( error ) + '</li>';
					} ).join( '' );

					showNotice( notice, '<ul class="protech-order-errors">' + items + '</ul>', true );

					return;
				}

				var addedMessage = i18n.added || 'Added to cart';

				if ( ! data.minimum_met && data.remaining ) {
					addedMessage += '. <strong>' + escapeHtml( data.remaining ) +
						'</strong> more to reach your wholesale minimum.';
				}

				showNotice( notice, addedMessage, false );
			} )
			.catch( function () {
				showNotice( notice, escapeHtml( i18n.error || 'Something went wrong. Please try again.' ), true );
			} )
			.then( function () {
				setButtonLoading( button, false );
			} );
	}

	function clearNotice( el ) {
		if ( ! el ) {
			return;
		}

		el.innerHTML = '';
		el.classList.remove( 'protech-notice-error', 'protech-notice-success' );
	}

	function showNotice( el, html, isError ) {
		if ( ! el ) {
			return;
		}

		el.innerHTML = html;
		el.classList.toggle( 'protech-notice-error', !! isError );
		el.classList.toggle( 'protech-notice-success', ! isError );
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );

		div.textContent = String( value == null ? '' : value );

		return div.innerHTML;
	}

	function setButtonLoading( button, isLoading ) {
		button.disabled = isLoading;
		button.classList.toggle( 'is-loading', isLoading );
	}
} )();
