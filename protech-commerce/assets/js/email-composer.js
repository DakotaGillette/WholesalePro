/**
 * Email template editor (Messaging → Email templates → Edit).
 *
 * The page is an ordinary form. Every setting is a real input named by its
 * position (`blocks[2][attrs][text]`), so saving posts the whole template and
 * the server stays the one source of truth: there is no model in here to keep
 * in step. This script only:
 *
 *  - adds a block by cloning a server-printed template (`<script
 *    type="text/html">`) and moves, copies and removes blocks;
 *  - renumbers the input names after any change, rebuilding each one from its
 *    `data-name-suffix` (the part after the position) instead of rewriting the
 *    name text, which is what makes columns (blocks inside blocks) safe;
 *  - keeps a live preview: the same form posted into the preview frame, so the
 *    server's own renderer draws it and it cannot differ from what is sent;
 *  - small conveniences: the "Insert a personal detail" menu, color pickers,
 *    the Media Library picker, a one-line summary on each collapsed block.
 *
 * Plain ES5, no build step, in the style of the rest of assets/js/.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var form = document.getElementById( 'protech-composer-form' );

		if ( ! form ) {
			return;
		}

		var strings = window.protechEmailComposer || {};
		var root = document.getElementById( 'protech-blocks' );
		var status = document.getElementById( 'protech-composer-status' );
		var frame = document.getElementById( 'protech-preview' );
		var refresh = document.getElementById( 'protech-refresh-preview' );
		var insertSelect = document.getElementById( 'protech-insert-detail' );
		var emptyNote = document.querySelector( '.protech-composer-empty' );
		var editor = document.getElementById( 'protech-block-editor' );
		var backButton = document.getElementById( 'protech-back-to-blocks' );
		var lastFocused = null;
		var previewTimer = null;
		var typingTimer = null;
		var dragged = null;
		var draggingType = null;
		var dropMarked = null;

		// -- helpers ----------------------------------------------------------

		function announce( text ) {
			if ( status ) {
				status.textContent = text;
			}
		}

		function fill( text, a, b ) {
			return String( text ).replace( '%1$d', String( a ) ).replace( '%2$d', String( b ) );
		}

		/** The blocks directly inside a list, in order. */
		function cardsOf( list ) {
			return Array.prototype.filter.call( list.children, function ( node ) {
				return node.classList && node.classList.contains( 'protech-block' );
			} );
		}

		/** The card an element belongs to (its nearest card, so a column's blocks belong to their own card). */
		function cardOf( node ) {
			return node.closest ? node.closest( '.protech-block' ) : null;
		}

		function ownInputs( card ) {
			return Array.prototype.filter.call( card.querySelectorAll( '[data-name-suffix]' ), function ( input ) {
				return cardOf( input ) === card;
			} );
		}

		function ownLists( card ) {
			return Array.prototype.filter.call( card.querySelectorAll( 'ol.protech-block-list' ), function ( list ) {
				return cardOf( list.parentNode ) === card;
			} );
		}

		// -- numbering --------------------------------------------------------

		/**
		 * Gives every input in a list its final name: the list's prefix, the
		 * card's position, then the suffix the input carries. Nested lists
		 * (a Columns block's columns) get their own prefix and are numbered
		 * the same way, so moving a Columns block renames everything inside it.
		 */
		function renumber( list, prefix ) {
			cardsOf( list ).forEach( function ( card, index ) {
				var base = prefix + '[' + index + ']';

				ownInputs( card ).forEach( function ( input ) {
					input.name = base + input.getAttribute( 'data-name-suffix' );
				} );

				ownLists( card ).forEach( function ( nested ) {
					renumber( nested, base + '[children][' + nested.getAttribute( 'data-column' ) + ']' );
				} );
			} );
		}

		function afterChange() {
			renumber( root, 'blocks' );

			if ( emptyNote ) {
				emptyNote.hidden = cardsOf( root ).length > 0;
			}

			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
			}

			schedulePreview();
		}

		// -- summaries ---------------------------------------------------------

		function summarise( card ) {
			var target = card.querySelector( '.protech-block-summary' );
			var source = ownInputs( card ).filter( function ( input ) {
				return input.hasAttribute( 'data-summary' );
			} )[ 0 ];

			if ( ! target ) {
				return;
			}

			var text = source ? String( source.value ).replace( /<[^>]*>/g, '' ).replace( /\s+/g, ' ' ).trim() : '';

			target.textContent = text ? '— ' + ( text.length > 48 ? text.slice( 0, 48 ) + '…' : text ) : '';
		}

		function summariseAll() {
			Array.prototype.forEach.call( form.querySelectorAll( '.protech-block' ), summarise );
		}

		// -- adding, moving, copying, removing ---------------------------------

		function open( card ) {
			var body = card.querySelector( '.protech-block-body' );
			var toggle = card.querySelector( '.protech-block-toggle' );

			if ( body ) {
				body.hidden = false;
			}

			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'true' );
			}
		}

		function close( card ) {
			var body = card.querySelector( '.protech-block-body' );
			var toggle = card.querySelector( '.protech-block-toggle' );

			if ( body ) {
				body.hidden = true;
			}

			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		}

		/** Opens one top-level block's settings exclusively; a nested block just opens in place. */
		function selectTopLevel( card ) {
			if ( card.parentNode !== root ) {
				open( card );
				return;
			}

			cardsOf( root ).forEach( function ( sibling ) {
				sibling.classList.toggle( 'is-active', sibling === card );

				if ( sibling !== card ) {
					close( sibling );
				}
			} );

			open( card );

			if ( editor ) {
				editor.classList.add( 'is-editing-block' );
			}

			if ( backButton ) {
				backButton.hidden = false;
			}

			card.scrollIntoView( { block: 'nearest' } );
		}

		/** Back to the palette and the full block list. */
		function deselectTopLevel() {
			cardsOf( root ).forEach( function ( card ) {
				card.classList.remove( 'is-active' );
				close( card );
			} );

			if ( editor ) {
				editor.classList.remove( 'is-editing-block' );
			}

			if ( backButton ) {
				backButton.hidden = true;
			}
		}

		function addBlock( type, list, before ) {
			var template = document.getElementById( 'protech-block-tpl-' + type );

			if ( ! template || ! list ) {
				return;
			}

			var holder = document.createElement( 'ol' );

			holder.innerHTML = template.innerHTML;

			var card = holder.firstElementChild;

			if ( ! card ) {
				return;
			}

			list.insertBefore( card, before || null );

			if ( list === root ) {
				selectTopLevel( card );
			} else {
				open( card );
			}

			afterChange();
			summarise( card );

			var first = card.querySelector( 'input[type="text"], textarea' );

			if ( first ) {
				first.focus();
			}

			announce( fill( strings.added || 'Block added at position %1$d of %2$d.', cardsOf( list ).indexOf( card ) + 1, cardsOf( list ).length ) );
		}

		function move( card, delta ) {
			var list = card.parentNode;
			var siblings = cardsOf( list );
			var index = siblings.indexOf( card );
			var target = siblings[ index + delta ];

			if ( ! target ) {
				return;
			}

			list.insertBefore( card, delta < 0 ? target : target.nextSibling );
			afterChange();
			card.querySelector( '[data-act="' + ( delta < 0 ? 'up' : 'down' ) + '"]' ).focus();
			announce( fill( strings.moved || 'Block moved to position %1$d of %2$d.', cardsOf( list ).indexOf( card ) + 1, cardsOf( list ).length ) );
		}

		/** Typed values live in properties, not attributes; copy them across so a cloned card keeps what was typed. */
		function syncAttributes( card ) {
			Array.prototype.forEach.call( card.querySelectorAll( 'input, textarea, select' ), function ( field ) {
				if ( 'checkbox' === field.type || 'radio' === field.type ) {
					if ( field.checked ) {
						field.setAttribute( 'checked', 'checked' );
					} else {
						field.removeAttribute( 'checked' );
					}
				} else if ( 'TEXTAREA' === field.tagName ) {
					field.textContent = field.value;
				} else if ( 'SELECT' === field.tagName ) {
					Array.prototype.forEach.call( field.options, function ( option ) {
						if ( option.selected ) {
							option.setAttribute( 'selected', 'selected' );
						} else {
							option.removeAttribute( 'selected' );
						}
					} );
				} else {
					field.setAttribute( 'value', field.value );
				}
			} );
		}

		function copy( card ) {
			syncAttributes( card );

			var clone = card.cloneNode( true );

			clone.classList.remove( 'is-active' );

			// A copy is a new block: it gets its own id from the server.
			Array.prototype.forEach.call( clone.querySelectorAll( '[data-name-suffix="[id]"]' ), function ( input ) {
				input.value = '';
				input.setAttribute( 'value', '' );
			} );

			// Product pickers were turned into select2 widgets; hand the clone back its plain select to enhance again.
			Array.prototype.forEach.call( clone.querySelectorAll( '.select2-container' ), function ( node ) {
				node.parentNode.removeChild( node );
			} );

			Array.prototype.forEach.call( clone.querySelectorAll( 'select.wc-product-search' ), function ( select ) {
				select.classList.remove( 'enhanced', 'select2-hidden-accessible' );
				select.removeAttribute( 'data-select2-id' );
				select.removeAttribute( 'aria-hidden' );
				select.removeAttribute( 'tabindex' );
			} );

			var wasTopLevel = card.parentNode === root;

			card.parentNode.insertBefore( clone, card.nextSibling );
			afterChange();
			summariseAll();

			if ( wasTopLevel ) {
				selectTopLevel( clone );
			}

			announce( strings.copied || 'Block copied.' );
		}

		function remove( card ) {
			var list = card.parentNode;
			var wasActive = list === root && card.classList.contains( 'is-active' );

			list.removeChild( card );
			afterChange();

			if ( wasActive ) {
				deselectTopLevel();
			}

			announce( strings.removed || 'Block removed.' );
		}

		// -- events (all delegated, so blocks added later just work) -------------

		form.addEventListener( 'click', function ( event ) {
			var target = event.target;
			var add = target.closest ? target.closest( '.protech-add-block' ) : null;

			if ( add ) {
				event.preventDefault();
				addBlock( add.getAttribute( 'data-type' ), root );
				return;
			}

			var back = target.closest ? target.closest( '#protech-back-to-blocks' ) : null;

			if ( back ) {
				event.preventDefault();
				deselectTopLevel();
				return;
			}

			var toggle = target.closest ? target.closest( '.protech-block-toggle' ) : null;

			if ( toggle ) {
				event.preventDefault();

				var card = cardOf( toggle );

				if ( card.parentNode === root ) {
					if ( card.querySelector( '.protech-block-body' ).hidden ) {
						selectTopLevel( card );
					} else {
						deselectTopLevel();
					}
				} else {
					var body = card.querySelector( '.protech-block-body' );
					var showing = body.hidden;

					body.hidden = ! showing;
					toggle.setAttribute( 'aria-expanded', showing ? 'true' : 'false' );
				}

				return;
			}

			var act = target.closest ? target.closest( '[data-act]' ) : null;

			if ( act ) {
				event.preventDefault();

				var owner = cardOf( act );
				var action = act.getAttribute( 'data-act' );

				if ( 'up' === action ) {
					move( owner, -1 );
				} else if ( 'down' === action ) {
					move( owner, 1 );
				} else if ( 'copy' === action ) {
					copy( owner );
				} else if ( 'remove' === action ) {
					remove( owner );
				}

				return;
			}

			var device = target.closest ? target.closest( '.protech-device' ) : null;

			if ( device ) {
				event.preventDefault();

				Array.prototype.forEach.call( form.querySelectorAll( '.protech-device' ), function ( button ) {
					button.classList.toggle( 'is-active', button === device );
				} );

				frame.style.width = device.getAttribute( 'data-width' );
				return;
			}

			var choose = target.closest ? target.closest( '.protech-media-choose' ) : null;

			if ( choose ) {
				event.preventDefault();
				chooseMedia( choose.closest( '.protech-media-picker' ) );
				return;
			}

			var clear = target.closest ? target.closest( '.protech-media-clear' ) : null;

			if ( clear ) {
				event.preventDefault();
				setMedia( clear.closest( '.protech-media-picker' ), 0, '' );
			}
		} );

		form.addEventListener( 'change', function ( event ) {
			var target = event.target;

			// Choosing a block from a column's "+ Add a block" menu.
			if ( target.classList && target.classList.contains( 'protech-add-in-list' ) ) {
				var value = target.value;
				var list = target.parentNode.querySelector( 'ol.protech-block-list' );

				target.value = '';

				if ( value ) {
					addBlock( value, list );
				}

				return;
			}

			// Two columns or three: show or hide the third column's list.
			if ( target.getAttribute && '[attrs][count]' === target.getAttribute( 'data-name-suffix' ) ) {
				var columns = cardOf( target ).querySelectorAll( '.protech-column' );

				if ( columns[ 2 ] ) {
					columns[ 2 ].hidden = parseInt( target.value, 10 ) < 3;
				}
			}
		} );

		form.addEventListener( 'input', function ( event ) {
			var target = event.target;

			if ( target.name === 'preview_email' ) {
				return;
			}

			var card = cardOf( target );

			if ( card && target.hasAttribute && target.hasAttribute( 'data-summary' ) ) {
				summarise( card );
			}

			// A color picker and its text box stay in step.
			if ( target.classList && target.classList.contains( 'protech-color-pick' ) ) {
				var text = target.parentNode.querySelector( '.protech-color-text' );

				text.value = target.value;
			} else if ( target.classList && target.classList.contains( 'protech-color-text' ) ) {
				var pick = target.parentNode.querySelector( '.protech-color-pick' );

				if ( /^#[0-9a-fA-F]{6}$/.test( target.value ) ) {
					pick.value = target.value;
				}
			}

			scheduleTypingPreview();
		} );

		form.addEventListener( 'change', function ( event ) {
			if ( event.target.name !== 'preview_email' ) {
				schedulePreview();
			}
		} );

		// The Insert menu goes into whichever text field was last used.
		document.addEventListener( 'focusin', function ( event ) {
			if ( event.target.classList && event.target.classList.contains( 'protech-tag-target' ) ) {
				lastFocused = event.target;
			}
		} );

		if ( insertSelect ) {
			insertSelect.addEventListener( 'change', function () {
				var tag = insertSelect.value;

				insertSelect.value = '';

				if ( ! tag ) {
					return;
				}

				if ( ! lastFocused ) {
					announce( strings.pickField || 'Click into a text box first, then choose what to insert.' );
					window.alert( strings.pickField || 'Click into a text box first, then choose what to insert.' );
					return;
				}

				var start = null === lastFocused.selectionStart ? lastFocused.value.length : lastFocused.selectionStart;
				var end = null === lastFocused.selectionEnd ? lastFocused.value.length : lastFocused.selectionEnd;

				lastFocused.value = lastFocused.value.slice( 0, start ) + tag + lastFocused.value.slice( end );
				lastFocused.focus();
				lastFocused.selectionStart = lastFocused.selectionEnd = start + tag.length;
				lastFocused.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		}

		// -- drag and drop -----------------------------------------------------------

		form.addEventListener( 'dragstart', function ( event ) {
			var paletteButton = event.target.closest ? event.target.closest( '.protech-add-block' ) : null;

			if ( paletteButton ) {
				draggingType = paletteButton.getAttribute( 'data-type' );

				if ( event.dataTransfer ) {
					event.dataTransfer.effectAllowed = 'copy';
					event.dataTransfer.setData( 'text/plain', 'protech-new-block' );
				}

				return;
			}

			var head = event.target.closest ? event.target.closest( '.protech-block-head' ) : null;

			if ( ! head ) {
				return;
			}

			dragged = cardOf( head );
			dragged.classList.add( 'is-dragging' );

			if ( event.dataTransfer ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', 'protech-block' );
			}
		} );

		form.addEventListener( 'dragover', function ( event ) {
			if ( ! dragged ) {
				return;
			}

			var over = event.target.closest ? event.target.closest( '.protech-block' ) : null;

			// Only within the same list: a block cannot be dragged out of its column.
			if ( ! over || over === dragged || over.parentNode !== dragged.parentNode ) {
				return;
			}

			event.preventDefault();

			var box = over.getBoundingClientRect();
			var before = event.clientY < box.top + box.height / 2;

			over.parentNode.insertBefore( dragged, before ? over : over.nextSibling );
		} );

		form.addEventListener( 'drop', function ( event ) {
			if ( dragged ) {
				event.preventDefault();
			}
		} );

		form.addEventListener( 'dragend', function () {
			if ( draggingType ) {
				draggingType = null;
				clearFrameDropTarget();
			}

			if ( ! dragged ) {
				return;
			}

			dragged.classList.remove( 'is-dragging' );

			var list = dragged.parentNode;

			announce( fill( strings.moved || 'Block moved to position %1$d of %2$d.', cardsOf( list ).indexOf( dragged ) + 1, cardsOf( list ).length ) );
			dragged = null;
			afterChange();
		} );

		// -- the canvas: click a rendered block to select it, drop a new one at a position ---

		/** The rendered block under a point in the iframe, and whether the point is past its middle. */
		function pointBlock( doc, x, y ) {
			var hit = doc.elementFromPoint ? doc.elementFromPoint( x, y ) : null;
			var el = hit && hit.closest ? hit.closest( '[data-pw-block]' ) : null;

			if ( ! el ) {
				return null;
			}

			var box = el.getBoundingClientRect();

			return { el: el, after: y > box.top + box.height / 2 };
		}

		function markFrameDropTarget( hit ) {
			if ( dropMarked && ( ! hit || dropMarked !== hit.el ) ) {
				dropMarked.style.outline = '';
				dropMarked = null;
			}

			if ( hit ) {
				hit.el.style.outline = '2px dashed #2271b1';
				dropMarked = hit.el;
			}
		}

		function clearFrameDropTarget() {
			markFrameDropTarget( null );
		}

		/** A brief highlight on the block that was just clicked, so the click has visible effect. */
		function flash( el ) {
			el.style.outline = '2px solid #2271b1';
			el.style.outlineOffset = '-2px';

			window.setTimeout( function () {
				el.style.outline = '';
				el.style.outlineOffset = '';
			}, 900 );
		}

		function wireFrameDocument() {
			var doc;

			try {
				doc = frame.contentDocument;
			} catch ( e ) {
				return; // Not same-origin (should not happen here); nothing we can safely do.
			}

			if ( ! doc || ! doc.body ) {
				return;
			}

			doc.addEventListener( 'click', function ( event ) {
				var el = event.target.closest ? event.target.closest( '[data-pw-block]' ) : null;

				if ( ! el ) {
					return;
				}

				var card = cardsOf( root )[ parseInt( el.getAttribute( 'data-pw-block' ), 10 ) ];

				if ( ! card ) {
					return;
				}

				selectTopLevel( card );
				flash( el );
			} );

			doc.addEventListener( 'dragover', function ( event ) {
				if ( ! draggingType ) {
					return;
				}

				event.preventDefault();
				markFrameDropTarget( pointBlock( doc, event.clientX, event.clientY ) );
			} );

			doc.addEventListener( 'drop', function ( event ) {
				if ( ! draggingType ) {
					return;
				}

				event.preventDefault();

				var type = draggingType;
				var hit = pointBlock( doc, event.clientX, event.clientY );

				clearFrameDropTarget();

				var before = null;

				if ( hit ) {
					var target = cardsOf( root )[ parseInt( hit.el.getAttribute( 'data-pw-block' ), 10 ) ];

					if ( target ) {
						before = hit.after ? target.nextSibling : target;
					}
				}

				addBlock( type, root, before );
			} );
		}

		frame.addEventListener( 'load', wireFrameDocument );

		// -- Media Library ---------------------------------------------------------------

		function setMedia( picker, id, url ) {
			var input = picker.querySelector( '[data-media-id]' );
			var thumb = picker.querySelector( '.protech-media-thumb' );
			var clear = picker.querySelector( '.protech-media-clear' );

			input.value = String( id || 0 );
			thumb.innerHTML = '';

			if ( url ) {
				var img = document.createElement( 'img' );

				img.src = url;
				img.alt = '';
				thumb.appendChild( img );
			}

			clear.hidden = ! id;
			schedulePreview();
		}

		function chooseMedia( picker ) {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			var chooser = window.wp.media( {
				title: strings.chooseTitle || 'Choose a picture',
				button: { text: strings.chooseButton || 'Use this picture' },
				library: { type: 'image' },
				multiple: false,
			} );

			chooser.on( 'select', function () {
				var attachment = chooser.state().get( 'selection' ).first().toJSON();
				var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

				setMedia( picker, attachment.id, thumb );
			} );

			chooser.open();
		}

		// -- live preview ----------------------------------------------------------------

		/** The same form, posted into the preview frame — exactly what the Refresh button does. */
		function previewNow() {
			if ( ! refresh ) {
				return;
			}

			if ( form.requestSubmit ) {
				form.requestSubmit( refresh );
			} else {
				refresh.click();
			}
		}

		/**
		 * Redraws the canvas right away: adding, moving, copying or removing a block (so a
		 * drag from the palette shows up live, with nothing to click), a dropdown or checkbox
		 * changing, a picture chosen. The short wait only coalesces anything firing more than
		 * once in the same moment; a person never notices it as a wait. Without this script,
		 * or if a request is still in flight when the next one is due, it is the Refresh button.
		 */
		function schedulePreview() {
			if ( ! refresh ) {
				return;
			}

			if ( typingTimer ) {
				clearTimeout( typingTimer );
				typingTimer = null;
			}

			if ( previewTimer ) {
				clearTimeout( previewTimer );
			}

			previewTimer = window.setTimeout( previewNow, 100 );
		}

		/** Typing in a text field: waits for a pause, so it is not one request per keystroke. */
		function scheduleTypingPreview() {
			if ( ! refresh ) {
				return;
			}

			if ( previewTimer ) {
				clearTimeout( previewTimer );
				previewTimer = null;
			}

			if ( typingTimer ) {
				clearTimeout( typingTimer );
			}

			typingTimer = window.setTimeout( previewNow, 700 );
		}

		// -- start ---------------------------------------------------------------------------

		summariseAll();
		afterChange();
	}
} )();
