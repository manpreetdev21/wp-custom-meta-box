/**
 * Field groups as blocks, in the block editor.
 *
 * Written against wp.element directly rather than JSX, so this plugin needs
 * no build step: what ships is what runs, and a bug here is debuggable in the
 * browser without a source map.
 *
 * The edit view injects the same PHP-rendered form the admin screens use, so
 * every field type — including repeaters and flexible content — works inside
 * a block without a second implementation in React.
 *
 * @package WPCMB
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var config = window.wpcmbBlocks || {};
	var i18n = config.i18n || {};
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;

	/**
	 * Read a form's field controls into a nested object.
	 *
	 * Input names are the renderer's own scheme — `wpcmb_values[rows][0][url]`
	 * — so this rebuilds exactly the shape the PHP side expects to receive,
	 * including repeater rows and nested groups.
	 *
	 * @param {Element} root Container holding the controls.
	 * @return {Object} Values keyed by field name.
	 */
	function collect( root ) {
		var values = {};
		var prefix = 'wpcmb_values';

		// Every named control, filtered here rather than in the selector.
		// An attribute selector carrying a `[` is legal CSS but not handled
		// consistently everywhere, and when it fails it matches nothing
		// silently — which would lose a block's content with no error.
		root.querySelectorAll( '[name]' ).forEach( function ( input ) {
			if ( input.disabled || 0 !== input.name.indexOf( prefix + '[' ) ) {
				return;
			}

			var checkable = 'checkbox' === input.type || 'radio' === input.type;
			var multiple = input.name.slice( -2 ) === '[]';

			if ( checkable && ! input.checked ) {
				return;
			}

			var path = ( input.name.match( /\[([^\]]*)\]/g ) || [] ).map( function ( part ) {
				return part.slice( 1, -1 );
			} );

			// A trailing empty segment means "append", as in name[].
			if ( multiple ) {
				path.pop();
			}

			var cursor = values;

			path.forEach( function ( key, index ) {
				var last = index === path.length - 1;

				if ( last ) {
					if ( multiple ) {
						cursor[ key ] = ( cursor[ key ] || [] ).concat( input.value );
					} else {
						cursor[ key ] = input.value;
					}

					return;
				}

				// A numeric segment means the parent is a list of rows.
				if ( undefined === cursor[ key ] || null === cursor[ key ] ) {
					cursor[ key ] = /^\d+$/.test( path[ index + 1 ] ) ? [] : {};
				}

				cursor = cursor[ key ];
			} );
		} );

		return values;
	}

	/**
	 * The block's edit view.
	 *
	 * @param {Object} settings Block settings from the server.
	 * @param {string} name     Block name.
	 * @return {Function} The edit component.
	 */
	function makeEdit( settings, name ) {
		return function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps();
			var mode = props.attributes.mode || settings.mode || 'auto';
			var editing = 'preview' !== mode;
			var container = useRef( null );
			var state = useState( '' );
			var html = state[ 0 ];
			var setHtml = state[ 1 ];
			var errorState = useState( '' );
			var error = errorState[ 0 ];
			var setError = errorState[ 1 ];

			// Fetch the form once per block instance. Refetching on every
			// keystroke would throw away focus and any half-typed value.
			useEffect( function () {
				if ( ! editing || html ) {
					return;
				}

				var body = new FormData();
				body.append( 'action', 'wpcmb_block_form' );
				body.append( 'nonce', config.nonce );
				body.append( 'group', settings.group );
				body.append( 'values', JSON.stringify( props.attributes.data || {} ) );

				window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( result ) {
						if ( result && result.success ) {
							setHtml( result.data.html );
							return;
						}

						setError( ( result && result.data && result.data.message ) || i18n.failed );
					} )
					.catch( function () {
						setError( i18n.failed );
					} );
			}, [ editing, html ] );

			// Initialise the injected fields once they are in the document,
			// so repeaters, conditional logic and the media picker all work.
			useEffect( function () {
				if ( ! container.current || ! html ) {
					return;
				}

				container.current.innerHTML = html;

				if ( window.wpcmb && window.wpcmb.initFields ) {
					window.wpcmb.initFields( container.current );
				}

				if ( window.wpcmb && window.wpcmb.initRepeaters ) {
					window.wpcmb.initRepeaters( container.current );
				}
			}, [ html ] );

			function sync() {
				if ( container.current ) {
					props.setAttributes( { data: collect( container.current ) } );
				}
			}

			var toolbar = el(
				wp.blockEditor.BlockControls,
				{ group: 'block' },
				el( wp.components.ToolbarButton, {
					icon: 'edit',
					label: i18n.edit,
					isPressed: editing,
					onClick: function () {
						props.setAttributes( { mode: 'edit' } );
					},
				} ),
				el( wp.components.ToolbarButton, {
					icon: 'visibility',
					label: i18n.preview,
					isPressed: ! editing,
					onClick: function () {
						sync();
						props.setAttributes( { mode: 'preview' } );
					},
				} )
			);

			var body;

			if ( ! editing ) {
				body = el( wp.serverSideRender, {
					block: name,
					attributes: props.attributes,
					// Marks the render as an editor preview, so a template can
					// show a placeholder instead of an empty region.
					urlQueryArgs: { __wpcmbPreview: 1 },
				} );
			} else if ( error ) {
				body = el( wp.components.Notice, { status: 'error', isDismissible: false }, error );
			} else if ( ! html ) {
				body = el( wp.components.Spinner, null );
			} else {
				body = el( 'div', {
					className: 'wpcmb-block-editor',
					ref: container,
					// Values are read out of the DOM on change rather than
					// controlled by React: the controls are rendered by PHP,
					// so React does not own their state.
					onChange: sync,
					onInput: sync,
				} );
			}

			var children = [ toolbar, body ];

			if ( settings.innerBlocks ) {
				children.push( el( wp.blockEditor.InnerBlocks, null ) );
			}

			return el( 'div', blockProps, children );
		};
	}

	Object.keys( config.blocks || {} ).forEach( function ( name ) {
		var settings = config.blocks[ name ];

		wp.blocks.registerBlockType( name, {
			edit: makeEdit( settings, name ),

			// Everything renders through the PHP callback, so nothing is
			// saved into post content and a template change takes effect on
			// existing posts instead of invalidating them.
			save: function () {
				return settings.innerBlocks ? el( wp.blockEditor.InnerBlocks.Content, null ) : null;
			},
		} );
	} );
}( window.wp ) );
