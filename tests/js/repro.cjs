/**
 * Ad-hoc reproduction: render a stored field group with the real localized
 * builder config. Not part of the suite; a diagnostic for a reported bug.
 *
 * Usage: node tests/js/repro.cjs <builder-config.json> <group-config.json>
 */

'use strict';

const fs = require( 'node:fs' );
const { setup, jqueryStub } = require( './harness' );

const cfg = JSON.parse( fs.readFileSync( process.argv[ 2 ], 'utf8' ) );
const stored = JSON.parse( fs.readFileSync( process.argv[ 3 ], 'utf8' ) );

const esc = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

const html = `
<div class="wpcmb-builder" data-wpcmb-builder="fields">
 <textarea class="wpcmb-builder__state" name="wpcmb_fields_json" hidden>${ esc( JSON.stringify( stored.fields ) ) }</textarea>
 <div class="wpcmb-builder__list" data-wpcmb-list></div>
 <p class="wpcmb-builder__actions"><button type="button" data-wpcmb-add-field>Add</button></p>
</div>
<div class="wpcmb-builder" data-wpcmb-builder="location">
 <textarea class="wpcmb-builder__state" name="wpcmb_location_json" hidden>${ esc( JSON.stringify( stored.location || [] ) ) }</textarea>
 <div class="wpcmb-builder__list" data-wpcmb-list></div>
 <p class="wpcmb-builder__actions"><button type="button" data-wpcmb-add-group>Add group</button></p>
</div>`;

const errs = [];

let page;

try {
	page = setup( { html, scripts: [ 'builder.js' ], globals: { wpcmbBuilder: cfg, jQuery: jqueryStub() } } );
} catch ( e ) {
	console.log( 'SETUP THREW:', e.message );
	console.log( e.stack.split( '\n' ).slice( 0, 8 ).join( '\n' ) );
	process.exit( 1 );
}

page.window.addEventListener( 'error', ( e ) => errs.push( e.message ) );

const rows = page.$$( '.wpcmb-builder[data-wpcmb-builder="fields"] > .wpcmb-builder__list > .wpcmb-field-row' );

console.log( 'top-level rows :', rows.length );
console.log( 'sub rows       :', page.$$( '.wpcmb-subsection .wpcmb-field-row' ).length );
console.log( 'selects        :', page.$$( '.wpcmb-field-row select' ).length );
console.log( 'location rules :', page.$$( '.wpcmb-rule' ).length );
console.log( 'errors         :', errs.length ? errs : 'none' );
console.log( 'state head     :', page.$( '[name=wpcmb_fields_json]' ).value.slice( 0, 90 ) + '…' );
