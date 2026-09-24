/**
 * Browser tests for assets/js/validate.js.
 *
 * The gate decides one thing: may this screen be saved right now. It asks the
 * server and acts on the answer, so what is worth testing is the acting —
 * when the block editor's save is locked, when it is not, and that a classic
 * submit is held back rather than let through.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { fixture, setup } = require( './harness' );

/**
 * A stub of the bits of `wp.data` the gate uses.
 *
 * Small on purpose: a fuller fake would be a second editor to maintain, and
 * the gate only ever reads one attribute and dispatches two actions.
 *
 * @param {string} status Post status the editor reports.
 * @return {Object} The stub, plus a record of what the gate did to it.
 */
function editorStub( status ) {
	const calls = { locks: [], unlocks: [], notices: [], removed: [] };
	const state = { status };
	const subscribers = [];

	const wp = {
		data: {
			select: ( store ) => {
				if ( 'core/editor' === store ) {
					return {
						getCurrentPostId: () => 12,
						getEditedPostAttribute: ( name ) => ( 'status' === name ? state.status : null ),
					};
				}

				return { getNotices: () => [] };
			},
			dispatch: ( store ) => {
				if ( 'core/editor' === store ) {
					return {
						lockPostSaving: ( key ) => calls.locks.push( key ),
						unlockPostSaving: ( key ) => calls.unlocks.push( key ),
					};
				}

				return {
					createNotice: ( type, content ) => calls.notices.push( { type, content } ),
					removeNotice: ( id ) => calls.removed.push( id ),
				};
			},
			subscribe: ( listener ) => subscribers.push( listener ),
		},
	};

	return {
		wp,
		calls,

		/**
		 * Change the status the way clicking Publish does, then let the gate see it.
		 *
		 * @param {string} next New status.
		 */
		setStatus: ( next ) => {
			state.status = next;
			subscribers.forEach( ( listener ) => listener() );
		},
	};
}

/**
 * A page with the gate loaded and the server's answer stubbed.
 *
 * @param {Object}  options           Options.
 * @param {Object}  [options.errors]  Errors the server reports.
 * @param {string}  [options.status]  Post status, when a block editor is wanted.
 * @param {boolean} [options.blocks]  Whether to pretend the block editor is running.
 * @param {boolean} [options.named]   Whether the form holds a control named "submit".
 * @return {Object} The harness, with the editor stub attached.
 */
function gatePage( options = {} ) {
	const stub = editorStub( options.status || 'draft' );
	const answer = {
		success: true,
		data: { valid: ! options.errors, errors: options.errors || {}, keys: {} },
	};

	const page = setup( {
		html: `<form id="post">${ fixture( 'conditional' ) }<input type="submit" id="publish"${ options.named ? ' name="submit"' : '' } /></form>`,
		scripts: [ 'fields.js' ],
		globals: {
			wpcmbValidate: {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				nonce: 'test-nonce',
				object: 'post_12',
				i18n: { blocked: 'This cannot be saved yet:', showField: 'Show me' },
			},
			wpcmbFields: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'test-nonce', i18n: {} },
		},
		fetch: () => Promise.resolve( {
			ok: true,
			json: () => Promise.resolve( answer ),
		} ),
	} );

	if ( options.blocks ) {
		page.window.document.body.classList.add( 'block-editor-page' );
		page.window.wp = Object.assign( page.window.wp || {}, stub.wp );
	}

	// Loaded after the globals are in place, the way WordPress enqueues it.
	page.load( 'validate.js' );

	return Object.assign( page, { stub } );
}

test( 'the block editor is not locked while the post is a draft', async () => {
	const page = gatePage( { blocks: true, status: 'draft', errors: { extra: 'Extra is required.' } } );

	// The gate binds on DOMContentLoaded, which jsdom fires after setup.
	await page.settle();

	assert.deepEqual( page.stub.calls.locks, [], 'a draft can still be saved' );
	assert.equal(
		page.$( '.wpcmb-field[data-wpcmb-name="extra"] .wpcmb-field__error' ).textContent,
		'Extra is required.',
		'but the problem is still shown'
	);
} );

test( 'trying to publish locks the save while a field is invalid', async () => {
	const page = gatePage( { blocks: true, status: 'draft', errors: { extra: 'Extra is required.' } } );

	// The gate binds on DOMContentLoaded, which jsdom fires after setup.
	await page.settle();

	// What the Publish button does: set the status, then save.
	page.stub.setStatus( 'publish' );

	assert.deepEqual( page.stub.calls.locks, [ 'wpcmb-required' ], 'the save is locked' );
	assert.equal( page.stub.calls.notices.length, 1, 'and the reason is announced' );
	assert.match( page.stub.calls.notices[ 0 ].content, /Extra is required\./ );
} );

test( 'a valid screen is never locked, whatever the status', async () => {
	const page = gatePage( { blocks: true, status: 'publish' } );

	await page.settle();

	assert.deepEqual( page.stub.calls.locks, [], 'nothing to block' );
} );

test( 'the lock is lifted once the field is filled in', async () => {
	const page = gatePage( { blocks: true, status: 'publish', errors: { extra: 'Extra is required.' } } );

	await page.settle();

	assert.deepEqual( page.stub.calls.locks, [ 'wpcmb-required' ], 'locked to begin with' );

	// The server's answer changes once the field has a value.
	page.window.fetch = () => Promise.resolve( {
		ok: true,
		json: () => Promise.resolve( { success: true, data: { valid: true, errors: {}, keys: {} } } ),
	} );

	// An edit is what makes the gate ask again, and it waits out a burst of
	// typing first, so the test waits with it.
	page.$( '.wpcmb-field[data-wpcmb-name="extra"] input' ).dispatchEvent(
		new page.window.Event( 'input', { bubbles: true } )
	);

	await new Promise( ( resolve ) => setTimeout( resolve, 700 ) );

	assert.ok( page.stub.calls.unlocks.length > 0, 'and unlocked again' );
	assert.equal(
		page.$( '.wpcmb-field[data-wpcmb-name="extra"] .wpcmb-field__error' ).hidden,
		true,
		'the message goes with it'
	);
} );

/**
 * Watch for the real submit, wherever the gate reaches for it.
 *
 * Spied on the prototype rather than the element, because a form exposes its
 * own controls as properties and `form.submit` is a button on half the forms
 * in wp-admin.
 *
 * @param {Object} page Harness.
 * @return {Function} Returns how many real submits happened.
 */
function watchSubmits( page ) {
	const proto = page.window.HTMLFormElement.prototype;
	const original = proto.submit;
	let count = 0;

	proto.submit = function () {
		count += 1;
	};

	return () => {
		proto.submit = original;

		return count;
	};
}

test( 'a classic submit is held back until the server has answered', async () => {
	const page = gatePage( { errors: { extra: 'Extra is required.' } } );

	// The gate binds on DOMContentLoaded, which jsdom fires after setup.
	await page.settle();

	const submits = watchSubmits( page );
	const form = page.$( '#post' );
	const event = new page.window.Event( 'submit', { bubbles: true, cancelable: true } );

	form.dispatchEvent( event );

	assert.equal( event.defaultPrevented, true, 'the submit is always cancelled first' );

	await page.settle();

	assert.equal( submits(), 0, 'and not replayed while a field is invalid' );
	assert.equal(
		page.$( '.wpcmb-field[data-wpcmb-name="extra"] .wpcmb-field__error' ).hidden,
		false,
		'the message is shown instead'
	);
} );

test( 'a classic submit goes through once everything is valid', async () => {
	const page = gatePage( {} );

	await page.settle();

	const submits = watchSubmits( page );

	page.$( '#post' ).dispatchEvent( new page.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

	await page.settle();

	assert.equal( submits(), 1, 'the save is replayed for real' );
} );

test( 'a form with a control named "submit" is still replayed', async () => {
	const page = gatePage( { named: true } );

	await page.settle();

	const submits = watchSubmits( page );
	const form = page.$( '#post' );

	/*
	 * A browser exposes a form's controls as properties of the form, so a
	 * control named "submit" replaces the method of the same name and calling
	 * it throws. jsdom does not implement that, so the shadowing is put in
	 * place by hand — without it this test passes even with the bug present,
	 * which is exactly how the bug reached a real screen in the first place.
	 */
	Object.defineProperty( form, 'submit', {
		value: page.$( '#publish' ),
		configurable: true,
	} );

	form.dispatchEvent( new page.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

	await page.settle();

	delete form.submit;

	assert.equal( submits(), 1, 'the named control does not get in the way' );
} );
