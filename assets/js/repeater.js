/**
 * Repeater rows on edit screens.
 *
 * Rows are fetched from the server rather than cloned from a template,
 * because a row can contain a control that is more than its markup — a
 * cloned wp_editor() is a dead editor. Duplicating an existing row is the
 * one case that does clone, and it re-runs field initialisation afterwards.
 *
 * @package WPCMB
 */

( function ( $ ) {
	'use strict';

	var config = window.wpcmbRepeater || {};
	var i18n = config.i18n || {};

	/**
	 * Rows currently in a repeater.
	 *
	 * Only direct children count: a nested repeater's rows belong to it, and
	 * treating them as the outer repeater's rows would renumber the wrong
	 * inputs and merge two repeaters' data on save.
	 *
	 * @param {Element} repeater Repeater root.
	 * @return {Element[]} Row elements.
	 */
	function rowsOf( repeater ) {
		var list = repeater.querySelector( ':scope > .wpcmb-repeater__rows' );

		return list ? Array.prototype.slice.call( list.children ) : [];
	}

	/**
	 * Rewrite every input name and id in a row to a new index.
	 *
	 * Only the segment belonging to this repeater is rewritten. The prefix is
	 * this repeater's own name, so a nested repeater's deeper indices are
	 * left alone.
	 *
	 * @param {Element} repeater Repeater root.
	 * @param {Element} row      Row element.
	 * @param {number}  index    New index.
	 */
	function reindex( repeater, row, index ) {
		var namePrefix = repeater.dataset.wpcmbName;
		var idPrefix = repeater.dataset.wpcmbId;

		row.dataset.wpcmbIndex = String( index );

		row.querySelectorAll( '[name]' ).forEach( function ( input ) {
			if ( 0 === input.name.indexOf( namePrefix + '[' ) ) {
				input.name = input.name.replace(
					namePrefix + '[' + input.name.slice( namePrefix.length + 1 ).split( ']' )[ 0 ] + ']',
					namePrefix + '[' + index + ']'
				);
			}
		} );

		row.querySelectorAll( '[id]' ).forEach( function ( element ) {
			if ( 0 === element.id.indexOf( idPrefix + '-' ) ) {
				element.id = element.id.replace(
					new RegExp( '^' + escapeRegExp( idPrefix ) + '-\\d+-' ),
					idPrefix + '-' + index + '-'
				);
			}
		} );

		row.querySelectorAll( 'label[for]' ).forEach( function ( label ) {
			if ( 0 === label.htmlFor.indexOf( idPrefix + '-' ) ) {
				label.htmlFor = label.htmlFor.replace(
					new RegExp( '^' + escapeRegExp( idPrefix ) + '-\\d+-' ),
					idPrefix + '-' + index + '-'
				);
			}
		} );
	}

	/**
	 * Escape a string for use inside a regular expression.
	 *
	 * @param {string} value Raw string.
	 * @return {string} Escaped string.
	 */
	function escapeRegExp( value ) {
		return value.replace( /[.*+?^${}()|[\]\\-]/g, '\\$&' );
	}

	/**
	 * Renumber every row and refresh the titles and button states.
	 *
	 * @param {Element} repeater Repeater root.
	 */
	function refresh( repeater ) {
		var rows = rowsOf( repeater );
		var min = parseInt( repeater.dataset.wpcmbMin, 10 ) || 0;
		var max = parseInt( repeater.dataset.wpcmbMax, 10 ) || 0;
		var template = repeater.dataset.wpcmbRowLabel || '';

		rows.forEach( function ( row, index ) {
			reindex( repeater, row, index );

			var title = row.querySelector( ':scope > .wpcmb-repeater__header > .wpcmb-repeater__title' );

			if ( title ) {
				title.textContent = template
					? template.replace( '{index}', String( index + 1 ) )
					: i18n.row.replace( '%d', String( index + 1 ) );
			}

			var remove = row.querySelector( ':scope > .wpcmb-repeater__header .wpcmb-repeater__remove' );

			if ( remove ) {
				remove.disabled = rows.length <= min;
			}

			row.querySelectorAll( ':scope > .wpcmb-repeater__header .wpcmb-repeater__move' ).forEach( function ( button ) {
				var delta = parseInt( button.dataset.wpcmbDelta, 10 );
				button.disabled = ( index + delta ) < 0 || ( index + delta ) >= rows.length;
			} );
		} );

		var add = repeater.querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__add' );

		if ( add ) {
			add.disabled = max > 0 && rows.length >= max;
		}

		rows.forEach( updatePreview );
	}

	/**
	 * Summarise a row's content beside its title.
	 *
	 * A collapsed row otherwise says nothing but "Row 3", which makes a long
	 * list of collapsed rows unnavigable. The first non-empty text value is
	 * usually the one a person would have used to name the row anyway.
	 *
	 * @param {Element} row Row element.
	 */
	function updatePreview( row ) {
		var slot = row.querySelector( ':scope > .wpcmb-repeater__header > .wpcmb-repeater__preview' );

		if ( ! slot ) {
			return;
		}

		var summary = '';

		row.querySelectorAll( 'input[type="text"], input[type="url"], input[type="email"], textarea' ).forEach( function ( input ) {
			if ( ! summary && input.value && ! input.disabled ) {
				summary = input.value;
			}
		} );

		slot.textContent = summary.length > 60 ? summary.slice( 0, 60 ) + '…' : summary;
	}

	/**
	 * Ask the server for a blank row and append it.
	 *
	 * @param {Element}  repeater Repeater root.
	 * @param {Function} [after]  Called with the new row element.
	 * @param {string}   [layout] Layout name, for flexible content.
	 */
	function addRow( repeater, after, layout ) {
		var list = repeater.querySelector( ':scope > .wpcmb-repeater__rows' );
		var add = repeater.querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__add' );

		add.disabled = true;

		var body = new FormData();
		body.append( 'action', 'wpcmb_repeater_row' );
		body.append( 'nonce', config.nonce );
		body.append( 'field_key', repeater.dataset.wpcmbFieldKey );
		body.append( 'name', repeater.dataset.wpcmbName );
		body.append( 'id', repeater.dataset.wpcmbId );
		body.append( 'index', String( rowsOf( repeater ).length ) );

		if ( layout ) {
			body.append( 'layout', layout );
		}

		/*
		 * On a front-end form the caller is a visitor, not an editor, so the
		 * server has no capability to check. The form's signed configuration
		 * travels with the request instead: it names the group, it cannot be
		 * edited by whoever holds it, and the server will only render fields
		 * belonging to that group.
		 */
		var form = repeater.closest( 'form.wpcmb-form' );

		if ( form ) {
			[ 'payload', 'signature' ].forEach( function ( part ) {
				var input = form.querySelector( '[name="wpcmb_form[' + part + ']"]' );

				if ( input ) {
					body.append( 'wpcmb_form[' + part + ']', input.value );
				}
			} );
		}

		window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					window.console.error( '[wpcmb]', result && result.data ? result.data.message : i18n.rowFailed );
					notify( repeater, ( result && result.data && result.data.message ) || i18n.rowFailed );
					return;
				}

				var holder = document.createElement( 'div' );
				holder.innerHTML = result.data.html;

				var row = holder.firstElementChild;
				list.appendChild( row );

				refresh( repeater );
				initRow( row );

				if ( after ) {
					after( row );
				}
			} )
			.catch( function () {
				notify( repeater, i18n.rowFailed );
			} )
			.finally( function () {
				refresh( repeater );
			} );
	}

	/**
	 * Show a message inside a repeater.
	 *
	 * @param {Element} repeater Repeater root.
	 * @param {string}  message  Message text.
	 */
	function notify( repeater, message ) {
		var slot = repeater.querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__csv' );

		if ( ! slot ) {
			return;
		}

		var note = slot.querySelector( '.wpcmb-repeater__note' ) || document.createElement( 'span' );
		note.className = 'wpcmb-repeater__note';
		note.textContent = message;
		slot.appendChild( note );
	}

	/**
	 * Initialise the fields inside a newly added row.
	 *
	 * @param {Element} row Row element.
	 */
	function initRow( row ) {
		if ( window.wpcmb && window.wpcmb.initFields ) {
			window.wpcmb.initFields( row );
		}

		init( row );
	}

	/**
	 * Read a repeater's current values, for CSV export.
	 *
	 * Read from the DOM rather than from what was last saved, so the export
	 * matches what is on screen including unsaved edits.
	 *
	 * @param {Element} repeater Repeater root.
	 * @return {Object[]} One object per row.
	 */
	function collect( repeater ) {
		var prefix = repeater.dataset.wpcmbName;

		return rowsOf( repeater ).map( function ( row ) {
			var values = {};

			row.querySelectorAll( '[name]' ).forEach( function ( input ) {
				if ( 0 !== input.name.indexOf( prefix + '[' ) ) {
					return;
				}

				var remainder = input.name.slice( prefix.length );
				var parts = remainder.match( /\[([^\]]*)\]/g ) || [];

				// Only direct sub fields: prefix[index][name] is two segments.
				if ( 2 !== parts.length ) {
					return;
				}

				if ( ( 'checkbox' === input.type || 'radio' === input.type ) && ! input.checked ) {
					return;
				}

				values[ parts[ 1 ].slice( 1, -1 ) ] = input.value;
			} );

			return values;
		} );
	}

	/**
	 * Wire the CSV import and export controls.
	 *
	 * @param {Element} repeater Repeater root.
	 */
	function initCsv( repeater ) {
		var slot = repeater.querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__csv' );

		if ( ! slot || 'true' !== repeater.dataset.wpcmbCsv || false === config.csv ) {
			return;
		}

		var exportButton = document.createElement( 'button' );
		exportButton.type = 'button';
		exportButton.className = 'wpcmb-btn wpcmb-btn--quiet';
		exportButton.textContent = i18n.exportCsv;

		exportButton.addEventListener( 'click', function () {
			var body = new FormData();
			body.append( 'action', 'wpcmb_repeater_csv_export' );
			body.append( 'nonce', config.nonce );
			body.append( 'field_key', repeater.dataset.wpcmbFieldKey );
			body.append( 'rows', JSON.stringify( collect( repeater ) ) );

			window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( ! result || ! result.success ) {
						notify( repeater, i18n.exportFailed );
						return;
					}

					// A blob URL keeps the file local; nothing is uploaded.
					var blob = new Blob( [ result.data.csv ], { type: 'text/csv;charset=utf-8' } );
					var link = document.createElement( 'a' );

					link.href = URL.createObjectURL( blob );
					link.download = result.data.filename;
					link.click();
					URL.revokeObjectURL( link.href );
				} );
		} );

		var file = document.createElement( 'input' );
		file.type = 'file';
		file.accept = '.csv,text/csv';
		file.hidden = true;

		var importButton = document.createElement( 'button' );
		importButton.type = 'button';
		importButton.className = 'wpcmb-btn wpcmb-btn--quiet';
		importButton.textContent = i18n.importCsv;
		importButton.addEventListener( 'click', function () {
			file.click();
		} );

		file.addEventListener( 'change', function () {
			if ( ! file.files.length ) {
				return;
			}

			var reader = new FileReader();

			reader.onload = function () {
				var body = new FormData();
				body.append( 'action', 'wpcmb_repeater_csv_import' );
				body.append( 'nonce', config.nonce );
				body.append( 'field_key', repeater.dataset.wpcmbFieldKey );
				body.append( 'csv', reader.result );

				window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( result ) {
						if ( ! result || ! result.success ) {
							notify( repeater, ( result && result.data && result.data.message ) || i18n.importFailed );
							return;
						}

						applyRows( repeater, result.data.rows );
					} );
			};

			reader.readAsText( file.files[ 0 ] );
			file.value = '';
		} );

		slot.appendChild( importButton );
		slot.appendChild( exportButton );
		slot.appendChild( file );
	}

	/**
	 * Append imported rows, one server-rendered row at a time.
	 *
	 * Appends rather than replaces: an import that silently discarded what
	 * was already there would be an unundoable change made before the user
	 * has had a chance to look at it.
	 *
	 * @param {Element}  repeater Repeater root.
	 * @param {Object[]} rows     Parsed rows.
	 */
	function applyRows( repeater, rows ) {
		if ( ! rows.length ) {
			notify( repeater, i18n.importEmpty );
			return;
		}

		var remaining = rows.slice();

		function next() {
			if ( ! remaining.length ) {
				notify( repeater, i18n.importDone.replace( '%d', String( rows.length ) ) );
				return;
			}

			var values = remaining.shift();

			addRow( repeater, function ( row ) {
				var inputs = Array.prototype.slice.call( row.querySelectorAll( '[name]' ) );

				Object.keys( values ).forEach( function ( name ) {
					// Matched by scanning rather than by building a selector
					// from the column name: a name is CSV data, and dropping
					// it into a selector makes any bracket or quote in a
					// header silently match the wrong control, or nothing.
					var suffix = '][' + name + ']';

					var input = inputs.filter( function ( candidate ) {
						return candidate.name.slice( -suffix.length ) === suffix;
					} )[ 0 ];

					if ( input ) {
						input.value = values[ name ];
						input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );

				next();
			} );
		}

		next();
	}

	/**
	 * Turn the add button into a layout picker for flexible content.
	 *
	 * The menu is a plain list of buttons grouped by category, toggled by the
	 * add button. It closes on Escape and on a click outside, and every entry
	 * is reachable by keyboard, because a layout picker that only responds to
	 * a pointer would make the whole field unusable without one.
	 *
	 * @param {Element} repeater Repeater root.
	 * @param {Element} add      The add button.
	 */
	function initLayoutPicker( repeater, add ) {
		var layouts;

		try {
			layouts = JSON.parse( repeater.dataset.wpcmbLayouts );
		} catch ( e ) {
			return;
		}

		var menu = document.createElement( 'div' );
		menu.className = 'wpcmb-layouts';
		menu.hidden = true;

		var lastCategory = null;

		layouts.forEach( function ( layout ) {
			if ( layout.category !== lastCategory ) {
				lastCategory = layout.category;

				var heading = document.createElement( 'p' );
				heading.className = 'wpcmb-layouts__category';
				heading.textContent = layout.category;
				menu.appendChild( heading );
			}

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'wpcmb-layouts__item';
			button.dataset.wpcmbLayout = layout.name;

			if ( layout.icon ) {
				var icon = document.createElement( 'span' );
				icon.className = 'dashicons ' + layout.icon;
				icon.setAttribute( 'aria-hidden', 'true' );
				button.appendChild( icon );
			}

			var label = document.createElement( 'span' );
			label.textContent = layout.label;
			button.appendChild( label );

			button.addEventListener( 'click', function () {
				close();
				addRow( repeater, null, layout.name );
			} );

			menu.appendChild( button );
		} );

		function close() {
			menu.hidden = true;
			add.setAttribute( 'aria-expanded', 'false' );
		}

		add.setAttribute( 'aria-expanded', 'false' );
		add.setAttribute( 'aria-haspopup', 'true' );

		add.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			menu.hidden = ! menu.hidden;
			add.setAttribute( 'aria-expanded', String( ! menu.hidden ) );

			if ( ! menu.hidden ) {
				var first = menu.querySelector( '.wpcmb-layouts__item' );

				if ( first ) {
					first.focus();
				}
			}
		} );

		menu.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				close();
				add.focus();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! menu.hidden && ! menu.contains( event.target ) ) {
				close();
			}
		} );

		add.parentNode.insertBefore( menu, add.nextSibling );
	}

	/**
	 * Wire one repeater.
	 *
	 * @param {Element} repeater Repeater root.
	 */
	function initRepeater( repeater ) {
		if ( repeater.dataset.wpcmbReady ) {
			return;
		}

		repeater.dataset.wpcmbReady = '1';

		var list = repeater.querySelector( ':scope > .wpcmb-repeater__rows' );

		var add = repeater.querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__add' );

		if ( repeater.dataset.wpcmbLayouts ) {
			initLayoutPicker( repeater, add );
		} else {
			add.addEventListener( 'click', function () {
				addRow( repeater );
			} );
		}

		repeater.addEventListener( 'click', function ( event ) {
			var row = event.target.closest( '.wpcmb-repeater__row' );

			// A click inside a nested repeater belongs to that repeater.
			if ( ! row || row.parentElement !== list ) {
				return;
			}

			if ( event.target.closest( '.wpcmb-repeater__toggle' ) ) {
				var collapsed = row.classList.toggle( 'is-collapsed' );
				event.target.closest( '.wpcmb-repeater__toggle' ).setAttribute( 'aria-expanded', String( ! collapsed ) );
				return;
			}

			if ( event.target.closest( '.wpcmb-repeater__remove' ) ) {
				row.remove();
				refresh( repeater );
				return;
			}

			if ( event.target.closest( '.wpcmb-repeater__duplicate' ) ) {
				var copy = row.cloneNode( true );
				delete copy.dataset.wpcmbReady;
				row.after( copy );
				refresh( repeater );
				initRow( copy );
				return;
			}

			var move = event.target.closest( '.wpcmb-repeater__move' );

			if ( move ) {
				var delta = parseInt( move.dataset.wpcmbDelta, 10 );

				if ( delta < 0 && row.previousElementSibling ) {
					row.previousElementSibling.before( row );
				} else if ( delta > 0 && row.nextElementSibling ) {
					row.nextElementSibling.after( row );
				}

				refresh( repeater );
				move.focus();
			}
		} );

		// Drag to reorder is the enhancement; the move buttons above are the
		// accessible baseline and work without a pointer.
		if ( $ && $.fn && $.fn.sortable ) {
			$( list ).sortable( {
				handle: '.wpcmb-repeater__handle',
				items: '> .wpcmb-repeater__row',
				axis: 'y',
				placeholder: 'wpcmb-repeater__placeholder',
				forcePlaceholderSize: true,
				update: function () {
					refresh( repeater );
				},
			} );
		}

		initCsv( repeater );
		refresh( repeater );
	}

	/**
	 * Wire every repeater inside a container.
	 *
	 * @param {Element} [root] Container, defaulting to the document.
	 */
	function init( root ) {
		( root || document ).querySelectorAll( '[data-wpcmb-repeater]' ).forEach( initRepeater );
	}

	window.wpcmb = window.wpcmb || {};
	window.wpcmb.initRepeaters = init;

	document.addEventListener( 'DOMContentLoaded', function () {
		init( document );
	} );
}( window.jQuery ) );
