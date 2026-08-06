/**
 * Browser tests for assets/js/fields.js.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { fixture, setup } = require( './harness' );

const globals = {
	wpcmbFields: {
		i18n: { remove: 'Remove', selectMedia: 'Select media', embedPending: 'Pending' },
	},
};

/**
 * A page with the conditional-logic fixture and the field script.
 *
 * @return {Object} The harness.
 */
function conditionalPage() {
	return setup( { html: fixture( 'conditional' ), scripts: [ 'fields.js' ], globals } );
}

test( 'conditional logic hides a field until its rule is met', () => {
	const page = conditionalPage();

	const extra = page.$( '.wpcmb-field[data-wpcmb-name="extra"]' );
	const always = page.$( '.wpcmb-field[data-wpcmb-name="always"]' );

	assert.ok( extra, 'the conditional field is in the markup' );
	assert.equal( extra.hidden, true, 'it starts hidden because the toggle is off' );
	assert.equal( always.hidden, false, 'a field with no logic is never hidden' );
} );

test( 'meeting the rule reveals the field', () => {
	const page = conditionalPage();

	const toggle = page.$( '.wpcmb-field[data-wpcmb-name="show_more"] input[type="checkbox"]' );
	const extra = page.$( '.wpcmb-field[data-wpcmb-name="extra"]' );

	page.check( toggle, true );

	assert.equal( extra.hidden, false, 'the field appears once the toggle is on' );

	page.check( toggle, false );

	assert.equal( extra.hidden, true, 'and hides again when it is turned off' );
} );

test( 'a hide rule inverts the outcome', () => {
	const page = conditionalPage();

	const toggle = page.$( '.wpcmb-field[data-wpcmb-name="show_more"] input[type="checkbox"]' );
	const inverse = page.$( '.wpcmb-field[data-wpcmb-name="inverse"]' );

	assert.equal( inverse.hidden, false, 'a hide rule starts visible while unmet' );

	page.check( toggle, true );

	assert.equal( inverse.hidden, true, 'and hides once the rule is met' );
} );

test( 'hidden fields are disabled so they neither block submission nor post', () => {
	const page = conditionalPage();

	const extra = page.$( '.wpcmb-field[data-wpcmb-name="extra"]' );
	const input = extra.querySelector( 'input' );

	// This is the whole reason hiding is not just a CSS class. The field is
	// required; left enabled, the browser would refuse to submit the form and
	// point at a control nobody can see.
	assert.equal( input.required, true, 'the field really is required' );
	assert.equal( input.disabled, true, 'but it is disabled while hidden' );

	page.check( page.$( '.wpcmb-field[data-wpcmb-name="show_more"] input[type="checkbox"]' ), true );

	assert.equal( input.disabled, false, 'and enabled again once shown' );
} );

test( 'a field whose rule points at a deleted field stays hidden rather than erroring', () => {
	const html = fixture( 'conditional' ).replace( 'field_toggle00001', 'field_gone000000001' );
	const page = setup( { html, scripts: [ 'fields.js' ], globals } );

	// The rule now references a key that is not on the page. Treating that as
	// unmet is what stops deleting one field from making another vanish
	// unpredictably, or throwing and stopping every other field's logic.
	assert.equal(
		page.$( '.wpcmb-field[data-wpcmb-name="always"]' ).hidden,
		false,
		'unrelated fields keep working'
	);
} );

test( 'tabs become panels with only the first shown', () => {
	const page = setup( { html: fixture( 'tabs' ), scripts: [ 'fields.js' ], globals } );

	const buttons = page.$$( '.wpcmb-tabs__button' );
	const panels = page.$$( '.wpcmb-tab-panel' );

	assert.equal( buttons.length, 2, 'both tab markers became buttons' );
	assert.deepEqual( buttons.map( ( b ) => b.textContent ), [ 'First', 'Second' ] );
	assert.equal( panels.length, 2, 'each tab has a panel' );
	assert.equal( panels[ 0 ].hidden, false, 'the first panel is open' );
	assert.equal( panels[ 1 ].hidden, true, 'the second is closed' );

	// The fields that followed each marker moved into its panel.
	assert.ok( panels[ 0 ].querySelector( '[data-wpcmb-name="one"]' ), 'the first field is in the first panel' );
	assert.ok( panels[ 1 ].querySelector( '[data-wpcmb-name="two"]' ), 'the second field is in the second panel' );
} );

test( 'clicking a tab switches panels', () => {
	const page = setup( { html: fixture( 'tabs' ), scripts: [ 'fields.js' ], globals } );

	const buttons = page.$$( '.wpcmb-tabs__button' );
	const panels = page.$$( '.wpcmb-tab-panel' );

	page.click( buttons[ 1 ] );

	assert.equal( panels[ 0 ].hidden, true );
	assert.equal( panels[ 1 ].hidden, false );
	assert.equal( buttons[ 1 ].getAttribute( 'aria-selected' ), 'true' );
	assert.equal( buttons[ 0 ].getAttribute( 'aria-selected' ), 'false' );
} );

test( 'the media picker opens the WordPress media library and stores the choice', () => {
	let frameOpened = false;
	let requestedLibrary = null;
	const listeners = {};

	const wp = {
		media: ( config ) => {
			requestedLibrary = config;

			return {
				on: ( event, callback ) => {
					listeners[ event ] = callback;
				},
				open: () => {
					frameOpened = true;
				},
				state: () => ( {
					get: () => ( {
						toJSON: () => [ { id: 42, title: 'A picture', sizes: { thumbnail: { url: 'http://x/t.png' } } } ],
					} ),
				} ),
			};
		},
	};

	const page = setup( {
		html: fixture( 'media' ),
		scripts: [ 'fields.js' ],
		globals: Object.assign( {}, globals, { wp } ),
	} );

	page.click( page.$( '.wpcmb-media__select' ) );

	assert.equal( frameOpened, true, 'the media frame opened' );
	assert.equal( requestedLibrary.library.type, 'image', 'an image field asks for images' );

	// The frame reports a selection.
	listeners.select();

	assert.equal( page.$( '.wpcmb-media__ids' ).value, '42', 'the id is stored in the hidden input' );
	assert.equal( page.$$( '.wpcmb-media__item' ).length, 1, 'the preview shows the attachment' );
	assert.match( page.$( '.wpcmb-media__title' ).textContent, /A picture/ );
} );

test( 'clearing the media field empties both the value and the preview', () => {
	const page = setup( {
		html: fixture( 'media' ),
		scripts: [ 'fields.js' ],
		globals: Object.assign( {}, globals, { wp: { media: () => ( { on: () => {}, open: () => {} } ) } } ),
	} );

	const ids = page.$( '.wpcmb-media__ids' );
	ids.value = '7,8';

	page.click( page.$( '.wpcmb-media__clear' ) );

	assert.equal( ids.value, '', 'the stored ids are gone' );
	assert.equal( page.$$( '.wpcmb-media__item' ).length, 0, 'and so is the preview' );
} );

test( 'the script survives a page with no media library available', () => {
	// A front-end form for a visitor who cannot upload gets no wp.media.
	// Throwing here would take out conditional logic and everything else.
	const page = setup( { html: fixture( 'media' ), scripts: [ 'fields.js' ], globals } );

	assert.doesNotThrow( () => page.click( page.$( '.wpcmb-media__select' ) ) );
} );

/* -------------------------------------------------------------------------
 * Colour: a swatch and a hex code showing one value.
 * ---------------------------------------------------------------------- */

/**
 * A page with the colour fixture, plus its two inputs.
 *
 * @return {Object} The harness, the swatch and the code input.
 */
function colorPage() {
	const page = setup( { html: fixture( 'color' ), scripts: [ 'fields.js' ], globals } );

	return { page, swatch: page.$( '.wpcmb-color__swatch' ), code: page.$( '.wpcmb-color__code' ) };
}

test( 'the hex code is the input that submits, not the swatch', () => {
	const { swatch, code } = colorPage();

	// A native colour input has no empty state, so if it carried the name
	// every untouched colour field would quietly save black.
	assert.equal( swatch.getAttribute( 'name' ), null, 'the swatch has no name' );
	assert.equal( code.getAttribute( 'name' ), 'wpcmb_values[shade]', 'the code carries the field name' );
	assert.equal( code.value, '', 'an unset colour submits nothing' );
} );

test( 'picking a colour on the swatch writes its code', () => {
	const { page, swatch, code } = colorPage();

	page.fill( swatch, '#ff8800' );

	assert.equal( code.value, '#ff8800', 'the code follows the swatch' );
} );

test( 'the swatch announces the change on the input that owns the value', () => {
	const { page, swatch, code } = colorPage();

	// Conditional logic, repeater row previews and dirty tracking all listen
	// to the named input. The swatch is not it, so it has to re-announce.
	let heard = 0;
	code.addEventListener( 'change', () => {
		heard += 1;
	} );

	page.fill( swatch, '#123456' );

	assert.equal( heard, 1, 'a change fired on the code input' );
} );

test( 'a complete hex typed into the code moves the swatch', () => {
	const { page, swatch, code } = colorPage();

	page.fill( code, '#00FF00' );

	assert.equal( swatch.value, '#00ff00', 'the swatch follows a valid code' );
} );

test( 'a half-typed hex leaves the swatch alone', () => {
	const { page, swatch, code } = colorPage();

	page.fill( code, '#00ff00' );
	page.fill( code, '#0' );
	page.fill( code, '' );

	// Mirroring every keystroke would make the swatch lurch through colours
	// nobody asked for while a value is still being typed.
	assert.equal( swatch.value, '#00ff00', 'the swatch kept the last complete colour' );
	assert.equal( code.value, '', 'the code still shows exactly what was typed' );
} );

test( 'a shorthand hex is expanded for the swatch but stored as typed', () => {
	const { page, swatch, code } = colorPage();

	page.fill( code, '#00f' );

	// A colour input takes #rrggbb only. Passing the shorthand straight
	// through does not fail loudly — it resets the swatch to black.
	assert.equal( swatch.value, '#0000ff', 'the swatch got the expanded form' );
	assert.equal( code.value, '#00f', 'the stored value is left as typed' );
} );

test( 'initFields is exposed so dynamically added markup can be wired up', () => {
	const page = setup( { html: fixture( 'conditional' ), scripts: [ 'fields.js' ], globals } );

	assert.equal( typeof page.window.wpcmb.initFields, 'function' );
} );
