/**
 * The advanced field controls: icon picker, signature pad, map, embed
 * preview, QR and barcode.
 *
 * Each of these upgrades markup the server already rendered. The value always
 * lives in an input the server wrote, never in a variable here, so a control
 * that fails to initialise leaves the stored value intact through a save
 * rather than blanking it.
 *
 * @package WPCMB
 */

( function () {
	'use strict';

	var config = window.wpcmbFields || {};
	var i18n = config.i18n || {};

	/**
	 * The hidden input carrying a control's value.
	 *
	 * @param {Element} wrapper Enhancement wrapper.
	 * @return {HTMLElement|null} The input.
	 */
	function valueInput( wrapper ) {
		return wrapper.querySelector( '.wpcmb-enhanced__value' );
	}

	/**
	 * Write a value and tell everything else that watches it.
	 *
	 * Conditional logic, the repeater row preview and dirty tracking all
	 * listen on the named input, and a script assignment fires no events.
	 *
	 * @param {HTMLElement} input Target input.
	 * @param {string}      value New value.
	 */
	function setValue( input, value ) {
		if ( ! input || input.value === value ) {
			return;
		}

		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/**
	 * Build an element with attributes and children.
	 *
	 * @param {string} tag     Tag name.
	 * @param {Object} [attrs] Attributes; `text` and `class` are special-cased.
	 * @param {Array}  [kids]  Child nodes.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, attrs, kids ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( name ) {
			if ( 'text' === name ) {
				node.textContent = attrs[ name ];
			} else {
				node.setAttribute( name, attrs[ name ] );
			}
		} );

		( kids || [] ).forEach( function ( kid ) {
			node.appendChild( kid );
		} );

		return node;
	}

	/* --------------------------------------------------------------------
	 * Icon picker: Dashicons, Media Library, URL.
	 * ----------------------------------------------------------------- */

	/**
	 * Wire every icon field inside a container.
	 *
	 * One value covers all three sources, told apart by shape: a Dashicon is
	 * `dashicons-something`, a library item is an attachment id, anything else
	 * is a URL. That keeps one stored string instead of a value plus a "which
	 * kind is it" flag that could disagree with it.
	 *
	 * @param {Element} root Container.
	 */
	function initIcons( root ) {
		root.querySelectorAll( '[data-wpcmb-enhanced="icon"]' ).forEach( function ( wrapper ) {
			var input = valueInput( wrapper );
			var picker = wrapper.querySelector( '.wpcmb-icon__picker' );
			var choose = wrapper.querySelector( '.wpcmb-icon__choose' );
			var clear = wrapper.querySelector( '.wpcmb-icon__clear' );
			var current = wrapper.querySelector( '.wpcmb-icon__current' );

			if ( ! input || ! picker || ! choose ) {
				return;
			}

			/**
			 * Redraw the preview beside the button.
			 *
			 * @param {string} value Stored value.
			 * @param {string} [url] Known image URL, when one was just picked.
			 */
			function preview( value, url ) {
				current.textContent = '';
				clear.hidden = '' === value;

				if ( '' === value ) {
					return;
				}

				if ( 0 === value.indexOf( 'dashicons-' ) ) {
					current.appendChild( el( 'span', { class: 'dashicons ' + value + ' wpcmb-icon__glyph', 'aria-hidden': 'true' } ) );

					return;
				}

				current.appendChild( el( 'img', { src: url || value, alt: '', width: '32', height: '32' } ) );
			}

			/**
			 * Choose a value and close the picker.
			 *
			 * @param {string} value Chosen value.
			 * @param {string} [url] Known image URL.
			 */
			function choosen( value, url ) {
				setValue( input, value );
				preview( value, url );
				toggle( false );
			}

			/**
			 * Open or close the picker.
			 *
			 * @param {boolean} open Whether to open.
			 */
			function toggle( open ) {
				picker.hidden = ! open;
				choose.setAttribute( 'aria-expanded', String( open ) );

				if ( open && ! picker.childElementCount ) {
					build();
				}
			}

			/**
			 * Build the three panels, once, on first open.
			 *
			 * Deferred because a Dashicons grid is several hundred buttons and
			 * a page can hold many icon fields — building them all up front
			 * costs a visible pause for panels most editors never open.
			 */
			function build() {
				var panels = [];
				var bar = el( 'div', { class: 'wpcmb-icon__tabs', role: 'tablist' } );

				/**
				 * Add one tab and its panel.
				 *
				 * @param {string}      label Tab label.
				 * @param {HTMLElement} body  Panel contents.
				 */
				function addTab( label, body ) {
					var index = panels.length;
					var button = el( 'button', {
						type: 'button',
						class: 'wpcmb-icon__tab',
						role: 'tab',
						text: label,
					} );

					button.addEventListener( 'click', function () {
						panels.forEach( function ( entry, i ) {
							entry.body.hidden = i !== index;
							entry.button.setAttribute( 'aria-selected', String( i === index ) );
							entry.button.classList.toggle( 'is-active', i === index );
						} );
					} );

					bar.appendChild( button );
					picker.appendChild( body );
					panels.push( { button: button, body: body } );
				}

				var grid = el( 'div', { class: 'wpcmb-icon__grid', role: 'group' } );

				( config.dashicons || [] ).forEach( function ( name ) {
					var button = el( 'button', {
						type: 'button',
						class: 'wpcmb-icon__option',
						title: name.replace( 'dashicons-', '' ),
					}, [ el( 'span', { class: 'dashicons ' + name, 'aria-hidden': 'true' } ) ] );

					// The visible glyph is decorative, so the name has to be
					// the button's accessible name or every option reads the
					// same to a screen reader.
					button.setAttribute( 'aria-label', name.replace( 'dashicons-', '' ) );
					button.addEventListener( 'click', function () {
						choosen( name );
					} );

					grid.appendChild( button );
				} );

				addTab( i18n.iconDashicons || 'Dashicons', grid );

				var mediaButton = el( 'button', {
					type: 'button',
					class: 'button wpcmb-icon__media',
					text: i18n.selectMedia || 'Select media',
				} );

				mediaButton.addEventListener( 'click', function () {
					if ( ! window.wp || ! window.wp.media ) {
						return;
					}

					var frame = window.wp.media( { multiple: false, library: { type: 'image' } } );

					frame.on( 'select', function () {
						var item = frame.state().get( 'selection' ).first().toJSON();

						choosen( String( item.id ), item.url );
					} );

					frame.open();
				} );

				addTab( i18n.iconMedia || 'Media Library', el( 'div', { class: 'wpcmb-icon__panel' }, [ mediaButton ] ) );

				var url = el( 'input', {
					type: 'url',
					class: 'wpcmb-input',
					placeholder: 'https://',
				} );

				var useUrl = el( 'button', {
					type: 'button',
					class: 'button',
					text: i18n.iconUseUrl || 'Use this URL',
				} );

				useUrl.addEventListener( 'click', function () {
					if ( url.value.trim() ) {
						choosen( url.value.trim() );
					}
				} );

				addTab( i18n.iconUrl || 'URL', el( 'div', { class: 'wpcmb-icon__panel' }, [ url, useUrl ] ) );

				picker.insertBefore( bar, picker.firstChild );
				panels[ 0 ].button.click();
			}

			choose.addEventListener( 'click', function () {
				toggle( picker.hidden );
			} );

			clear.addEventListener( 'click', function () {
				choosen( '' );
			} );

			// Escape closes the picker, which is the one thing a keyboard user
			// cannot otherwise do without tabbing back out of the whole grid.
			wrapper.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && ! picker.hidden ) {
					toggle( false );
					choose.focus();
				}
			} );
		} );
	}

	/* --------------------------------------------------------------------
	 * Signature pad.
	 * ----------------------------------------------------------------- */

	/**
	 * Wire every signature field inside a container.
	 *
	 * Pointer events rather than separate mouse and touch handlers: one set of
	 * listeners covers a mouse, a finger and a stylus, and the browser sorts
	 * out which is which.
	 *
	 * @param {Element} root Container.
	 */
	function initSignature( root ) {
		root.querySelectorAll( '[data-wpcmb-enhanced="signature"]' ).forEach( function ( wrapper ) {
			var input = valueInput( wrapper );
			var canvas = wrapper.querySelector( '.wpcmb-signature__pad' );
			var clear = wrapper.querySelector( '.wpcmb-signature__clear' );

			if ( ! input || ! canvas || 'function' !== typeof canvas.getContext ) {
				return;
			}

			// getContext returns null where canvas is unavailable rather than
			// throwing, and reading a property off that null took out every
			// control initialised after this one.
			var context = canvas.getContext( '2d' );

			if ( ! context ) {
				return;
			}

			var drawing = false;
			var dirty = false;

			context.lineWidth = 2;
			context.lineCap = 'round';
			context.lineJoin = 'round';
			context.strokeStyle = '#1d2327';

			if ( input.value ) {
				var existing = new Image();

				existing.onload = function () {
					context.drawImage( existing, 0, 0, canvas.width, canvas.height );
				};

				existing.src = input.value;
			}

			/**
			 * Canvas coordinates for a pointer event.
			 *
			 * The canvas is drawn at its attribute size but laid out by CSS at
			 * whatever width fits, so the two have to be scaled between or the
			 * ink lands away from the pointer.
			 *
			 * @param {PointerEvent} event The event.
			 * @return {Object} x and y in canvas space.
			 */
			function point( event ) {
				var box = canvas.getBoundingClientRect();

				return {
					x: ( event.clientX - box.left ) * ( canvas.width / box.width ),
					y: ( event.clientY - box.top ) * ( canvas.height / box.height ),
				};
			}

			canvas.addEventListener( 'pointerdown', function ( event ) {
				var at = point( event );

				drawing = true;
				canvas.setPointerCapture( event.pointerId );
				context.beginPath();
				context.moveTo( at.x, at.y );
				event.preventDefault();
			} );

			canvas.addEventListener( 'pointermove', function ( event ) {
				if ( ! drawing ) {
					return;
				}

				var at = point( event );

				context.lineTo( at.x, at.y );
				context.stroke();
				dirty = true;
			} );

			/**
			 * Finish a stroke and store the result.
			 */
			function finish() {
				if ( ! drawing ) {
					return;
				}

				drawing = false;

				if ( dirty ) {
					setValue( input, canvas.toDataURL( 'image/png' ) );
				}
			}

			canvas.addEventListener( 'pointerup', finish );
			canvas.addEventListener( 'pointercancel', finish );

			clear.addEventListener( 'click', function () {
				context.clearRect( 0, 0, canvas.width, canvas.height );
				dirty = false;
				setValue( input, '' );
			} );
		} );
	}

	/* --------------------------------------------------------------------
	 * Map: a coordinate pair, not a slippy map.
	 * ----------------------------------------------------------------- */

	/**
	 * Wire every map field inside a container.
	 *
	 * ponytail: coordinates and a link out, no tiles. Drawing an actual map
	 * means loading imagery from a third-party host on every edit screen,
	 * which this plugin does not do — every asset it loads is its own. A site
	 * that wants tiles can add them on the `wpcmb:enhance` event, where the
	 * coordinates are already sitting in the inputs below.
	 *
	 * @param {Element} root Container.
	 */
	function initMap( root ) {
		root.querySelectorAll( '[data-wpcmb-enhanced="map"]' ).forEach( function ( wrapper ) {
			var input = valueInput( wrapper );
			var lat = wrapper.querySelector( '.wpcmb-map__lat' );
			var lng = wrapper.querySelector( '.wpcmb-map__lng' );
			var address = wrapper.querySelector( '.wpcmb-map__address' );
			var locate = wrapper.querySelector( '.wpcmb-map__locate' );
			var open = wrapper.querySelector( '.wpcmb-map__open' );

			if ( ! input || ! lat || ! lng ) {
				return;
			}

			/**
			 * Fold the three inputs back into the one stored string.
			 */
			function sync() {
				var pair = lat.value.trim() + ',' + lng.value.trim();
				var complete = '' !== lat.value.trim() && '' !== lng.value.trim();
				var place = address.value.trim();

				setValue( input, complete ? pair + ( place ? ',' + place : '' ) : '' );

				open.hidden = ! complete;

				if ( complete ) {
					open.href = 'https://www.openstreetmap.org/?mlat=' +
						encodeURIComponent( lat.value.trim() ) + '&mlon=' +
						encodeURIComponent( lng.value.trim() ) + '#map=15/' +
						encodeURIComponent( lat.value.trim() ) + '/' +
						encodeURIComponent( lng.value.trim() );
				}
			}

			[ lat, lng, address ].forEach( function ( control ) {
				control.addEventListener( 'input', sync );
			} );

			// Only offered when the browser has the API and the page is in a
			// secure context, since it silently fails otherwise.
			if ( navigator.geolocation && window.isSecureContext ) {
				locate.hidden = false;

				locate.addEventListener( 'click', function () {
					locate.disabled = true;

					navigator.geolocation.getCurrentPosition(
						function ( position ) {
							lat.value = position.coords.latitude.toFixed( 6 );
							lng.value = position.coords.longitude.toFixed( 6 );
							locate.disabled = false;
							sync();
						},
						function () {
							locate.disabled = false;
						}
					);
				} );
			}

			sync();
		} );
	}

	/* --------------------------------------------------------------------
	 * Embed preview.
	 * ----------------------------------------------------------------- */

	/**
	 * Wire every embed field inside a container.
	 *
	 * The lookup runs on the server: oEmbed provider matching, the allow-list
	 * and the response cache are all already there, and asking a provider
	 * directly from the browser would neither match nor cache.
	 *
	 * @param {Element} root Container.
	 */
	function initEmbed( root ) {
		root.querySelectorAll( '[data-wpcmb-enhanced="embed"]' ).forEach( function ( wrapper ) {
			var input = wrapper.querySelector( 'input' );
			var preview = wrapper.querySelector( '.wpcmb-enhanced__preview' );
			var timer = null;
			var last = input ? input.value : '';

			if ( ! input || ! preview || ! config.ajaxUrl ) {
				return;
			}

			/**
			 * Fetch and show the preview for the current URL.
			 */
			function load() {
				var url = input.value.trim();

				if ( url === last ) {
					return;
				}

				last = url;

				if ( '' === url ) {
					preview.textContent = '';

					return;
				}

				preview.textContent = i18n.embedLoading || '';

				var body = new FormData();

				body.append( 'action', 'wpcmb_embed_preview' );
				body.append( 'nonce', config.nonce );
				body.append( 'url', url );

				window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( result ) {
						// Stale response: the field moved on while this was in
						// flight, so showing it would contradict the input.
						if ( url !== input.value.trim() ) {
							return;
						}

						if ( result && result.success && result.data.html ) {
							preview.innerHTML = result.data.html;
						} else {
							preview.textContent = i18n.embedNone || '';
						}
					} )
					.catch( function () {
						preview.textContent = i18n.embedNone || '';
					} );
			}

			input.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( load, 600 );
			} );

			input.addEventListener( 'change', load );
		} );
	}

	/* --------------------------------------------------------------------
	 * QR and barcode previews.
	 * ----------------------------------------------------------------- */

	/**
	 * Draw a QR matrix as an SVG.
	 *
	 * One path of rectangles rather than a rect element per module: a version
	 * 20 code is 3,721 modules, and that many elements is slow to lay out and
	 * pointless when none of them is interactive.
	 *
	 * @param {number[][]} matrix Module matrix.
	 * @return {SVGElement} The drawing.
	 */
	function qrSvg( matrix ) {
		var size = matrix.length;
		var quiet = 4;
		var span = size + ( quiet * 2 );
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		var d = '';

		matrix.forEach( function ( row, y ) {
			row.forEach( function ( on, x ) {
				if ( on ) {
					d += 'M' + ( x + quiet ) + ' ' + ( y + quiet ) + 'h1v1h-1z';
				}
			} );
		} );

		svg.setAttribute( 'viewBox', '0 0 ' + span + ' ' + span );
		svg.setAttribute( 'width', '160' );
		svg.setAttribute( 'height', '160' );
		svg.setAttribute( 'role', 'img' );
		path.setAttribute( 'd', d );
		path.setAttribute( 'fill', 'currentColor' );
		svg.appendChild( path );

		return svg;
	}

	/**
	 * Wire every QR field inside a container.
	 *
	 * Barcode is not handled here. See the note at the top of codes.js: the
	 * Code 128 table has no structure to verify an implementation against, so
	 * that field stays on the `wpcmb/field/enhanced_config` hook rather than
	 * shipping an encoder whose output could scan as the wrong data.
	 *
	 * @param {Element} root Container.
	 */
	function initCodes( root ) {
		root.querySelectorAll( '[data-wpcmb-enhanced="qr"]' ).forEach( function ( wrapper ) {
			var input = wrapper.querySelector( 'input' );
			var preview = wrapper.querySelector( '.wpcmb-enhanced__preview' );

			if ( ! input || ! preview || ! window.wpcmb || ! window.wpcmb.qrMatrix ) {
				return;
			}

			/**
			 * Redraw the code for whatever is currently typed.
			 */
			function draw() {
				var value = input.value.trim();

				preview.textContent = '';

				if ( '' === value ) {
					return;
				}

				var matrix = window.wpcmb.qrMatrix( value );

				if ( ! matrix ) {
					preview.textContent = i18n.qrTooLong || '';

					return;
				}

				var drawing = qrSvg( matrix );

				drawing.setAttribute( 'aria-label', value );
				preview.appendChild( drawing );
			}

			input.addEventListener( 'input', draw );
			draw();
		} );
	}

	/* --------------------------------------------------------------------
	 * Multiple select: a searchable checklist with the choices made visible.
	 *
	 * A native `select[multiple]` is a scrolling box that gives up its
	 * selection only to a ctrl-click, and it shows what is chosen only while
	 * the chosen rows happen to be scrolled into view. This replaces the look
	 * of it, not the thing itself: the select stays in the form, keeps its
	 * name and its options, and every change made here is written back to it
	 * and announced with a `change` event, so validation, conditional logic
	 * and the save path go on reading the control they always read. If this
	 * script never runs, the native select is still there and still works.
	 * ----------------------------------------------------------------- */

	/**
	 * The chevron used on a multiple select's trigger.
	 *
	 * @return {SVGElement} The icon.
	 */
	function chevronIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		var path = document.createElementNS( ns, 'path' );

		svg.setAttribute( 'class', 'wpcmb-svg' );
		svg.setAttribute( 'viewBox', '0 0 16 16' );
		svg.setAttribute( 'width', '16' );
		svg.setAttribute( 'height', '16' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.5' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		path.setAttribute( 'd', 'M4.5 6.25 8 9.75l3.5-3.5' );
		svg.appendChild( path );

		return svg;
	}

	/**
	 * Wire every multiple select inside a container.
	 *
	 * @param {Element} root Container.
	 */
	function initMultiSelects( root ) {
		root.querySelectorAll( 'select.wpcmb-input[multiple]' ).forEach( function ( select ) {
			if ( select.hasAttribute( 'data-wpcmb-multiselect' ) ) {
				return;
			}

			select.setAttribute( 'data-wpcmb-multiselect', '1' );

			var options = Array.prototype.slice.call( select.options );
			var wrapper = el( 'div', { class: 'wpcmb-multiselect' } );
			var chips = el( 'div', { class: 'wpcmb-multiselect__chips', hidden: 'hidden' } );
			var trigger = el( 'button', {
				type: 'button',
				class: 'wpcmb-multiselect__trigger',
				'aria-expanded': 'false',
			} );
			var label = el( 'span', { class: 'wpcmb-multiselect__label' } );
			var panel = el( 'div', { class: 'wpcmb-multiselect__panel', hidden: 'hidden' } );
			var search = el( 'input', {
				type: 'search',
				class: 'wpcmb-input wpcmb-multiselect__search',
				placeholder: i18n.searchOptions || '',
				'aria-label': i18n.searchOptions || '',
			} );
			var list = el( 'div', { class: 'wpcmb-multiselect__list' } );
			var empty = el( 'p', {
				class: 'wpcmb-multiselect__empty',
				text: i18n.noMatches || '',
				hidden: 'hidden',
			} );
			var rows = [];

			/**
			 * Redraw the chips and the trigger from the select's own state.
			 */
			function render() {
				var chosen = options.filter( function ( option ) {
					return option.selected;
				} );

				chips.textContent = '';
				chips.hidden = 0 === chosen.length;

				chosen.forEach( function ( option ) {
					var chip = el( 'span', { class: 'wpcmb-multiselect__chip' }, [
						el( 'span', { text: option.text } ),
					] );
					var remove = el( 'button', {
						type: 'button',
						class: 'wpcmb-multiselect__chip-remove',
						'aria-label': ( i18n.remove || '' ) + ': ' + option.text,
						text: '×',
					} );

					remove.addEventListener( 'click', function () {
						option.selected = false;
						render();
						announce();
					} );

					chip.appendChild( remove );
					chips.appendChild( chip );
				} );

				rows.forEach( function ( row ) {
					row.box.checked = row.option.selected;
				} );

				label.textContent = 0 === chosen.length
					? ( i18n.selectOptions || '' )
					: ( i18n.selectedCount || '' ).replace( '%d', String( chosen.length ) );

				label.classList.toggle( 'is-placeholder', 0 === chosen.length );
			}

			/**
			 * Tell the page the selection changed.
			 *
			 * Conditional logic and validation listen on the select, and a
			 * scripted change to `selected` fires nothing on its own.
			 */
			function announce() {
				select.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			/**
			 * Open or close the panel.
			 *
			 * @param {boolean} open Whether to open.
			 */
			function toggle( open ) {
				panel.hidden = ! open;
				trigger.setAttribute( 'aria-expanded', String( open ) );

				if ( open ) {
					search.focus();
				}
			}

			/**
			 * Show only the options matching what is typed.
			 */
			function filter() {
				var term = search.value.trim().toLowerCase();
				var shown = 0;

				rows.forEach( function ( row ) {
					var match = '' === term || -1 !== row.option.text.toLowerCase().indexOf( term );

					row.node.hidden = ! match;
					shown += match ? 1 : 0;
				} );

				empty.hidden = 0 !== shown;
			}

			options.forEach( function ( option ) {
				var box = el( 'input', { type: 'checkbox', class: 'wpcmb-multiselect__box' } );
				var row = el( 'label', { class: 'wpcmb-multiselect__option' }, [
					box,
					el( 'span', { text: option.text } ),
				] );

				box.checked = option.selected;
				box.addEventListener( 'change', function () {
					option.selected = box.checked;
					render();
					announce();
				} );

				list.appendChild( row );
				rows.push( { option: option, box: box, node: row } );
			} );

			trigger.appendChild( label );
			trigger.appendChild( chevronIcon() );
			panel.appendChild( search );
			panel.appendChild( list );
			panel.appendChild( empty );

			select.parentNode.insertBefore( wrapper, select );
			wrapper.appendChild( chips );
			wrapper.appendChild( trigger );
			wrapper.appendChild( panel );
			wrapper.appendChild( select );

			// Still in the form and still submitted, but no longer a second
			// control to tab through or a second thing to read out.
			select.classList.add( 'wpcmb-multiselect__native' );
			select.setAttribute( 'tabindex', '-1' );
			select.setAttribute( 'aria-hidden', 'true' );

			trigger.addEventListener( 'click', function () {
				toggle( panel.hidden );
			} );

			search.addEventListener( 'input', filter );

			// A search input's own clear button fires `search`, not `input`.
			search.addEventListener( 'search', filter );

			wrapper.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && ! panel.hidden ) {
					toggle( false );
					trigger.focus();
				}
			} );

			document.addEventListener( 'click', function ( event ) {
				if ( ! panel.hidden && ! wrapper.contains( event.target ) ) {
					toggle( false );
				}
			} );

			render();
		} );
	}

	/**
	 * Initialise every advanced control inside a container.
	 *
	 * @param {Element} [root] Container, defaulting to the document.
	 */
	function init( root ) {
		root = root || document;

		initIcons( root );
		initSignature( root );
		initMap( root );
		initEmbed( root );
		initCodes( root );
		initMultiSelects( root );
	}

	window.wpcmb = window.wpcmb || {};
	window.wpcmb.initEnhanced = init;

	// Run for markup added later — a repeater row — on the same event the
	// rest of the field enhancements use.
	document.addEventListener( 'wpcmb:enhance', function ( event ) {
		init( event.detail && event.detail.root ? event.detail.root : document );
	} );
}() );
