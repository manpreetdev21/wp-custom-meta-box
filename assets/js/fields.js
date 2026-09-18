/**
 * Field behaviour on edit screens.
 *
 * Four jobs: live conditional logic, the media picker, tab and accordion
 * markers, and the small per-type enhancements. Everything degrades — a
 * screen with this script blocked still renders working form controls and
 * still saves, because the server re-decides visibility and re-validates.
 *
 * @package WPCMB
 */

( function () {
	'use strict';

	var config = window.wpcmbFields || {};

	/**
	 * All field wrappers within a root.
	 *
	 * @param {Element} root Container.
	 * @return {Element[]} Field wrappers.
	 */
	function fieldsIn( root ) {
		return Array.prototype.slice.call( root.querySelectorAll( '.wpcmb-field' ) );
	}

	/* --------------------------------------------------------------------
	 * Conditional logic.
	 * ----------------------------------------------------------------- */

	/**
	 * The current value of a field wrapper, as the rules compare it.
	 *
	 * @param {Element} field Field wrapper.
	 * @return {string|string[]} The value.
	 */
	function valueOf( field ) {
		var checkable = field.querySelectorAll( 'input[type="checkbox"], input[type="radio"]' );

		if ( checkable.length ) {
			var checked = Array.prototype.filter.call( checkable, function ( input ) {
				return input.checked;
			} ).map( function ( input ) {
				return input.value;
			} );

			return 1 === checked.length ? checked[ 0 ] : checked;
		}

		var select = field.querySelector( 'select' );

		if ( select && select.multiple ) {
			return Array.prototype.filter.call( select.options, function ( option ) {
				return option.selected;
			} ).map( function ( option ) {
				return option.value;
			} );
		}

		var input = field.querySelector( 'input, select, textarea' );

		return input ? input.value : '';
	}

	/**
	 * Whether one rule is met.
	 *
	 * Mirrors WPCMB\Fields\Conditional::rule_met(). The server decides what
	 * is actually stored; this only decides what is shown, so a disagreement
	 * is a visual bug rather than a data one — but they are kept in step
	 * deliberately, including "0" not counting as empty.
	 *
	 * @param {Object}          rule  The rule.
	 * @param {string|string[]} value Current value.
	 * @return {boolean} Whether the rule is met.
	 */
	function ruleMet( rule, value ) {
		var expected = String( rule.value == null ? '' : rule.value );

		if ( Array.isArray( value ) && 'empty' !== rule.operator && 'not_empty' !== rule.operator ) {
			return value.some( function ( item ) {
				return ruleMet( rule, item );
			} );
		}

		var actual = Array.isArray( value ) ? '' : String( value == null ? '' : value );
		var isEmpty = Array.isArray( value ) ? 0 === value.length : '' === actual;
		var numeric = '' !== actual && '' !== expected && ! isNaN( actual ) && ! isNaN( expected );

		switch ( rule.operator ) {
			case '!=':
				return actual !== expected;
			case '>':
				return numeric && parseFloat( actual ) > parseFloat( expected );
			case '<':
				return numeric && parseFloat( actual ) < parseFloat( expected );
			case 'contains':
				return '' !== expected && -1 !== actual.indexOf( expected );
			case 'not_contains':
				return '' === expected || -1 === actual.indexOf( expected );
			case 'empty':
				return isEmpty;
			case 'not_empty':
				return ! isEmpty;
			case 'pattern':
				try {
					return '' !== expected && new RegExp( expected ).test( actual );
				} catch ( e ) {
					return false;
				}
			default:
				return actual === expected;
		}
	}

	/**
	 * Show or hide every conditional field within a root.
	 *
	 * Hidden fields have their controls disabled, so a hidden required input
	 * cannot block submission with a validation message pointing at something
	 * the user cannot see, and a disabled control posts nothing — which the
	 * server reads as "not on the form" and leaves untouched.
	 *
	 * @param {Element} root Container.
	 */
	function applyConditionals( root ) {
		var byKey = {};

		fieldsIn( root ).forEach( function ( field ) {
			if ( field.dataset.wpcmbKey ) {
				byKey[ field.dataset.wpcmbKey ] = field;
			}
		} );

		fieldsIn( root ).forEach( function ( field ) {
			if ( ! field.dataset.wpcmbConditional ) {
				return;
			}

			var logic;

			try {
				logic = JSON.parse( field.dataset.wpcmbConditional );
			} catch ( e ) {
				return;
			}

			var results = ( logic.rules || [] ).map( function ( rule ) {
				var target = byKey[ rule.field ];
				return target ? ruleMet( rule, valueOf( target ) ) : false;
			} );

			var satisfied = 'any' === logic.logic
				? results.some( Boolean )
				: results.length > 0 && results.every( Boolean );

			var visible = 'hide' === logic.action ? ! satisfied : satisfied;

			field.hidden = ! visible;

			field.querySelectorAll( 'input, select, textarea' ).forEach( function ( input ) {
				input.disabled = ! visible;
			} );
		} );
	}

	/* --------------------------------------------------------------------
	 * Media picker.
	 * ----------------------------------------------------------------- */

	/**
	 * Wire one media field to the WordPress media library.
	 *
	 * @param {Element} root Media field container.
	 */
	function initMedia( root ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var ids = root.querySelector( '.wpcmb-media__ids' );
		var list = root.querySelector( '.wpcmb-media__list' );
		var multiple = '1' === root.dataset.wpcmbMultiple;
		var frame = null;

		function currentIds() {
			return ids.value ? ids.value.split( ',' ).filter( Boolean ) : [];
		}

		function render( attachments ) {
			list.textContent = '';

			attachments.forEach( function ( attachment ) {
				var item = document.createElement( 'li' );
				item.className = 'wpcmb-media__item';
				item.dataset.wpcmbId = attachment.id;

				var url = attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.icon;

				if ( url ) {
					var img = document.createElement( 'img' );
					img.src = url;
					img.alt = '';
					img.loading = 'lazy';
					item.appendChild( img );
				}

				var title = document.createElement( 'span' );
				title.className = 'wpcmb-media__title';
				title.textContent = attachment.title || String( attachment.id );
				item.appendChild( title );

				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'wpcmb-media__remove';
				remove.textContent = '×';
				remove.setAttribute( 'aria-label', config.i18n.remove );
				item.appendChild( remove );

				list.appendChild( item );
			} );
		}

		root.querySelector( '.wpcmb-media__select' ).addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = window.wp.media( {
					title: config.i18n.selectMedia,
					multiple: multiple ? 'add' : false,
					library: root.dataset.wpcmbMime ? { type: root.dataset.wpcmbMime } : {},
				} );

				frame.on( 'select', function () {
					var selection = frame.state().get( 'selection' ).toJSON();
					var picked = multiple
						? currentIds().concat( selection.map( function ( a ) { return String( a.id ); } ) )
						: selection.slice( 0, 1 ).map( function ( a ) { return String( a.id ); } );

					// Same file picked twice is one entry, not two.
					ids.value = picked.filter( function ( id, index ) {
						return picked.indexOf( id ) === index;
					} ).join( ',' );

					render( multiple ? selectionFor( ids.value, selection ) : selection.slice( 0, 1 ) );
					ids.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
			}

			frame.open();
		} );

		/**
		 * Merge freshly selected attachments with ones already shown.
		 *
		 * @param {string} value      Comma separated ids.
		 * @param {Array}  selection  Newly selected attachments.
		 * @return {Array} Attachments in stored order.
		 */
		function selectionFor( value, selection ) {
			var known = {};

			selection.forEach( function ( attachment ) {
				known[ String( attachment.id ) ] = attachment;
			} );

			return value.split( ',' ).filter( Boolean ).map( function ( id ) {
				if ( known[ id ] ) {
					return known[ id ];
				}

				var existing = list.querySelector( '[data-wpcmb-id="' + id + '"]' );
				var img = existing ? existing.querySelector( 'img' ) : null;

				return {
					id: id,
					title: existing ? existing.querySelector( '.wpcmb-media__title' ).textContent : id,
					icon: img ? img.src : '',
				};
			} );
		}

		root.querySelector( '.wpcmb-media__clear' ).addEventListener( 'click', function () {
			ids.value = '';
			list.textContent = '';
			ids.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		list.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '.wpcmb-media__remove' );

			if ( ! remove ) {
				return;
			}

			var item = remove.closest( '.wpcmb-media__item' );
			var id = item.dataset.wpcmbId;

			ids.value = currentIds().filter( function ( existing ) {
				return existing !== id;
			} ).join( ',' );

			item.remove();
			ids.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	/* --------------------------------------------------------------------
	 * Tabs and accordions.
	 * ----------------------------------------------------------------- */

	/**
	 * Turn tab markers into a tab bar over the fields that follow each one.
	 *
	 * Markers are siblings rather than containers, so the server never has to
	 * model nesting and a tab cannot half-contain a field.
	 *
	 * @param {Element} root Group container.
	 */
	function initTabs( root ) {
		var markers = root.querySelectorAll( '[data-wpcmb-tab]' );

		if ( ! markers.length ) {
			return;
		}

		var bar = document.createElement( 'div' );
		bar.className = 'wpcmb-tabs';
		bar.setAttribute( 'role', 'tablist' );

		var panels = [];

		markers.forEach( function ( marker, index ) {
			var wrapper = marker.closest( '.wpcmb-field' );
			var panel = document.createElement( 'div' );
			panel.className = 'wpcmb-tab-panel';
			panel.setAttribute( 'role', 'tabpanel' );

			var sibling = wrapper.nextElementSibling;

			while ( sibling && ! sibling.querySelector( '[data-wpcmb-tab]' ) ) {
				var next = sibling.nextElementSibling;
				panel.appendChild( sibling );
				sibling = next;
			}

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'wpcmb-tabs__button';
			button.setAttribute( 'role', 'tab' );
			button.textContent = marker.dataset.wpcmbLabel || '';
			button.addEventListener( 'click', function () {
				panels.forEach( function ( entry, i ) {
					entry.panel.hidden = i !== index;
					entry.button.setAttribute( 'aria-selected', String( i === index ) );
					entry.button.classList.toggle( 'is-active', i === index );
				} );
			} );

			bar.appendChild( button );
			wrapper.replaceWith( panel );
			panels.push( { panel: panel, button: button } );
		} );

		root.insertBefore( bar, root.firstChild );

		if ( panels.length ) {
			panels[ 0 ].button.click();
		}
	}

	/**
	 * Turn accordion markers into collapsible sections over the fields that
	 * follow each one.
	 *
	 * Built on `<details>` rather than a button and a hidden div: open and
	 * closed state, the disclosure triangle, keyboard operation and the
	 * screen-reader announcement are all native, so there is nothing here to
	 * keep in sync and nothing to get wrong on the accessibility side.
	 *
	 * Runs after initTabs so that an accordion inside a tab is sliced within
	 * its panel — the sibling walk below never crosses a parent.
	 *
	 * @param {Element} root Group container.
	 */
	function initAccordions( root ) {
		root.querySelectorAll( '[data-wpcmb-accordion]' ).forEach( function ( marker ) {
			var wrapper = marker.closest( '.wpcmb-field' );

			if ( ! wrapper ) {
				return;
			}

			var details = document.createElement( 'details' );
			details.className = 'wpcmb-accordion';
			details.open = '1' === marker.dataset.wpcmbOpen;

			var summary = document.createElement( 'summary' );
			summary.className = 'wpcmb-accordion__summary';
			summary.textContent = marker.dataset.wpcmbLabel || '';

			var body = document.createElement( 'div' );
			body.className = 'wpcmb-accordion__body';

			var sibling = wrapper.nextElementSibling;

			while ( sibling && ! sibling.querySelector( '[data-wpcmb-accordion]' ) ) {
				var next = sibling.nextElementSibling;
				body.appendChild( sibling );
				sibling = next;
			}

			details.appendChild( summary );
			details.appendChild( body );
			wrapper.replaceWith( details );
		} );
	}

	/* --------------------------------------------------------------------
	 * Small per-type enhancements.
	 * ----------------------------------------------------------------- */

	/**
	 * Read a hex colour as the six-digit form a colour input will accept.
	 *
	 * A colour input takes `#rrggbb` and nothing else: assigning the shorthand
	 * `#00f` does not fail loudly, it silently resets the swatch to black. The
	 * stored value keeps whichever form was typed — `sanitize_hex_color()`
	 * accepts both — so only the swatch needs the expansion.
	 *
	 * @param {string} value Typed value.
	 * @return {string} Six-digit hex, or an empty string if it is not one yet.
	 */
	function expandHex( value ) {
		var hex = String( value ).trim();

		if ( /^#[\da-f]{3}$/i.test( hex ) ) {
			return '#' + hex.slice( 1 ).replace( /./g, function ( digit ) {
				return digit + digit;
			} );
		}

		return /^#[\da-f]{6}$/i.test( hex ) ? hex : '';
	}

	/**
	 * Keep a colour swatch and its hex code showing the same value.
	 *
	 * The code input is the one that submits, so the swatch only ever writes
	 * into it — never the other way round for an incomplete entry. Typing is
	 * mirrored onto the swatch only once the text parses as a full hex colour,
	 * otherwise the swatch would lurch around while a value is half typed.
	 *
	 * @param {Element} root Container.
	 */
	function initColors( root ) {
		root.querySelectorAll( '.wpcmb-color' ).forEach( function ( wrapper ) {
			var swatch = wrapper.querySelector( '.wpcmb-color__swatch' );
			var code = wrapper.querySelector( '.wpcmb-color__code' );

			if ( ! swatch || ! code ) {
				return;
			}

			swatch.addEventListener( 'input', function () {
				code.value = swatch.value;

				// The swatch is not the named input, so nothing else would
				// hear about the change: conditional logic, the repeater row
				// preview and any dirty-state tracking all watch the code.
				code.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				code.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );

			code.addEventListener( 'input', function () {
				var hex = expandHex( code.value );

				if ( hex ) {
					swatch.value = hex;
				}
			} );
		} );
	}

	/**
	 * Wire the enhancements that need no third-party library.
	 *
	 * Anything needing a tile provider or an encoder stays as its stored
	 * text until a site supplies one, which is more honest than an empty box.
	 *
	 * @param {Element} root Container.
	 */
	function initEnhancements( root ) {
		root.querySelectorAll( 'input[type="range"]' ).forEach( function ( input ) {
			var output = root.querySelector( 'output[for="' + input.id + '"]' );

			if ( output ) {
				input.addEventListener( 'input', function () {
					output.textContent = input.value;
				} );
			}
		} );

		/**
		 * Fires once field enhancements have run.
		 *
		 * Sites add map, QR, barcode or icon behaviour here, matching the
		 * config supplied through the `wpcmb/field/enhanced_config` filter.
		 */
		document.dispatchEvent( new CustomEvent( 'wpcmb:enhance', { detail: { root: root } } ) );
	}

	/* --------------------------------------------------------------------
	 * Boot.
	 * ----------------------------------------------------------------- */

	/**
	 * Initialise every field inside a container.
	 *
	 * Exposed so that dynamically added markup — a repeater row — can be
	 * initialised the same way as the fields present at page load.
	 *
	 * @param {Element} [root] Container, defaulting to the document.
	 */
	function init( root ) {
		root = root || document;

		// Tabs first: an accordion inside a tab has to be sliced within its
		// panel, and the panel does not exist until initTabs has built it.
		root.querySelectorAll( '.wpcmb-group-fields' ).forEach( initTabs );
		root.querySelectorAll( '.wpcmb-group-fields' ).forEach( initAccordions );
		root.querySelectorAll( '[data-wpcmb-media]' ).forEach( initMedia );

		initColors( root );
		initEnhancements( root );
		applyConditionals( root );

		root.addEventListener( 'change', function () {
			applyConditionals( root );
		} );

		root.addEventListener( 'input', function () {
			applyConditionals( root );
		} );
	}

	window.wpcmb = window.wpcmb || {};
	window.wpcmb.initFields = init;

	document.addEventListener( 'DOMContentLoaded', function () {
		init( document );
	} );
}() );
