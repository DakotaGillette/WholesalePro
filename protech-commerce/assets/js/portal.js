/**
 * /wholesale login form (templates/portal.php): the show/hide password
 * button. The button ships `hidden` and is only revealed here, so a
 * visitor without JavaScript never gets a control that does nothing.
 *
 * @package ProtechWholesale
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.protech-password-toggle' ), function ( toggle ) {
			var input = document.getElementById( toggle.getAttribute( 'aria-controls' ) || '' );

			if ( ! input ) {
				return;
			}

			toggle.hidden = false;

			toggle.addEventListener( 'click', function () {
				var reveal = 'password' === input.type;

				input.type = reveal ? 'text' : 'password';
				toggle.setAttribute( 'aria-pressed', reveal ? 'true' : 'false' );
				toggle.setAttribute(
					'aria-label',
					toggle.getAttribute( reveal ? 'data-label-hide' : 'data-label-show' ) || ''
				);
				input.focus();
			} );
		} );

		// Never post a password that is still showing as plain text into
		// the browser's form history.
		Array.prototype.forEach.call( document.querySelectorAll( '.protech-portal-login-form' ), function ( form ) {
			form.addEventListener( 'submit', function () {
				var field = form.querySelector( '#protech-password' );

				if ( field ) {
					field.type = 'password';
				}
			} );
		} );
	} );
} )();
