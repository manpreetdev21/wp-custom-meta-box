/**
 * Browser tests for assets/js/blocks.js.
 *
 * The block editor is stubbed rather than loaded: what is worth testing here
 * is the plugin's own logic — how a block registers, and how it reads the
 * injected PHP form back into block attributes — not Gutenberg itself.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { fixture, setup } = require( './harness' );

/**
 * A minimal stand-in for the parts of `wp` that blocks.js touches.
 *
 * @param {Array} [stateValues] Values for successive useState calls, in the
 *                              order the edit view asks for them: the fetched
 *                              form markup, then the error message.
 * @return {Object} The stub, with the registrations it recorded.
 */
function wpStub( stateValues = [] ) {
	const registered = {};
	const hooks = [];
	let stateCall = 0;

	/**
	 * Record a createElement call as a plain tree.
	 *
	 * @param {*}      type     Element type.
	 * @param {Object} props    Props.
	 * @param {...*}   children Children.
	 * @return {Object} The recorded node.
	 */
	const createElement = ( type, props, ...children ) => ( {
		type,
		props: props || {},
		children: children.flat().filter( Boolean ),
	} );

	return {
		registered,
		hooks,
		api: {
			blocks: {
				registerBlockType: ( name, settings ) => {
					registered[ name ] = settings;
				},
			},
			element: {
				createElement,
				useState: ( initial ) => {
					const seeded = stateValues[ stateCall ];
					stateCall += 1;

					return [ undefined === seeded ? initial : seeded, () => {} ];
				},
				useEffect: ( fn ) => {
					hooks.push( fn );
				},
				useRef: ( initial ) => ( { current: initial } ),
			},
			blockEditor: {
				useBlockProps: () => ( { className: 'wp-block' } ),
				BlockControls: 'BlockControls',
				InnerBlocks: Object.assign( 'InnerBlocks', { Content: 'InnerBlocks.Content' } ),
			},
			components: {
				ToolbarButton: 'ToolbarButton',
				Notice: 'Notice',
				Spinner: 'Spinner',
			},
			serverSideRender: 'ServerSideRender',
			i18n: { __: ( s ) => s },
		},
	};
}

const config = {
	ajaxUrl: 'http://localhost/wp-admin/admin-ajax.php',
	nonce: 'block-nonce',
	blocks: {
		'wpcmb/call-out': { group: 'group_abc0000001', mode: 'auto', innerBlocks: false },
		'wpcmb/with-inner': { group: 'group_def0000001', mode: 'preview', innerBlocks: true },
	},
	i18n: { edit: 'Edit', preview: 'Preview', loading: 'Loading…', failed: 'Failed', empty: 'Empty' },
};

/**
 * Load blocks.js against the stub.
 *
 * @param {Object} [overrides] Config overrides.
 * @return {Object} The harness, plus the stub.
 */
function blocksPage( overrides = {} ) {
	const stub = wpStub();

	const page = setup( {
		scripts: [ 'blocks.js' ],
		globals: { wp: stub.api, wpcmbBlocks: Object.assign( {}, config, overrides ) },
	} );

	page.stub = stub;

	return page;
}

test( 'every configured group is registered as a block', () => {
	const page = blocksPage();

	assert.deepEqual(
		Object.keys( page.stub.registered ).sort(),
		[ 'wpcmb/call-out', 'wpcmb/with-inner' ]
	);
} );

test( 'a block without inner blocks saves nothing into post content', () => {
	const page = blocksPage();

	// Nothing saved means the PHP render callback is the only source of
	// output, so changing a template updates posts that already exist rather
	// than invalidating them.
	assert.equal( page.stub.registered[ 'wpcmb/call-out' ].save(), null );
} );

test( 'a block with inner blocks saves only their content', () => {
	const page = blocksPage();

	const saved = page.stub.registered[ 'wpcmb/with-inner' ].save();

	assert.equal( saved.type, 'InnerBlocks.Content' );
} );

test( 'the script does nothing when the block editor is absent', () => {
	// blocks.js is only enqueued in the editor, but a plugin conflict or an
	// aggressive optimiser can load it elsewhere. It must be inert, not fatal.
	assert.doesNotThrow( () => {
		setup( { scripts: [ 'blocks.js' ], globals: { wp: undefined, wpcmbBlocks: config } } );
	} );
} );

test( 'the script does nothing when no group is configured as a block', () => {
	const page = blocksPage( { blocks: {} } );

	assert.deepEqual( Object.keys( page.stub.registered ), [] );
} );

/* -------------------------------------------------------------------------
 * Reading the injected form back into block attributes.
 *
 * This is the part that has to agree with the PHP renderer's naming scheme.
 * The markup below is real renderer output, so a change to how names are
 * built fails here rather than silently losing a block's content.
 * ---------------------------------------------------------------------- */

/**
 * Run the script's collect() against real markup.
 *
 * collect() is private to the module, so it is reached the way the editor
 * reaches it: by rendering the edit view and firing the change handler.
 *
 * @param {string} html Fixture markup.
 * @return {Object} The collected attributes.
 */
function collectFrom( html ) {
	// The first useState holds the fetched form markup. Seeding it puts the
	// edit view straight into the state it reaches once the AJAX call has
	// returned, which is the only state where collect() runs.
	const stub = wpStub( [ html, '' ] );

	const page = setup( {
		html: `<div id="host">${ html }</div>`,
		scripts: [ 'blocks.js' ],
		globals: { wp: stub.api, wpcmbBlocks: config },
	} );

	let collected = null;

	const edit = stub.registered[ 'wpcmb/call-out' ].edit;

	const tree = edit( {
		attributes: { data: {}, mode: 'edit' },
		setAttributes: ( next ) => {
			collected = next.data;
		},
	} );

	// Find the container node the edit view built, point its ref at the real
	// markup, and fire the handler the way a change in the form would.
	const findBody = ( node ) => {
		if ( ! node || 'object' !== typeof node ) {
			return null;
		}

		if ( node.props && node.props.className === 'wpcmb-block-editor' ) {
			return node;
		}

		for ( const child of node.children || [] ) {
			const found = findBody( child );

			if ( found ) {
				return found;
			}
		}

		return null;
	};

	// The edit view only renders the container once its fetch has resolved,
	// so the state hook is primed by running the effects the stub recorded.
	const body = findBody( tree );

	if ( ! body ) {
		return { page, collected: null };
	}

	body.props.ref.current = page.$( '#host' );
	body.props.onChange();

	return { page, collected };
}

test( 'flat field values are collected under their own names', () => {
	const { collected } = collectFrom(
		'<input name="wpcmb_values[heading]" value="Hello" />' +
		'<input name="wpcmb_values[link]" value="https://example.com" />'
	);

	assert.deepEqual( collected, { heading: 'Hello', link: 'https://example.com' } );
} );

test( 'repeater rows are collected as an array, not an object', () => {
	const { collected } = collectFrom(
		'<input name="wpcmb_values[rows][0][title]" value="One" />' +
		'<input name="wpcmb_values[rows][1][title]" value="Two" />'
	);

	assert.ok( Array.isArray( collected.rows ), 'a numeric segment produces an array' );
	assert.deepEqual( collected.rows, [ { title: 'One' }, { title: 'Two' } ] );
} );

test( 'nested repeaters survive the round trip', () => {
	const { collected } = collectFrom(
		'<input name="wpcmb_values[team][0][name]" value="Ada" />' +
		'<input name="wpcmb_values[team][0][links][0][url]" value="https://a.example" />' +
		'<input name="wpcmb_values[team][0][links][1][url]" value="https://b.example" />'
	);

	assert.equal( collected.team[ 0 ].name, 'Ada' );
	assert.deepEqual(
		collected.team[ 0 ].links,
		[ { url: 'https://a.example' }, { url: 'https://b.example' } ]
	);
} );

test( 'an unchecked box contributes nothing', () => {
	const { collected } = collectFrom(
		'<input type="checkbox" name="wpcmb_values[tags][]" value="a" checked />' +
		'<input type="checkbox" name="wpcmb_values[tags][]" value="b" />' +
		'<input type="checkbox" name="wpcmb_values[tags][]" value="c" checked />'
	);

	assert.deepEqual( collected.tags, [ 'a', 'c' ], 'only the checked values are kept' );
} );

test( 'a disabled control contributes nothing', () => {
	// Conditional logic disables the controls of a hidden field. Collecting
	// them would store values for fields the author cannot see.
	const { collected } = collectFrom(
		'<input name="wpcmb_values[shown]" value="yes" />' +
		'<input name="wpcmb_values[hidden]" value="should not appear" disabled />'
	);

	assert.deepEqual( collected, { shown: 'yes' } );
} );

test( 'controls outside the plugin are ignored', () => {
	const { collected } = collectFrom(
		'<input name="wpcmb_values[mine]" value="keep" />' +
		'<input name="post_title" value="not mine" />' +
		'<input name="some_other_plugin[x]" value="not mine" />'
	);

	assert.deepEqual( collected, { mine: 'keep' } );
} );
