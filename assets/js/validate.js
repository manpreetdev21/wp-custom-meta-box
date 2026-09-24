/**
 * The gate that stops a screen being saved while a field is invalid.
 *
 * Both editors let a post be published without a required field ever being
 * filled in: the classic form posts whatever it has, and the block editor
 * saves the post through the REST API and submits the meta boxes afterwards,
 * so by the time this plugin sees the values the post is already published.
 * That is what this closes.
 *
 * Nothing here decides what is valid. It asks the server — the real Validator,
 * over the real resolved fields — and acts on the answer, so conditional
 * logic, per-type formats, lengths, ranges, patterns, the `wpcmb/validate`
 * filters and required-ness are all decided in one place. A rule added in PHP
 * is enforced here the moment it exists, with nothing to keep in step.
 *
 * If this script never runs, the server still refuses to publish a post whose
 * required fields are empty; see MetaBoxes::guard_publish().
 *
 * @package WPCMB
 */

( function () {
	'use strict';

	var config = window.wpcmbValidate || {};
	var i18n = config.i18n || {};
	var LOCK = 'wpcmb-required';

	/**
	 * Whether the block editor is running this screen.
	 *
	 * @return {boolean} True in the block editor.
	 */
	function isBlockEditor() {
		return !! ( window.wp && window.wp.data && window.wp.data.select( 'core/editor' ) );
	}

	/**
	 * The form holding the fields, classic or block editor alike.
	 *
	 * @return {HTMLFormElement|null} The form.
	 */
	function form() {
		var field = document.querySelector( '.wpcmb-group-fields' );

		return field ? field.closest( 'form' ) : null;
	}

	/**
	 * The field values currently on screen, as the save path would read them.
	 *
	 * Serialised from the form itself rather than walked control by control:
	 * the form already knows how every control type reports itself, including
	 * the ones whose value lives in a hidden input, and a control disabled by
	 * conditional logic is left out by the same rule the save path applies.
	 *
	 * @return {FormData|null} The values, or null when there is no form.
	 */
	function values() {
		var element = form();

		if ( ! element ) {
			return null;
		}

		var data = new FormData();
		var seen = new FormData( element );

		seen.forEach( function ( value, name ) {
			if ( 0 === name.indexOf( 'wpcmb_values' ) ) {
				data.append( name, value );
			}
		} );

		data.append( 'action', 'wpcmb_validate_values' );
		data.append( 'nonce', config.nonce || '' );
		data.append( 'object', config.object || '' );

		return data;
	}

	/**
	 * Ask the server whether what is on screen may be saved.
	 *
	 * A request that fails outright resolves as valid: a network blip must not
	 * lock somebody out of saving their work. The server checks again on the
	 * way in, so nothing invalid gets stored by being optimistic here.
	 *
	 * @return {Promise<Object>} Errors keyed by field name.
	 */
	function ask() {
		var data = values();

		if ( ! data ) {
			return Promise.resolve( {} );
		}

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'wpcmb: validation request failed with ' + response.status );
				}

				return response.json();
			} )
			.then( function ( body ) {
				return body && body.success && body.data ? body.data.errors || {} : {};
			} )
			.catch( function ( error ) {
				// Said out loud rather than swallowed: failing open is the
				// right default, but a gate that has quietly stopped working
				// looks exactly like a screen with nothing to report.
				if ( window.console && window.console.warn ) {
					window.console.warn( error );
				}

				return {};
			} );
	}

	/**
	 * Show or clear the message under each field.
	 *
	 * Writes into the error paragraph the renderer already puts beside every
	 * control, so a validation message appears in the same place whether it
	 * came from here or from a save that was refused.
	 *
	 * @param {Object} errors Errors keyed by field name.
	 * @return {HTMLElement|null} The first field with a message.
	 */
	function show( errors ) {
		var first = null;

		document.querySelectorAll( '.wpcmb-field[data-wpcmb-name]' ).forEach( function ( field ) {
			var slot = field.querySelector( ':scope > .wpcmb-field__control > .wpcmb-field__error' );

			if ( ! slot ) {
				return;
			}

			var message = errors[ field.dataset.wpcmbName ];

			field.classList.toggle( 'is-invalid', !! message );
			slot.textContent = message || '';
			slot.hidden = ! message;

			if ( message && ! first ) {
				first = field;
			}
		} );

		return first;
	}

	/**
	 * Submit a form for real, past anything named "submit" inside it.
	 *
	 * A form exposes its own controls as properties, so on any form holding a
	 * control named `submit` — the profile, term and comment forms all do —
	 * `form.submit` is that button, not the method, and calling it throws.
	 * Taking the method off the prototype is the only reliable way in.
	 *
	 * @param {HTMLFormElement} element The form.
	 */
	function replay( element ) {
		window.HTMLFormElement.prototype.submit.call( element );
	}

	/**
	 * Bring a field into view and put the cursor in it.
	 *
	 * @param {HTMLElement} field Field wrapper.
	 */
	function reveal( field ) {
		if ( ! field ) {
			return;
		}

		// A collapsed postbox or a closed meta box drawer hides the field, and
		// scrolling to something invisible looks like nothing happened.
		var box = field.closest( '.postbox' );

		if ( box && box.classList.contains( 'closed' ) ) {
			box.classList.remove( 'closed' );
		}

		// Feature-checked: if this throws, the caller never re-enables the
		// submit button and the screen is left spinning over nothing.
		if ( field.scrollIntoView ) {
			field.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}

		var control = field.querySelector( 'input, select, textarea, button' );

		if ( control && ! control.disabled ) {
			control.focus( { preventScroll: true } );
		}
	}

	/**
	 * The block editor: refuse the save that would make the post public.
	 *
	 * Saving is held with the editor's own lock, which is what greys out the
	 * Publish and Update buttons, so no save has to be intercepted half way
	 * through. The lock goes on only when the post is heading for a public
	 * status: a draft with an empty required field still saves, because
	 * refusing to keep somebody's half-written work is a worse outcome than
	 * the one being prevented.
	 *
	 * Clicking Publish sets the status and then saves, both in one handler.
	 * `wp.data` calls subscribers synchronously as each change is dispatched,
	 * so the lock lands between the two and the save finds it already there.
	 */
	function watchBlockEditor() {
		var editor = window.wp.data.dispatch( 'core/editor' );
		var reader = window.wp.data.select( 'core/editor' );
		var notices = window.wp.data.dispatch( 'core/notices' );
		var PUBLIC = [ 'publish', 'future', 'private' ];
		var errors = {};
		var locked = false;

		/**
		 * Whether this post is public, or about to be.
		 *
		 * @return {boolean} True when the status would put it on the site.
		 */
		function goingPublic() {
			return -1 !== PUBLIC.indexOf( reader.getEditedPostAttribute( 'status' ) );
		}

		/**
		 * Put the lock on or take it off, to match what is known right now.
		 *
		 * Runs on every store change, so it stays a comparison and a dispatch
		 * and nothing more.
		 */
		function sync() {
			var blocked = Object.keys( errors ).length > 0 && goingPublic();

			if ( blocked === locked ) {
				return;
			}

			locked = blocked;

			if ( ! blocked ) {
				editor.unlockPostSaving( LOCK );
				notices.removeNotice( LOCK );

				return;
			}

			editor.lockPostSaving( LOCK );

			var names = Object.keys( errors );
			var field = show( errors );

			notices.createNotice(
				'error',
				( i18n.blocked || '' ) + ' ' + names.map( function ( name ) {
					return errors[ name ];
				} ).join( ' ' ),
				{
					id: LOCK,
					isDismissible: false,
					actions: field
						? [ {
							label: i18n.showField || '',
							onClick: function () {
								reveal( field );
							},
						} ]
						: [],
				}
			);
		}

		var pending = false;

		/**
		 * Re-ask the server, then show what it said.
		 */
		function refresh() {
			if ( pending ) {
				return;
			}

			pending = true;

			ask().then( function ( answer ) {
				pending = false;
				errors = answer;
				show( errors );
				sync();
			} );
		}

		var timer = null;

		/**
		 * Coalesce a burst of edits into one question.
		 */
		function queue() {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 400 );
		}

		var root = form();

		if ( root ) {
			root.addEventListener( 'change', queue );
			root.addEventListener( 'input', queue );
		}

		// Rows and layouts arrive after this runs, and each brings fields of
		// its own that may be required.
		document.addEventListener( 'wpcmb:enhance', queue );

		window.wp.data.subscribe( sync );

		refresh();
	}

	/**
	 * The classic editor: stop the submit, ask, then let it through.
	 *
	 * The answer arrives after the event has to be decided, so the submit is
	 * always cancelled and replayed once the server has answered.
	 */
	function watchClassicEditor() {
		var element = form();

		if ( ! element ) {
			return;
		}

		// The post form is the only one with a publish button to put back.
		var isPostForm = 'post' === element.id;

		var cleared = false;

		element.addEventListener( 'submit', function ( event ) {
			if ( cleared ) {
				return;
			}

			// Saving a draft or previewing is not publishing, and neither is
			// worth blocking: the work in progress should always be keepable.
			var action = document.activeElement;

			if ( action && ( 'save-post' === action.id || 'post-preview' === action.id ) ) {
				return;
			}

			event.preventDefault();

			ask().then( function ( errors ) {
				var field = show( errors );

				if ( ! field ) {
					cleared = true;
					replay( element );

					return;
				}

				reveal( field );

				if ( ! isPostForm ) {
					return;
				}

				// The spinner core starts on submit never stops on its own.
				element.classList.remove( 'submitting' );
				document.querySelectorAll( '#publishing-action .spinner, #saving-action .spinner' ).forEach( function ( spinner ) {
					spinner.classList.remove( 'is-active' );
				} );
				document.querySelectorAll( '#post input[type="submit"], #post button[type="submit"]' ).forEach( function ( button ) {
					button.disabled = false;
					button.classList.remove( 'disabled' );
				} );
			} );
		} );
	}

	/**
	 * Whether the block editor's store is up and pointed at this post.
	 *
	 * @return {boolean} True once the editor store can be asked.
	 */
	function blockEditorReady() {
		if ( ! isBlockEditor() ) {
			return false;
		}

		var store = window.wp.data.select( 'core/editor' );

		return !! ( store && store.getCurrentPostId && store.getCurrentPostId() );
	}

	/**
	 * Wait for the block editor to finish booting, then watch it.
	 *
	 * This script has no dependency on `wp-data` — declaring one would pull the
	 * editor's data layer onto every classic screen — so on a block editor
	 * screen it can and does run before that layer exists. Which editor is
	 * running is decided by the body class, which is in the markup from the
	 * start; only the waiting is deferred.
	 *
	 * @param {number} attempt How many times the editor has been waited for.
	 */
	function waitForBlockEditor( attempt ) {
		if ( blockEditorReady() ) {
			watchBlockEditor();

			return;
		}

		// Ten seconds, then leave it. The server still refuses to publish a
		// post whose required fields are empty, so giving up costs the live
		// feedback rather than the rule.
		if ( attempt > 40 ) {
			return;
		}

		window.setTimeout( function () {
			waitForBlockEditor( attempt + 1 );
		}, 250 );
	}

	/**
	 * Start whichever gate this screen needs.
	 */
	function init() {
		if ( ! document.querySelector( '.wpcmb-group-fields' ) ) {
			return;
		}

		if ( document.body.classList.contains( 'block-editor-page' ) ) {
			waitForBlockEditor( 0 );

			return;
		}

		watchClassicEditor();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.wpcmb = window.wpcmb || {};
	window.wpcmb.validateNow = ask;
}() );
