/**
 * Browser tests for assets/js/form.js.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { fixture, setup } = require( './harness' );

const globals = {
	wpcmbFields: { i18n: {} },
	wpcmbForm: {
		ajaxUrl: 'http://localhost/wp-admin/admin-ajax.php',
		i18n: { submitting: 'Sending…', failed: 'Something went wrong.' },
	},
};

/**
 * A page with the form fixture and a fetch stub.
 *
 * @param {Object} response The JSON the endpoint should return.
 * @return {Object} The harness, plus the recorded submissions.
 */
function formPage( response ) {
	const submissions = [];

	const page = setup( {
		html: fixture( 'form' ),
		scripts: [ 'fields.js', 'form.js' ],
		globals,
		fetch: ( url, options ) => {
			submissions.push( options.body );

			return Promise.resolve( { json: () => Promise.resolve( response ) } );
		},
	} );

	page.submissions = submissions;

	page.submit = async () => {
		page.$( 'form' ).dispatchEvent(
			new page.window.Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await page.settle();
	};

	return page;
}

test( 'the form is a real form that works without the script', () => {
	// The whole progressive-enhancement claim rests on this: a real method,
	// a real action, and every value in a named control.
	const page = setup( { html: fixture( 'form' ), scripts: [], globals } );
	const form = page.$( 'form' );

	assert.equal( form.method, 'post' );
	assert.match( form.action, /admin-post\.php$/ );
	assert.equal( form.enctype, 'multipart/form-data', 'uploads can be posted' );
	assert.ok( page.$( '[name="action"]' ), 'the handler is named' );
	assert.ok( page.$( '[name="wpcmb_form_nonce"]' ), 'a nonce is present' );
	assert.ok( page.$( '[name="wpcmb_form[payload]"]' ), 'the signed configuration travels with it' );
	assert.ok( page.$( '[name="wpcmb_form[signature]"]' ) );
} );

test( 'the honeypot is present, empty and kept away from people', () => {
	const page = setup( { html: fixture( 'form' ), scripts: [], globals } );
	const honeypot = page.$( '[name="wpcmb_website_url"]' );

	assert.ok( honeypot, 'the honeypot exists' );
	assert.equal( honeypot.value, '', 'and starts empty' );
	assert.equal( honeypot.tabIndex, -1, 'keyboard users skip it' );
	assert.equal( honeypot.closest( '[aria-hidden="true"]' ) !== null, true, 'screen readers skip it' );

	// Deliberately not hidden with the attribute: form fillers skip fields
	// the browser reports as hidden, which would defeat the whole point.
	assert.equal( honeypot.hidden, false, 'it is not hidden from a form filler' );
} );

test( 'a successful submission reports and does not reload the page', async () => {
	const page = formPage( { success: true, data: { message: 'Thanks!', errors: {}, redirect: '' } } );

	await page.submit();

	assert.equal( page.submissions.length, 1, 'the submission went over fetch' );
	assert.match( page.$( '.wpcmb-form__notice' ).textContent, /Thanks!/ );
	assert.ok(
		page.$( '.wpcmb-form__notice' ).className.includes( 'success' ),
		'and is marked as a success'
	);
} );

test( 'the submit button is re-enabled after a submission', async () => {
	const page = formPage( { success: true, data: { message: 'Thanks!', errors: {}, redirect: '' } } );

	await page.submit();

	assert.equal( page.$( '.wpcmb-form__submit' ).disabled, false, 'the form can be used again' );
	assert.equal( page.$( '.wpcmb-form__status' ).textContent, '', 'the busy message is cleared' );
} );

test( 'validation errors are shown beside the fields they belong to', async () => {
	const page = formPage( {
		success: false,
		data: {
			message: 'Please check the highlighted fields.',
			errors: { sender_email: 'Enter a valid email address.' },
		},
	} );

	await page.submit();

	const field = page.$( '.wpcmb-field[data-wpcmb-name="sender_email"]' );
	const slot = field.querySelector( '.wpcmb-field__error' );

	assert.equal( slot.hidden, false, 'the error slot is shown' );
	assert.match( slot.textContent, /valid email/ );
	assert.match( page.$( '.wpcmb-form__notice' ).textContent, /highlighted/ );
	assert.ok( page.$( '.wpcmb-form__notice' ).className.includes( 'error' ) );
} );

test( 'errors from a previous attempt are cleared on the next one', async () => {
	const page = formPage( {
		success: false,
		data: { message: 'Nope', errors: { sender_email: 'Bad email' } },
	} );

	await page.submit();

	const slot = page.$( '.wpcmb-field[data-wpcmb-name="sender_email"] .wpcmb-field__error' );

	assert.equal( slot.hidden, false );

	// A stale error beside a field the user has since fixed is worse than no
	// error at all, so the slots are emptied before new ones are applied.
	page.window.fetch = () => Promise.resolve( {
		json: () => Promise.resolve( { success: true, data: { message: 'OK', errors: {}, redirect: '' } } ),
	} );

	await page.submit();

	assert.equal( slot.hidden, true, 'the old error is gone' );
	assert.equal( slot.textContent, '' );
} );

test( 'an error naming a field that is not on the form does not throw', async () => {
	const page = formPage( {
		success: false,
		data: { message: 'Nope', errors: { not_a_field: 'Missing' } },
	} );

	await assert.doesNotReject( page.submit() );
	assert.match( page.$( '.wpcmb-form__notice' ).textContent, /Nope/ );
} );

test( 'a network failure is reported rather than leaving the form stuck', async () => {
	const page = setup( {
		html: fixture( 'form' ),
		scripts: [ 'fields.js', 'form.js' ],
		globals,
		fetch: () => Promise.reject( new Error( 'offline' ) ),
	} );

	page.$( 'form' ).dispatchEvent( new page.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await page.settle();

	assert.match( page.$( '.wpcmb-form__notice' ).textContent, /went wrong/ );
	assert.equal( page.$( '.wpcmb-form__submit' ).disabled, false, 'the button is usable again' );
} );

test( 'a form with AJAX turned off is left to submit normally', async () => {
	const submissions = [];

	const page = setup( {
		html: fixture( 'form' ).replace( 'data-wpcmb-ajax="1"', 'data-wpcmb-ajax="0"' ),
		scripts: [ 'fields.js', 'form.js' ],
		globals,
		fetch: ( url, options ) => {
			submissions.push( options );
			return Promise.resolve( { json: () => Promise.resolve( {} ) } );
		},
	} );

	const event = new page.window.Event( 'submit', { bubbles: true, cancelable: true } );
	page.$( 'form' ).dispatchEvent( event );
	await page.settle();

	assert.equal( submissions.length, 0, 'the script did not intercept' );
	assert.equal( event.defaultPrevented, false, 'the browser posts the form itself' );
} );
