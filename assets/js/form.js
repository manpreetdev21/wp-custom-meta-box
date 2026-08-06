/**
 * Front-end form submission.
 *
 * Progressive: the form is a real form with a real action, so it works with
 * this script blocked. All this adds is submitting without a page reload and
 * showing per-field errors in place.
 *
 * @package WPCMB
 */

( function () {
	'use strict';

	var config = window.wpcmbForm || {};
	var i18n = config.i18n || {};

	/**
	 * Show a message above the form.
	 *
	 * @param {Element} form    The form.
	 * @param {string}  message Message text.
	 * @param {boolean} success Whether it is a success message.
	 */
	function notify( form, message, success ) {
		var wrap = form.parentElement;
		var notice = wrap.querySelector( '.wpcmb-form__notice' );

		if ( ! notice ) {
			notice = document.createElement( 'p' );
			notice.setAttribute( 'role', 'status' );
			wrap.insertBefore( notice, form );
		}

		notice.className = 'wpcmb-form__notice wpcmb-form__notice--' + ( success ? 'success' : 'error' );
		notice.textContent = message;
	}

	/**
	 * Show validation errors beside the fields they belong to.
	 *
	 * @param {Element} form   The form.
	 * @param {Object}  errors Messages keyed by field name.
	 */
	function showErrors( form, errors ) {
		form.querySelectorAll( '.wpcmb-field__error' ).forEach( function ( slot ) {
			slot.textContent = '';
			slot.hidden = true;
		} );

		var first = null;

		Object.keys( errors || {} ).forEach( function ( name ) {
			var field = form.querySelector( '.wpcmb-field[data-wpcmb-name="' + name + '"]' )
				|| form.querySelector( '[data-wpcmb-error-for="' + name + '"]' );

			if ( ! field ) {
				return;
			}

			var slot = field.classList.contains( 'wpcmb-field__error' )
				? field
				: field.querySelector( '.wpcmb-field__error' );

			if ( slot ) {
				slot.textContent = errors[ name ];
				slot.hidden = false;
			}

			first = first || field;
		} );

		if ( ! first ) {
			return;
		}

		// Bringing the field into view is a courtesy, not part of reporting
		// the error. Anything that goes wrong here must not stop the message
		// being shown, so it is guarded rather than allowed to throw.
		if ( 'function' === typeof first.scrollIntoView ) {
			first.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		var input = first.querySelector( 'input, select, textarea' );

		if ( input ) {
			input.focus( { preventScroll: true } );
		}
	}

	/**
	 * Submit a form without reloading the page.
	 *
	 * @param {Event} event Submit event.
	 */
	function submit( event ) {
		var form = event.target;

		if ( '1' !== form.dataset.wpcmbAjax ) {
			return;
		}

		event.preventDefault();

		var button = form.querySelector( '.wpcmb-form__submit' );
		var status = form.querySelector( '.wpcmb-form__status' );

		button.disabled = true;
		status.textContent = i18n.submitting;

		// FormData carries the file inputs too, so an upload-capable form
		// needs nothing extra here.
		window.fetch( config.ajaxUrl, {
			method: 'POST',
			body: new FormData( form ),
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.catch( function () {
				// Only a transport failure reaches here. Handling it as its
				// own step means a later DOM error cannot be misreported as a
				// network problem, hiding the real validation message.
				return { success: false, data: { message: i18n.failed } };
			} )
			.then( function ( result ) {
				var data = result && result.data ? result.data : {};

				if ( result && result.success ) {
					notify( form, data.message, true );
					showErrors( form, {} );

					if ( data.redirect ) {
						window.location.assign( data.redirect );
						return;
					}

					// A form that created something starts blank again; one
					// that edited something keeps what was just saved.
					if ( 'new' === form.dataset.wpcmbObject ) {
						form.reset();
					}

					return;
				}

				notify( form, data.message || i18n.failed, false );
				showErrors( form, data.errors );
			} )
			.finally( function () {
				button.disabled = false;
				status.textContent = '';
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-wpcmb-form]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', submit );
		} );
	} );
}() );
