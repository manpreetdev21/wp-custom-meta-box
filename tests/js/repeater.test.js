/**
 * Browser tests for assets/js/repeater.js.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { fixture, setup, jqueryStub } = require( './harness' );

const ROW = fixture( 'repeater-row' );

const globals = {
	wpcmbFields: { i18n: {} },
	wpcmbRepeater: {
		ajaxUrl: 'http://localhost/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		i18n: {
			row: 'Row %d',
			rowFailed: 'Row failed',
			importCsv: 'Import CSV',
			exportCsv: 'Export CSV',
			importFailed: 'Import failed',
			importEmpty: 'No rows',
			importDone: '%d rows added.',
			exportFailed: 'Export failed',
		},
	},
};

/**
 * Build a page with the repeater fixture and a fetch stub that serves rows.
 *
 * The stub returns the same markup the real AJAX endpoint returns, because
 * that markup came out of the real renderer.
 *
 * @param {Object} [responses] Overrides keyed by AJAX action.
 * @return {Object} The harness, plus the recorded requests.
 */
function repeaterPage( responses = {} ) {
	const requests = [];

	const fetchStub = ( url, options ) => {
		const body = options.body;
		const action = body.get( 'action' );

		requests.push( {
			action,
			fieldKey: body.get( 'field_key' ),
			name: body.get( 'name' ),
			index: body.get( 'index' ),
			nonce: body.get( 'nonce' ),
			rows: body.get( 'rows' ),
			csv: body.get( 'csv' ),
		} );

		if ( responses[ action ] ) {
			return Promise.resolve( { json: () => Promise.resolve( responses[ action ] ) } );
		}

		if ( 'wpcmb_repeater_row' === action ) {
			// Mirror the server: the row is rendered at the index asked for.
			const index = body.get( 'index' );
			return Promise.resolve( {
				json: () => Promise.resolve( {
					success: true,
					data: { html: ROW.replace( /99/g, index ) },
				} ),
			} );
		}

		return Promise.resolve( { json: () => Promise.resolve( { success: false, data: {} } ) } );
	};

	const page = setup( {
		html: fixture( 'repeater' ),
		scripts: [ 'fields.js', 'repeater.js' ],
		globals: Object.assign( {}, globals, { jQuery: jqueryStub() } ),
		fetch: fetchStub,
	} );

	page.requests = requests;

	return page;
}

/**
 * The outer repeater.
 *
 * @param {Object} page The harness.
 * @return {Element} The repeater root.
 */
function outer( page ) {
	return page.$( '[data-wpcmb-repeater]' );
}

/**
 * The outer repeater's direct rows.
 *
 * @param {Object} page The harness.
 * @return {Element[]} Row elements.
 */
function rows( page ) {
	return Array.from( outer( page ).querySelector( ':scope > .wpcmb-repeater__rows' ).children );
}

/**
 * The outer repeater's own add button.
 *
 * Scoped deliberately. Once a row exists, that row's nested repeater has an
 * add button of its own that comes first in document order, so a plain
 * `.wpcmb-repeater__add` lookup would start driving the nested repeater.
 *
 * @param {Object} page The harness.
 * @return {Element} The button.
 */
function addButton( page ) {
	return outer( page ).querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__add' );
}

/**
 * Add a row to the outer repeater and wait for it.
 *
 * @param {Object} page The harness.
 */
async function addRow( page ) {
	page.click( addButton( page ) );
	await page.settle();
}

test( 'a repeater starts with no rows and an enabled add button', () => {
	const page = repeaterPage();

	assert.equal( rows( page ).length, 0 );
	assert.equal( addButton( page ).disabled, false );
} );

test( 'adding a row fetches it from the server and appends it', async () => {
	const page = repeaterPage();

	await addRow( page );

	assert.equal( page.requests.length, 1, 'one request was made' );
	assert.equal( page.requests[ 0 ].action, 'wpcmb_repeater_row' );
	assert.equal( page.requests[ 0 ].nonce, 'test-nonce', 'the nonce is sent' );
	assert.equal( page.requests[ 0 ].fieldKey, 'field_team000001', 'the field is identified by key' );
	assert.equal( page.requests[ 0 ].index, '0', 'the new row index is the current row count' );

	assert.equal( rows( page ).length, 1, 'the row was added to the page' );
} );

test( 'rows are numbered with the configured label', async () => {
	const page = repeaterPage();

	await addRow( page );
	await addRow( page );

	const titles = rows( page ).map(
		( row ) => row.querySelector( ':scope > .wpcmb-repeater__header > .wpcmb-repeater__title' ).textContent
	);

	assert.deepEqual( titles, [ 'Member 1', 'Member 2' ], 'the row_label setting is honoured' );
} );

test( 'input names are renumbered so rows post in the order shown', async () => {
	const page = repeaterPage();

	await addRow( page );
	await addRow( page );

	const names = page.$$( 'input[name*="[mname]"]' ).map( ( input ) => input.name );

	assert.deepEqual(
		names,
		[ 'wpcmb_values[team][0][mname]', 'wpcmb_values[team][1][mname]' ],
		'each row owns a distinct index'
	);
} );

test( 'removing a row renumbers the rows after it', async () => {
	const page = repeaterPage();

	for ( let i = 0; i < 3; i++ ) {
		await addRow( page );
	}

	// Label the middle row so it can be told apart after the removal.
	rows( page )[ 2 ].querySelector( 'input[name*="[mname]"]' ).value = 'third';

	page.click( rows( page )[ 1 ].querySelector( '.wpcmb-repeater__remove' ) );

	assert.equal( rows( page ).length, 2, 'the row is gone' );

	const names = page.$$( 'input[name*="[mname]"]' ).map( ( i ) => i.name );

	assert.deepEqual(
		names,
		[ 'wpcmb_values[team][0][mname]', 'wpcmb_values[team][1][mname]' ],
		'indices close the gap rather than leaving a hole'
	);

	assert.equal(
		rows( page )[ 1 ].querySelector( 'input[name*="[mname]"]' ).value,
		'third',
		'the surviving row kept its value while its index changed'
	);
} );

test( 'moving a row down swaps it with the next and renumbers both', async () => {
	const page = repeaterPage();

	await addRow( page );
	await addRow( page );

	rows( page )[ 0 ].querySelector( 'input[name*="[mname]"]' ).value = 'first';
	rows( page )[ 1 ].querySelector( 'input[name*="[mname]"]' ).value = 'second';

	const down = rows( page )[ 0 ].querySelector( '.wpcmb-repeater__move[data-wpcmb-delta="1"]' );
	page.click( down );

	const values = rows( page ).map( ( row ) => row.querySelector( 'input[name*="[mname]"]' ).value );

	assert.deepEqual( values, [ 'second', 'first' ], 'the rows swapped' );

	const names = rows( page ).map( ( row ) => row.querySelector( 'input[name*="[mname]"]' ).name );

	assert.deepEqual(
		names,
		[ 'wpcmb_values[team][0][mname]', 'wpcmb_values[team][1][mname]' ],
		'and their indices follow the new order'
	);
} );

test( 'the move buttons are disabled at the ends of the list', async () => {
	const page = repeaterPage();

	await addRow( page );
	await addRow( page );

	const first = rows( page )[ 0 ];
	const last = rows( page )[ 1 ];

	assert.equal( first.querySelector( '.wpcmb-repeater__move[data-wpcmb-delta="-1"]' ).disabled, true );
	assert.equal( first.querySelector( '.wpcmb-repeater__move[data-wpcmb-delta="1"]' ).disabled, false );
	assert.equal( last.querySelector( '.wpcmb-repeater__move[data-wpcmb-delta="1"]' ).disabled, true );
} );

test( 'duplicating a row copies its values and gives the copy its own index', async () => {
	const page = repeaterPage();

	await addRow( page );

	rows( page )[ 0 ].querySelector( 'input[name*="[mname]"]' ).setAttribute( 'value', 'Ada' );

	page.click( rows( page )[ 0 ].querySelector( '.wpcmb-repeater__duplicate' ) );

	assert.equal( rows( page ).length, 2, 'there are two rows' );

	const names = rows( page ).map( ( row ) => row.querySelector( 'input[name*="[mname]"]' ).name );

	assert.deepEqual( names, [ 'wpcmb_values[team][0][mname]', 'wpcmb_values[team][1][mname]' ] );
	assert.equal(
		rows( page )[ 1 ].querySelector( 'input[name*="[mname]"]' ).value,
		'Ada',
		'the copy carries the original values'
	);
} );

test( 'collapsing a row hides its fields and reports the state', async () => {
	const page = repeaterPage();

	await addRow( page );

	const row = rows( page )[ 0 ];
	const toggle = row.querySelector( '.wpcmb-repeater__toggle' );

	assert.equal( row.classList.contains( 'is-collapsed' ), false );

	page.click( toggle );

	assert.equal( row.classList.contains( 'is-collapsed' ), true );
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'false' );

	page.click( toggle );

	assert.equal( row.classList.contains( 'is-collapsed' ), false );
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'true' );
} );

test( 'a nested repeater is wired up and keeps its own indices', async () => {
	const page = repeaterPage();

	await addRow( page );

	const inner = rows( page )[ 0 ].querySelector( '[data-wpcmb-repeater]' );

	assert.ok( inner, 'the row contains a nested repeater' );
	assert.equal( inner.dataset.wpcmbName, 'wpcmb_values[team][0][links]', 'nested naming follows the outer index' );
	assert.equal( inner.dataset.wpcmbReady, '1', 'the nested repeater was initialised' );
} );

test( 'a click inside a nested repeater does not act on the outer one', async () => {
	const page = repeaterPage();

	await addRow( page );

	const outerRow = rows( page )[ 0 ];
	const innerAdd = outerRow.querySelector( '[data-wpcmb-repeater] .wpcmb-repeater__add' );

	page.click( innerAdd );
	await page.settle();

	// The outer repeater must still have exactly one row: the click belonged
	// to the nested repeater, and a delegated handler that ignored nesting
	// would have added a row to both.
	assert.equal( rows( page ).length, 1, 'the outer repeater is untouched' );

	const lastRequest = page.requests[ page.requests.length - 1 ];
	assert.equal( lastRequest.name, 'wpcmb_values[team][0][links]', 'the nested repeater made the request' );
} );

test( 'a failed row request reports rather than silently doing nothing', async () => {
	const page = repeaterPage( {
		wpcmb_repeater_row: { success: false, data: { message: 'Nope' } },
	} );

	await addRow( page );

	assert.equal( rows( page ).length, 0, 'no row was added' );
	assert.match( outer( page ).querySelector( '.wpcmb-repeater__note' ).textContent, /Nope/, 'the reason is shown' );
	assert.equal( addButton( page ).disabled, false, 'the button is usable again' );
} );

test( 'drag support is requested from jQuery UI', () => {
	const $ = jqueryStub();

	setup( {
		html: fixture( 'repeater' ),
		scripts: [ 'fields.js', 'repeater.js' ],
		globals: Object.assign( {}, globals, { jQuery: $ } ),
		fetch: () => Promise.resolve( { json: () => Promise.resolve( {} ) } ),
	} );

	assert.equal( $.calls.length > 0, true, 'sortable was initialised' );
	assert.equal( $.calls[ 0 ].config.handle, '.wpcmb-repeater__handle' );
	assert.equal( $.calls[ 0 ].config.items, '> .wpcmb-repeater__row', 'only direct rows are draggable' );
} );

test( 'the repeater still works when jQuery UI sortable is unavailable', () => {
	// Drag is the enhancement; the move buttons are the baseline. A missing
	// sortable must not stop the rest of the repeater from being set up.
	assert.doesNotThrow( () => {
		setup( {
			html: fixture( 'repeater' ),
			scripts: [ 'fields.js', 'repeater.js' ],
			globals: Object.assign( {}, globals, { jQuery: undefined } ),
			fetch: () => Promise.resolve( { json: () => Promise.resolve( {} ) } ),
		} );
	} );
} );

test( 'CSV controls appear only when the field enables them', async () => {
	const page = repeaterPage();

	const labels = Array.from(
		outer( page ).querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__csv' ).querySelectorAll( 'button' )
	).map( ( b ) => b.textContent );

	assert.deepEqual( labels, [ 'Import CSV', 'Export CSV' ], 'both controls are offered' );
} );

test( 'CSV import appends rows and fills their values in', async () => {
	const page = repeaterPage( {
		wpcmb_repeater_csv_import: {
			success: true,
			data: { rows: [ { mname: 'Ada' }, { mname: 'Grace' } ], columns: [ 'mname' ] },
		},
	} );

	await addRow( page );
	page.fill( rows( page )[ 0 ].querySelector( 'input[name*="[mname]"]' ), 'Existing' );

	const file = outer( page ).querySelector( 'input[type="file"]' );

	// Stand in for the file the browser would hand the reader.
	page.window.FileReader = function () {
		this.readAsText = () => {
			this.result = '"mname"\r\n"Ada"\r\n"Grace"';
			this.onload();
		};
	};

	Object.defineProperty( file, 'files', { value: [ { name: 'rows.csv' } ], configurable: true } );

	file.dispatchEvent( new page.window.Event( 'change', { bubbles: true } ) );
	await page.settle();
	await page.settle();
	await page.settle();

	const values = rows( page ).map( ( row ) => row.querySelector( 'input[name*="[mname]"]' ).value );

	// Appending rather than replacing: an import that discarded what was
	// already there would be an unundoable change made before the user has
	// had a chance to look at it.
	assert.deepEqual( values, [ 'Existing', 'Ada', 'Grace' ] );
} );

test( 'a CSV column whose name would break a selector still fills its field', async () => {
	// Column names come from a file. Building a selector out of one means a
	// bracket or quote in a header silently matches the wrong control, or
	// nothing at all, and the import looks like it worked.
	const page = repeaterPage( {
		wpcmb_repeater_csv_import: {
			success: true,
			data: { rows: [ { 'mname': 'Ada' } ], columns: [ 'mname' ] },
		},
	} );

	const file = outer( page ).querySelector( 'input[type="file"]' );

	page.window.FileReader = function () {
		this.readAsText = () => {
			this.result = '"mname"\r\n"Ada"';
			this.onload();
		};
	};

	Object.defineProperty( file, 'files', { value: [ { name: 'rows.csv' } ], configurable: true } );

	file.dispatchEvent( new page.window.Event( 'change', { bubbles: true } ) );
	await page.settle();
	await page.settle();

	assert.equal(
		rows( page )[ 0 ].querySelector( 'input[name*="[mname]"]' ).value,
		'Ada',
		'the value reached the right control'
	);
} );

test( 'CSV export sends the values currently on screen, not the saved ones', async () => {
	const page = repeaterPage( {
		wpcmb_repeater_csv_export: { success: true, data: { filename: 'x.csv', csv: '"mname"\r\n"Ada"' } },
	} );

	await addRow( page );

	page.fill( rows( page )[ 0 ].querySelector( 'input[name*="[mname]"]' ), 'Unsaved edit' );

	page.window.URL.createObjectURL = () => 'blob:x';
	page.window.URL.revokeObjectURL = () => {};

	// The script triggers the download by clicking a temporary anchor. jsdom
	// treats that as navigation, which it does not implement, so the click is
	// neutered — the download itself is the browser's job, not the script's.
	page.window.HTMLAnchorElement.prototype.click = function () {};

	page.click( outer( page ).querySelector( ':scope > .wpcmb-repeater__actions > .wpcmb-repeater__csv button:last-of-type' ) );
	await page.settle();

	const request = page.requests.find( ( r ) => 'wpcmb_repeater_csv_export' === r.action );

	assert.ok( request, 'an export request was made' );

	const sent = JSON.parse( request.rows );

	assert.equal( sent.length, 1 );
	assert.equal( sent[ 0 ].mname, 'Unsaved edit', 'the unsaved value is exported' );
} );
