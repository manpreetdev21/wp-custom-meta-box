/**
 * Browser tests for assets/js/enhanced.js and assets/js/codes.js.
 *
 * These controls all replace or wrap markup the server rendered, and every one
 * of them writes into an input the server owns. What matters here is that the
 * stored value is right, because that is the only part that survives the page.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { fixture, setup } = require( './harness' );

const globals = {
	wpcmbFields: {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		dashicons: [ 'dashicons-admin-home', 'dashicons-star-filled', 'dashicons-cart' ],
		i18n: {
			remove: 'Remove',
			selectMedia: 'Select media',
			iconDashicons: 'Dashicons',
			iconMedia: 'Media Library',
			iconUrl: 'URL',
			iconUseUrl: 'Use this URL',
			embedLoading: 'Loading the preview…',
			embedNone: 'Nothing could be embedded from that URL.',
			qrTooLong: 'That is too long to fit in a QR code.',
			barcodeUnsupported: 'A barcode can only hold plain ASCII characters.',
		},
	},
};

/**
 * A page with the advanced fixture and both scripts.
 *
 * @param {Function} [fetchStub] Stub for window.fetch.
 * @return {Object} The harness.
 */
function advancedPage( fetchStub ) {
	return setup( {
		html: fixture( 'advanced' ),
		scripts: [ 'fields.js', 'codes.js', 'enhanced.js' ],
		globals,
		fetch: fetchStub,
	} );
}

/**
 * The hidden value input for one enhanced control.
 *
 * @param {Object} page Harness.
 * @param {string} kind Enhancement name.
 * @return {HTMLElement} The input.
 */
function valueOf( page, kind ) {
	return page.$( '[data-wpcmb-enhanced="' + kind + '"] .wpcmb-enhanced__value' );
}

/* -------------------------------------------------------------------------
 * Icon picker.
 * ---------------------------------------------------------------------- */

test( 'the icon picker is not built until it is opened', () => {
	const page = advancedPage();

	// A Dashicons grid is several hundred buttons and a page can hold many
	// icon fields, so building them all on load costs a visible pause.
	assert.equal( page.$$( '.wpcmb-icon__option' ).length, 0, 'nothing is built up front' );

	page.click( page.$( '.wpcmb-icon__choose' ) );

	assert.equal( page.$$( '.wpcmb-icon__option' ).length, 3, 'the grid appears on open' );
	assert.equal( page.$$( '.wpcmb-icon__tab' ).length, 3, 'Dashicons, Media Library and URL' );
} );

test( 'choosing a Dashicon stores its name and shows it', () => {
	const page = advancedPage();

	page.click( page.$( '.wpcmb-icon__choose' ) );
	page.click( page.$$( '.wpcmb-icon__option' )[ 1 ] );

	assert.equal( valueOf( page, 'icon' ).value, 'dashicons-star-filled' );
	assert.ok(
		page.$( '.wpcmb-icon__current .wpcmb-icon__glyph' ),
		'the preview shows the glyph'
	);
	assert.equal( page.$( '.wpcmb-icon__picker' ).hidden, true, 'and the picker closes' );
} );

test( 'an icon option is announced by name, not by its decorative glyph', () => {
	const page = advancedPage();

	page.click( page.$( '.wpcmb-icon__choose' ) );

	assert.equal( page.$$( '.wpcmb-icon__option' )[ 0 ].getAttribute( 'aria-label' ), 'admin-home' );
} );

test( 'a URL can be used as the icon', () => {
	const page = advancedPage();

	page.click( page.$( '.wpcmb-icon__choose' ) );
	page.click( page.$$( '.wpcmb-icon__tab' )[ 2 ] );

	const panel = page.$$( '.wpcmb-icon__panel' )[ 1 ];

	page.fill( panel.querySelector( 'input' ), 'https://example.com/logo.png' );
	page.click( panel.querySelector( 'button' ) );

	assert.equal( valueOf( page, 'icon' ).value, 'https://example.com/logo.png' );
	assert.ok( page.$( '.wpcmb-icon__current img' ), 'the preview shows the image' );
} );

test( 'clearing the icon empties the stored value', () => {
	const page = advancedPage();

	page.click( page.$( '.wpcmb-icon__choose' ) );
	page.click( page.$$( '.wpcmb-icon__option' )[ 0 ] );
	page.click( page.$( '.wpcmb-icon__clear' ) );

	assert.equal( valueOf( page, 'icon' ).value, '' );
	assert.equal( page.$( '.wpcmb-icon__current' ).textContent, '' );
} );

/* -------------------------------------------------------------------------
 * Map.
 * ---------------------------------------------------------------------- */

test( 'a complete coordinate pair is folded into the stored value', () => {
	const page = advancedPage();

	page.fill( page.$( '.wpcmb-map__lat' ), '51.5074' );
	page.fill( page.$( '.wpcmb-map__lng' ), '-0.1278' );

	assert.equal( valueOf( page, 'map' ).value, '51.5074,-0.1278' );
} );

test( 'an address is appended after the coordinates', () => {
	const page = advancedPage();

	page.fill( page.$( '.wpcmb-map__lat' ), '51.5074' );
	page.fill( page.$( '.wpcmb-map__lng' ), '-0.1278' );
	page.fill( page.$( '.wpcmb-map__address' ), 'Trafalgar Square' );

	assert.equal( valueOf( page, 'map' ).value, '51.5074,-0.1278,Trafalgar Square' );
} );

test( 'half a coordinate pair stores nothing', () => {
	const page = advancedPage();

	// The server rejects anything that is not a full pair, so storing a
	// half-typed one would mean silently discarding it on save instead.
	page.fill( page.$( '.wpcmb-map__lat' ), '51.5074' );

	assert.equal( valueOf( page, 'map' ).value, '' );
	assert.equal( page.$( '.wpcmb-map__open' ).hidden, true, 'and there is nothing to open' );
} );

test( 'a complete pair offers a link out to a map', () => {
	const page = advancedPage();

	page.fill( page.$( '.wpcmb-map__lat' ), '51.5074' );
	page.fill( page.$( '.wpcmb-map__lng' ), '-0.1278' );

	const link = page.$( '.wpcmb-map__open' );

	assert.equal( link.hidden, false );
	assert.match( link.href, /mlat=51\.5074/ );
	assert.equal( link.rel, 'noopener noreferrer', 'a new-tab link is not left exploitable' );
} );

/* -------------------------------------------------------------------------
 * QR and barcode.
 * ---------------------------------------------------------------------- */

test( 'typing a value draws a QR code', () => {
	const page = advancedPage();
	const input = page.$( '[data-wpcmb-enhanced="qr"] input' );

	page.fill( input, 'https://example.com' );

	const svg = page.$( '[data-wpcmb-enhanced="qr"] .wpcmb-enhanced__preview svg' );

	assert.ok( svg, 'an SVG is drawn' );
	assert.equal( svg.getAttribute( 'aria-label' ), 'https://example.com', 'and it is labelled' );
} );

test( 'clearing the value removes the QR code', () => {
	const page = advancedPage();
	const input = page.$( '[data-wpcmb-enhanced="qr"] input' );

	page.fill( input, 'https://example.com' );
	page.fill( input, '' );

	assert.equal( page.$( '[data-wpcmb-enhanced="qr"] .wpcmb-enhanced__preview' ).textContent, '' );
} );

test( 'a value too long for a QR code says so rather than drawing nothing', () => {
	const page = advancedPage();

	page.fill( page.$( '[data-wpcmb-enhanced="qr"] input' ), 'x'.repeat( 3000 ) );

	assert.equal(
		page.$( '[data-wpcmb-enhanced="qr"] .wpcmb-enhanced__preview' ).textContent,
		globals.wpcmbFields.i18n.qrTooLong
	);
} );

test( 'the barcode field keeps its value and draws nothing', () => {
	const page = advancedPage();
	const input = page.$( '[data-wpcmb-enhanced="barcode"] input' );

	// No encoder ships for barcode — see the note in codes.js. What must hold
	// is that the field still stores what was typed rather than breaking.
	page.fill( input, 'ABC-12345' );

	assert.equal( input.value, 'ABC-12345' );
	assert.equal( page.$( '[data-wpcmb-enhanced="barcode"] .wpcmb-enhanced__preview' ).textContent, '' );
} );

/* -------------------------------------------------------------------------
 * Embed preview.
 * ---------------------------------------------------------------------- */

test( 'an embed URL is previewed from the server', async () => {
	let asked = null;

	const page = advancedPage( ( url, options ) => {
		asked = options.body.get( 'url' );

		return Promise.resolve( {
			json: () => Promise.resolve( { success: true, data: { html: '<iframe src="x"></iframe>' } } ),
		} );
	} );

	const input = page.$( '[data-wpcmb-enhanced="embed"] input' );

	input.value = 'https://example.com/watch';
	page.window.document.querySelector( '[data-wpcmb-enhanced="embed"] input' )
		.dispatchEvent( new page.window.Event( 'change', { bubbles: true } ) );

	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	assert.equal( asked, 'https://example.com/watch', 'the URL is sent to the server' );
	assert.ok(
		page.$( '[data-wpcmb-enhanced="embed"] .wpcmb-enhanced__preview iframe' ),
		'the returned markup is shown'
	);
} );

test( 'a URL that embeds nothing reports it instead of staying blank', async () => {
	const page = advancedPage( () =>
		Promise.resolve( { json: () => Promise.resolve( { success: false, data: {} } ) } )
	);

	const input = page.$( '[data-wpcmb-enhanced="embed"] input' );

	input.value = 'https://example.com/nope';
	input.dispatchEvent( new page.window.Event( 'change', { bubbles: true } ) );

	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	assert.equal(
		page.$( '[data-wpcmb-enhanced="embed"] .wpcmb-enhanced__preview' ).textContent,
		globals.wpcmbFields.i18n.embedNone
	);
} );

test( 'a network failure does not leave the preview stuck on loading', async () => {
	const page = advancedPage( () => Promise.reject( new Error( 'offline' ) ) );

	const input = page.$( '[data-wpcmb-enhanced="embed"] input' );

	input.value = 'https://example.com/watch';
	input.dispatchEvent( new page.window.Event( 'change', { bubbles: true } ) );

	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	assert.equal(
		page.$( '[data-wpcmb-enhanced="embed"] .wpcmb-enhanced__preview' ).textContent,
		globals.wpcmbFields.i18n.embedNone
	);
} );

/* -------------------------------------------------------------------------
 * Signature, which jsdom cannot draw on.
 * ---------------------------------------------------------------------- */

test( 'a canvas with no 2d context is left alone rather than throwing', () => {
	// jsdom has no canvas backend, which is the same situation as a browser
	// with canvas disabled. Throwing here would take out every control that
	// initialises after it.
	const page = advancedPage();

	assert.ok( page.$( '.wpcmb-signature__pad' ), 'the pad is still in the markup' );
	assert.equal( valueOf( page, 'signature' ).value, '', 'and the stored value is untouched' );
} );

/* -------------------------------------------------------------------------
 * The encoders themselves.
 * ---------------------------------------------------------------------- */

/**
 * The encoders, loaded without a DOM.
 *
 * @return {Object} window.wpcmb.
 */
function encoders() {
	return setup( { scripts: [ 'codes.js' ] } ).window.wpcmb;
}

test( 'Reed-Solomon matches the worked example in the QR specification', () => {
	// Version 1-M encoding the numeric string "01234567". Getting this wrong
	// produces a code that looks perfectly plausible and scans as nothing.
	const data = [ 0x10, 0x20, 0x0c, 0x56, 0x61, 0x80, 0xec, 0x11, 0xec, 0x11, 0xec, 0x11, 0xec, 0x11, 0xec, 0x11 ];
	const want = [ 0xa5, 0x24, 0xd4, 0xc1, 0xed, 0x36, 0xc7, 0x87, 0x2c, 0x55 ];

	assert.deepEqual( encoders().reedSolomon( data, 10 ), want );
} );

test( 'a QR matrix carries the three finder patterns', () => {
	const matrix = encoders().qrMatrix( 'https://example.com/hello' );
	const size = matrix.length;

	/**
	 * Whether a 7x7 finder pattern sits at an origin.
	 *
	 * @param {number} row Origin row.
	 * @param {number} col Origin column.
	 * @return {boolean} Whether it matches.
	 */
	const finder = ( row, col ) =>
		[ 0, 1, 2, 3, 4, 5, 6 ].every( ( i ) =>
			[ 0, 1, 2, 3, 4, 5, 6 ].every( ( j ) => {
				const ring = 0 === i || 6 === i || 0 === j || 6 === j;
				const core = i >= 2 && i <= 4 && j >= 2 && j <= 4;

				return matrix[ row + i ][ col + j ] === ( ring || core ? 1 : 0 );
			} )
		);

	assert.ok( finder( 0, 0 ), 'top left' );
	assert.ok( finder( 0, size - 7 ), 'top right' );
	assert.ok( finder( size - 7, 0 ), 'bottom left' );
} );

test( 'a QR matrix carries the timing patterns and the dark module', () => {
	const matrix = encoders().qrMatrix( 'https://example.com/hello' );
	const size = matrix.length;

	for ( let i = 8; i < size - 8; i++ ) {
		assert.equal( matrix[ 6 ][ i ], ( i + 1 ) % 2, 'row timing at ' + i );
		assert.equal( matrix[ i ][ 6 ], ( i + 1 ) % 2, 'column timing at ' + i );
	}

	assert.equal( matrix[ size - 8 ][ 8 ], 1, 'the dark module is set' );
} );

test( 'a QR matrix grows a version at a time and stays square', () => {
	const wpcmb = encoders();

	[ 'short', 'x'.repeat( 100 ), 'x'.repeat( 500 ) ].forEach( ( value ) => {
		const matrix = wpcmb.qrMatrix( value );

		assert.equal( matrix.length, matrix[ 0 ].length, 'square' );
		assert.equal( ( matrix.length - 17 ) % 4, 0, 'a valid QR size' );
	} );
} );

test( 'a value that will not fit returns nothing rather than a broken code', () => {
	assert.equal( encoders().qrMatrix( 'x'.repeat( 3000 ) ), null );
} );

test( 'no barcode encoder is exposed, so nothing can quietly start using one', () => {
	// A wrong Code 128 table scans cleanly as the wrong data. Until the table
	// comes from a reference, the absence has to be the tested state — this is
	// what fails if a half-remembered one is added back.
	assert.equal( encoders().code128, undefined );
} );
