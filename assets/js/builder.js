/**
 * Field group editor: the fields list and the location rule builder.
 *
 * Both builders keep their state in a hidden textarea holding JSON, which is
 * what the form posts. Nothing here writes markup from user input as HTML:
 * every value goes through textContent or a form control's value.
 *
 * @package WPCMB
 */

( function ( $ ) {
	'use strict';

	var config = window.wpcmbBuilder || {};
	var i18n = config.i18n || {};

	/**
	 * Create an element with attributes, text and children.
	 *
	 * @param {string} tag     Tag name.
	 * @param {Object} [attrs] Attributes. `class`, `text` and `dataset` are special-cased.
	 * @param {Array}  [kids]  Child nodes.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, attrs, kids ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( name ) {
			var value = attrs[ name ];

			if ( 'text' === name ) {
				node.textContent = value;
			} else if ( 'dataset' === name ) {
				Object.keys( value ).forEach( function ( key ) {
					node.dataset[ key ] = value[ key ];
				} );
			} else if ( false !== value && null !== value && undefined !== value ) {
				node.setAttribute( name, value );
			}
		} );

		( kids || [] ).forEach( function ( kid ) {
			if ( kid ) {
				node.appendChild( kid );
			}
		} );

		return node;
	}

	/**
	 * Build a select from a flat map or a map of optgroups.
	 *
	 * @param {Object} options  Values keyed by value, or groups of those.
	 * @param {string} selected Currently selected value.
	 * @param {bool}   grouped  Whether `options` is a map of optgroups.
	 * @return {HTMLSelectElement} The select.
	 */
	function select( options, selected, grouped ) {
		var node = el( 'select', { class: 'wpcmb-field-row__control' } );

		function addOption( parent, value, label ) {
			var option = el( 'option', { value: value, text: label } );
			option.selected = String( value ) === String( selected );
			parent.appendChild( option );
		}

		Object.keys( options || {} ).forEach( function ( key ) {
			if ( grouped ) {
				var group = el( 'optgroup', { label: key } );
				Object.keys( options[ key ] ).forEach( function ( value ) {
					addOption( group, value, options[ key ][ value ] );
				} );
				node.appendChild( group );
			} else {
				addOption( node, key, options[ key ] );
			}
		} );

		return node;
	}

	/**
	 * A yes/no control for a setting stored as '' or '1'.
	 *
	 * Not built with select() above, because that takes an options object and
	 * the two values here cannot survive one. JavaScript enumerates keys that
	 * look like array indices before string keys whatever order they were
	 * written in, so `{ '': No, 1: Yes }` comes back as Yes-then-No. With no
	 * option matching an unset setting the browser then selects the first, so
	 * every new field displayed "Yes" while storing nothing — the setting read
	 * as off, and choosing the "Yes" already on screen fired no change event
	 * to correct it.
	 *
	 * @param {*} value Stored setting.
	 * @return {HTMLElement} The select.
	 */
	function toggleSelect( value ) {
		var node = el( 'select', { class: 'wpcmb-field-row__control' } );

		node.appendChild( el( 'option', { value: '', text: i18n.no } ) );
		node.appendChild( el( 'option', { value: '1', text: i18n.yes } ) );

		// '0' is what a stored "no" looks like coming back from PHP, and it is
		// truthy as a string.
		node.value = value && '0' !== String( value ) ? '1' : '';

		return node;
	}

	/**
	 * A labelled control wrapped for the builder grid.
	 *
	 * @param {string}      label   Label text.
	 * @param {HTMLElement} control Control element.
	 * @param {string}      [width] Optional grid width class suffix.
	 * @return {HTMLElement} The wrapper.
	 */
	function labelled( label, control, width ) {
		var id = 'wpcmb-c-' + Math.random().toString( 36 ).slice( 2, 9 );

		control.id = id;

		return el( 'div', { class: 'wpcmb-field-row__cell' + ( width ? ' is-' + width : '' ) }, [
			el( 'label', { text: label, for: id, class: 'wpcmb-label' } ),
			control,
		] );
	}

	/**
	 * Coerce a value into a plain object suitable for keyed properties.
	 *
	 * PHP has one array type, so an empty associative array encodes to JSON
	 * as `[]`, not `{}`. In JavaScript `[]` is truthy, so the usual
	 * `value || {}` guard keeps it — and then two things go wrong. Reading
	 * `value.rules` gives undefined and throws on the next call, and writing
	 * `value.min = 5` sets a named property on an Array, which
	 * JSON.stringify silently discards. The second is the dangerous one: the
	 * setting appears to save and is simply gone.
	 *
	 * @param {*} value Stored value.
	 * @return {Object} A plain object, empty when there was nothing usable.
	 */
	function asObject( value ) {
		if ( ! value || 'object' !== typeof value || Array.isArray( value ) ) {
			return {};
		}

		return value;
	}

	/**
	 * Put a stored field into the shape the editor expects.
	 *
	 * Applied on load and to every newly added field, so the rest of the
	 * builder can rely on these being real objects.
	 *
	 * @param {Object} field Field definition.
	 * @return {Object} The same field.
	 */
	function normaliseField( field ) {
		field.settings = asObject( field.settings );
		field.wrapper = asObject( field.wrapper );

		var conditional = asObject( field.conditional );

		field.conditional = {
			action: conditional.action || 'show',
			logic: conditional.logic || 'all',
			rules: Array.isArray( conditional.rules ) ? conditional.rules : [],
		};

		( field.sub_fields || [] ).forEach( normaliseField );

		( field.layouts || [] ).forEach( function ( layout ) {
			layout.settings = asObject( layout.settings );
			( layout.sub_fields || [] ).forEach( normaliseField );
		} );

		return field;
	}

	/**
	 * Generate a key with the same shape the PHP sanitizer accepts.
	 *
	 * @param {string} prefix Key prefix.
	 * @return {string} The key.
	 */
	function generateKey( prefix ) {
		var random = '';

		while ( random.length < 13 ) {
			random += Math.random().toString( 36 ).slice( 2 );
		}

		return prefix + '_' + random.slice( 0, 13 );
	}

	/**
	 * The human label for a field type.
	 *
	 * Types arrive grouped for the picker's optgroups, so finding one means
	 * looking through the groups.
	 *
	 * @param {string} type Type name.
	 * @return {string} The label, or the raw type when it is not registered.
	 */
	function typeLabel( type ) {
		var groups = config.fieldTypes || {};
		var found = type;

		Object.keys( groups ).forEach( function ( group ) {
			if ( groups[ group ][ type ] ) {
				found = groups[ group ][ type ];
			}
		} );

		return found;
	}

	/**
	 * A section heading with an optional count.
	 *
	 * The count is what makes a collapsed section useful: without it there is
	 * no way to tell an empty Sub fields section from one holding six.
	 *
	 * @param {string} text  Heading text.
	 * @param {number} [num] Count to show.
	 * @return {HTMLElement} The summary element.
	 */
	function summaryWithCount( text, num ) {
		var summary = el( 'summary', { text: text } );

		if ( num ) {
			summary.appendChild( el( 'span', { class: 'wpcmb-count', text: String( num ) } ) );
		}

		return summary;
	}

	/**
	 * A tabbed panel set.
	 *
	 * Built to the ARIA tabs pattern: one stop in the tab order for the whole
	 * set, arrow keys to move between tabs, Home and End to jump. Without the
	 * arrow-key handling this would be five tab stops a keyboard user has to
	 * walk through to reach the panel.
	 *
	 * @param {Array} panels `{ id, label, content }`, empty ones skipped.
	 * @return {HTMLElement|null} The tab set, or null when there is one panel.
	 */
	function tabbed( panels ) {
		panels = panels.filter( function ( panel ) {
			return panel.content && panel.content.childNodes.length;
		} );

		if ( ! panels.length ) {
			return null;
		}

		// A single panel needs no tab to select it.
		if ( 1 === panels.length ) {
			return panels[ 0 ].content;
		}

		var wrap = el( 'div', { class: 'wpcmb-tabset' } );
		var strip = el( 'div', { class: 'wpcmb-tabset__list', role: 'tablist' } );
		var buttons = [];

		function activate( index ) {
			panels.forEach( function ( panel, i ) {
				var selected = i === index;

				buttons[ i ].setAttribute( 'aria-selected', String( selected ) );
				buttons[ i ].setAttribute( 'tabindex', selected ? '0' : '-1' );
				buttons[ i ].classList.toggle( 'is-active', selected );
				panel.content.hidden = ! selected;
			} );
		}

		panels.forEach( function ( panel, index ) {
			var id = 'wpcmb-tab-' + Math.random().toString( 36 ).slice( 2, 9 );

			var button = el( 'button', {
				type: 'button',
				class: 'wpcmb-tabset__tab',
				role: 'tab',
				id: id,
				'aria-controls': id + '-panel',
			} );

			button.textContent = panel.label;

			button.addEventListener( 'click', function () {
				activate( index );
			} );

			button.addEventListener( 'keydown', function ( event ) {
				var next = null;

				if ( 'ArrowRight' === event.key ) {
					next = ( index + 1 ) % panels.length;
				} else if ( 'ArrowLeft' === event.key ) {
					next = ( index - 1 + panels.length ) % panels.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = panels.length - 1;
				}

				if ( null === next ) {
					return;
				}

				event.preventDefault();
				activate( next );
				buttons[ next ].focus();
			} );

			panel.content.id = id + '-panel';
			panel.content.setAttribute( 'role', 'tabpanel' );
			panel.content.setAttribute( 'aria-labelledby', id );
			panel.content.classList.add( 'wpcmb-tabset__panel' );

			buttons.push( button );
			strip.appendChild( button );
		} );

		wrap.appendChild( strip );

		panels.forEach( function ( panel ) {
			wrap.appendChild( panel.content );
		} );

		activate( 0 );

		return wrap;
	}

	/**
	 * A placeholder shown in place of an empty list.
	 *
	 * @param {string} title  What is missing.
	 * @param {string} detail What to do about it.
	 * @return {HTMLElement} The placeholder.
	 */
	function emptyState( title, detail ) {
		return el( 'div', { class: 'wpcmb-empty' }, [
			el( 'span', { class: 'wpcmb-empty__title', text: title } ),
			el( 'span', { text: detail } ),
		] );
	}

	/**
	 * Turn a label into a meta key.
	 *
	 * Mirrors sanitize_key(): the PHP side re-sanitizes, so a mismatch here
	 * would silently rename a field on save.
	 *
	 * @param {string} label Field label.
	 * @return {string} The name.
	 */
	function toName( label ) {
		return String( label )
			.toLowerCase()
			.replace( /[^a-z0-9_\-\s]/g, '' )
			.trim()
			.replace( /[\s\-]+/g, '_' );
	}

	/**
	 * Base builder: owns the JSON state and re-renders on structural change.
	 *
	 * @param {HTMLElement} root Builder root element.
	 * @constructor
	 */
	function Builder( root ) {
		this.root = root;
		this.state = root.querySelector( '.wpcmb-builder__state' );
		this.list = root.querySelector( '[data-wpcmb-list]' );
		this.items = this.read();
	}

	Builder.prototype.read = function () {
		try {
			var parsed = JSON.parse( this.state.value );
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	};

	/**
	 * Read state that holds field definitions rather than location rules.
	 *
	 * @return {Array} Normalised field definitions.
	 */
	Builder.prototype.readFields = function () {
		return this.read().filter( function ( field ) {
			return field && 'object' === typeof field;
		} ).map( normaliseField );
	};

	Builder.prototype.sync = function () {
		this.state.value = JSON.stringify( this.items );
	};

	Builder.prototype.render = function () {
		this.sync();
		this.list.textContent = '';
		this.items.forEach( function ( item, index ) {
			this.list.appendChild( this.renderItem( item, index ) );
		}, this );
	};

	/**
	 * Bind a control to a property, syncing without a re-render.
	 *
	 * Re-rendering on keystrokes would move focus, so text controls only
	 * update the state; the DOM already shows the right value.
	 *
	 * @param {HTMLElement} control  Control element.
	 * @param {Object}      target   Object to write to.
	 * @param {string}      property Property name.
	 * @param {Function}    [after]  Optional callback after each change.
	 * @return {HTMLElement} The control.
	 */
	Builder.prototype.bind = function ( control, target, property, after ) {
		var builder = this;

		control.addEventListener( 'input', function () {
			target[ property ] = 'checkbox' === control.type ? control.checked : control.value;
			builder.sync();

			if ( builder.refreshSummary ) {
				builder.refreshSummary();
			}

			if ( after ) {
				after();
			}
		} );

		return control;
	};

	/**
	 * Make a list sortable, reordering the backing array to match.
	 *
	 * @param {HTMLElement} list  List element.
	 * @param {Array}       items Backing array.
	 */
	Builder.prototype.sortable = function ( list, items ) {
		var builder = this;

		$( list ).sortable( {
			handle: '.wpcmb-field-row__handle',
			axis: 'y',
			placeholder: 'wpcmb-field-row__placeholder',
			forcePlaceholderSize: true,
			update: function ( event, ui ) {
				var from = ui.item.data( 'wpcmbIndex' );
				var to = ui.item.index();

				items.splice( to, 0, items.splice( from, 1 )[ 0 ] );
				builder.render();
			},
			start: function ( event, ui ) {
				ui.item.data( 'wpcmbIndex', ui.item.index() );
			},
		} );
	};

	/**
	 * The fields list.
	 *
	 * @param {HTMLElement} root Builder root.
	 * @constructor
	 */
	function FieldsBuilder( root ) {
		Builder.call( this, root );

		// Field state arrives from PHP, where an empty map encodes as `[]`.
		this.items = this.readFields();

		var builder = this;

		this.buildToolbar();

		root.querySelector( '[data-wpcmb-add-field]' ).addEventListener( 'click', function () {
			builder.items.push( blankField() );
			builder.render();
			builder.open( builder.items.length - 1 );
		} );

		this.render();
	}

	FieldsBuilder.prototype = Object.create( Builder.prototype );
	FieldsBuilder.prototype.constructor = FieldsBuilder;

	FieldsBuilder.prototype.render = function () {
		Builder.prototype.render.call( this );

		if ( ! this.items.length ) {
			this.list.appendChild(
				emptyState( i18n.noFieldsYet, i18n.noFieldsHint )
			);
		}

		this.sortable( this.list, this.items );
		this.validate();
		this.refreshToolbar();
	};

	/**
	 * Build the toolbar above the field list, once.
	 *
	 * Only the top-level list gets one: a nested list is already scoped by
	 * the field it belongs to, and a search box per repeater would be noise.
	 */
	FieldsBuilder.prototype.buildToolbar = function () {
		var builder = this;

		this.count = el( 'span', { class: 'wpcmb-toolbar__count' } );

		this.search = el( 'input', {
			type: 'search',
			class: 'wpcmb-toolbar__search',
			placeholder: i18n.searchFields,
			'aria-label': i18n.searchFields,
		} );

		this.search.addEventListener( 'input', function () {
			builder.filter( builder.search.value );
		} );

		var expand = el( 'button', { type: 'button', class: 'wpcmb-toolbar__button', text: i18n.expandAll } );
		var collapse = el( 'button', { type: 'button', class: 'wpcmb-toolbar__button', text: i18n.collapseAll } );

		expand.addEventListener( 'click', function () {
			builder.setCollapsed( false );
		} );

		collapse.addEventListener( 'click', function () {
			builder.setCollapsed( true );
		} );

		var toolbar = el( 'div', { class: 'wpcmb-toolbar' }, [
			this.search,
			this.count,
			el( 'span', { class: 'wpcmb-toolbar__actions' }, [ expand, collapse ] ),
		] );

		this.list.parentNode.insertBefore( toolbar, this.list );
	};

	/**
	 * Show only the rows matching a search term.
	 *
	 * Rows are hidden rather than removed, so a filtered list still posts
	 * every field. Filtering by rebuilding `items` would drop the rest on
	 * save, which is the kind of bug that only shows up after the fact.
	 *
	 * @param {string} term Search term.
	 */
	FieldsBuilder.prototype.filter = function ( term ) {
		term = String( term || '' ).trim().toLowerCase();

		Array.prototype.forEach.call( this.list.children, function ( row ) {
			if ( ! row.dataset.wpcmbSearch ) {
				return;
			}

			row.hidden = '' !== term && -1 === row.dataset.wpcmbSearch.indexOf( term );
		} );

		this.refreshToolbar();
	};

	/**
	 * Collapse or expand every row.
	 *
	 * @param {boolean} collapsed Whether rows should be collapsed.
	 */
	FieldsBuilder.prototype.setCollapsed = function ( collapsed ) {
		Array.prototype.forEach.call( this.list.children, function ( row ) {
			if ( ! row.classList.contains( 'wpcmb-field-row' ) ) {
				return;
			}

			row.classList.toggle( 'is-collapsed', collapsed );

			var toggle = row.querySelector( ':scope > .wpcmb-field-row__header > .wpcmb-field-row__toggle' );

			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			}
		} );
	};

	/**
	 * Update the live count beside the search box.
	 */
	FieldsBuilder.prototype.refreshToolbar = function () {
		if ( ! this.count ) {
			return;
		}

		var total = this.items.length;
		var shown = Array.prototype.filter.call( this.list.children, function ( row ) {
			return row.dataset.wpcmbSearch && ! row.hidden;
		} ).length;

		this.count.textContent = shown === total
			? i18n.fieldCount.replace( '%d', String( total ) )
			: i18n.fieldCountFiltered.replace( '%1$d', String( shown ) ).replace( '%2$d', String( total ) );
	};

	/**
	 * Expand a row by index.
	 *
	 * @param {number} index Row index.
	 */
	FieldsBuilder.prototype.open = function ( index ) {
		var row = this.list.children[ index ];

		if ( row ) {
			row.classList.remove( 'is-collapsed' );
			var name = row.querySelector( '[data-wpcmb-focus]' );
			if ( name ) {
				name.focus();
			}
		}
	};

	/**
	 * Flag duplicate or missing field names.
	 *
	 * Duplicates would overwrite each other's meta, so this blocks nothing
	 * but makes the collision visible before the group is saved.
	 */
	FieldsBuilder.prototype.validate = function () {
		var seen = {};
		var notice = this.root.querySelector( '.wpcmb-builder__notice' );
		var problem = '';

		this.items.forEach( function ( field ) {
			if ( ! field.name ) {
				problem = problem || i18n.nameRequired;
				return;
			}
			if ( seen[ field.name ] ) {
				problem = problem || i18n.duplicateName;
			}
			seen[ field.name ] = true;
		} );

		if ( ! notice ) {
			notice = el( 'p', { class: 'wpcmb-builder__notice' } );
			this.root.appendChild( notice );
		}

		notice.textContent = problem;
		notice.hidden = ! problem;
	};

	FieldsBuilder.prototype.renderItem = function ( field, index ) {
		var builder = this;
		var nameEdited = !! field.name;

		var title = el( 'span', {
			class: 'wpcmb-field-row__title',
			text: field.label || i18n.newField || 'New Field',
		} );

		var labelInput = this.bind(
			el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: field.label || '' } ),
			field,
			'label',
			function () {
				title.textContent = field.label || i18n.newField || 'New Field';

				if ( ! nameEdited ) {
					field.name = toName( field.label );
					nameInput.value = field.name;
					builder.sync();
					builder.validate();
				}
			}
		);
		labelInput.dataset.wpcmbFocus = '1';

		var nameInput = this.bind(
			el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: field.name || '' } ),
			field,
			'name',
			function () {
				nameEdited = true;
				builder.validate();
			}
		);

		var badge = el( 'span', { class: 'wpcmb-field-row__type', text: typeLabel( field.type ) } );

		var typeSelect = this.bind(
			select( config.fieldTypes, field.type, true ),
			field,
			'type',
			function () {
				// Settings belong to a type. Keeping the old type's settings
				// would silently apply them to a field that no longer has
				// those options, so the row is rebuilt from scratch.
				field.settings = {};
				builder.render();
				builder.open( index );
			}
		);

		var requiredInput = el( 'input', { type: 'checkbox', class: 'wpcmb-field-row__checkbox' } );
		requiredInput.checked = !! field.required;
		this.bind( requiredInput, field, 'required' );

		field.wrapper = asObject( field.wrapper );

		var instructions = this.bind(
			el( 'textarea', { rows: '2', class: 'wpcmb-field-row__control' } ),
			field,
			'instructions'
		);

		instructions.value = field.instructions || '';

		var general = el( 'div', {}, [
			el( 'div', { class: 'wpcmb-field-row__grid' }, [
				labelled( i18n.label, labelInput ),
				labelled( i18n.name, nameInput ),
				labelled( i18n.type, typeSelect ),
				labelled(
					i18n.defaultValue,
					this.bind(
						el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: field.default || '' } ),
						field,
						'default'
					)
				),
				labelled(
					i18n.placeholder,
					this.bind(
						el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: field.placeholder || '' } ),
						field,
						'placeholder'
					)
				),
			] ),
			labelled( i18n.instructions, instructions ),
			el( 'label', { class: 'wpcmb-checkbox' }, [ requiredInput, el( 'span', { text: ' ' + i18n.required } ) ] ),
			this.renderSettings( field, 'general' ),
			this.renderSubFields( field ),
			this.renderLayouts( field, index ),
		] );

		var appearance = el( 'div', {}, [
			el( 'div', { class: 'wpcmb-field-row__grid' }, [
				labelled(
					i18n.width,
					this.bind(
						el( 'input', {
							type: 'number',
							min: '0',
							max: '100',
							class: 'wpcmb-field-row__control',
							value: field.wrapper.width || '',
						} ),
						field.wrapper,
						'width'
					)
				),
				labelled(
					i18n.wrapperClass,
					this.bind(
						el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: field.wrapper.class || '' } ),
						field.wrapper,
						'class'
					)
				),
			] ),
			this.renderSettings( field, 'appearance' ),
		] );

		var advanced = el( 'div', {}, [
			el( 'div', { class: 'wpcmb-field-row__grid' }, [
				labelled(
					i18n.wrapperId,
					this.bind(
						el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: field.wrapper.id || '' } ),
						field.wrapper,
						'id'
					)
				),
				labelled(
					i18n.fieldKey,
					el( 'input', {
						type: 'text',
						class: 'wpcmb-field-row__control',
						value: field.key || '',
						readonly: 'readonly',
					} )
				),
			] ),
			this.renderSettings( field, 'advanced' ),
		] );

		var body = el( 'div', { class: 'wpcmb-field-row__body' }, [
			tabbed( [
				{ label: i18n.tabGeneral, content: general },
				{ label: i18n.tabValidation, content: el( 'div', {}, [ this.renderSettings( field, 'validation' ) ] ) },
				{ label: i18n.tabAppearance, content: appearance },
				{ label: i18n.tabLogic, content: el( 'div', {}, [ this.renderConditional( field, index ) ] ) },
				{ label: i18n.tabAdvanced, content: advanced },
			] ),
		] );

		var toggle = el( 'button', {
			type: 'button',
			class: 'wpcmb-field-row__toggle',
			'aria-expanded': 'true',
			'aria-label': i18n.collapseField,
			text: '▾',
		} );

		var duplicate = el( 'button', {
			type: 'button',
			class: 'wpcmb-field-row__duplicate',
			'aria-label': i18n.duplicateField,
			title: i18n.duplicateField,
			text: '⧉',
		} );

		var remove = el( 'button', {
			type: 'button',
			class: 'wpcmb-field-row__remove',
			'aria-label': i18n.deleteField,
			title: i18n.deleteField,
			text: '×',
		} );

		var icon = el( 'span', {
			class: 'wpcmb-field-row__icon dashicons ' + ( ( config.fieldIcons || {} )[ field.type ] || 'dashicons-edit' ),
			'aria-hidden': 'true',
		} );

		var required = el( 'abbr', {
			class: 'wpcmb-field-row__required',
			title: i18n.required,
			text: '*',
		} );

		required.hidden = ! field.required;

		requiredInput.addEventListener( 'change', function () {
			required.hidden = ! requiredInput.checked;
		} );

		var row = el( 'div', { class: 'wpcmb-field-row', dataset: { wpcmbIndex: index } }, [
			el( 'div', { class: 'wpcmb-field-row__header' }, [
				el( 'span', { class: 'wpcmb-field-row__handle', title: i18n.reorder, text: '☰' } ),
				icon,
				title,
				required,
				badge,
				el( 'code', { class: 'wpcmb-field-row__key', text: field.name || '' } ),
				toggle,
				duplicate,
				remove,
			] ),
			body,
		] );

		// Read by the toolbar's filter, so searching never touches state.
		row.dataset.wpcmbSearch = (
			( field.label || '' ) + ' ' + ( field.name || '' ) + ' ' + typeLabel( field.type )
		).toLowerCase();

		toggle.addEventListener( 'click', function () {
			var collapsed = row.classList.toggle( 'is-collapsed' );
			toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			toggle.setAttribute( 'aria-label', collapsed ? i18n.expandField : i18n.collapseField );
		} );

		duplicate.addEventListener( 'click', function () {
			// Deep copied and re-keyed, so the copy is a separate field rather
			// than a second reference to the same one. The name is cleared
			// because two fields sharing a name overwrite each other's values.
			var copy = normaliseField( JSON.parse( JSON.stringify( field ) ) );

			rekey( copy );
			copy.name = '';
			copy.label = ( field.label || '' ) + ' ' + i18n.copySuffix;

			builder.items.splice( index + 1, 0, copy );
			builder.render();
			builder.open( index + 1 );
		} );

		remove.addEventListener( 'click', function () {
			if ( window.confirm( i18n.confirmRemove ) ) {
				builder.items.splice( index, 1 );
				builder.render();
			}
		} );

		nameInput.addEventListener( 'input', function () {
			row.querySelector( '.wpcmb-field-row__key' ).textContent = field.name || '';
		} );

		return row;
	};

	/**
	 * Build a nested field list bound to an array on a parent field.
	 *
	 * Shared by the sub-field editor and the layout editor: both are the same
	 * list of field rows, differing only in which array they write to.
	 *
	 * @param {Object} parent Owning builder.
	 * @param {Array}  items  Array to edit in place.
	 * @return {Object} A builder bound to those items.
	 */
	function childBuilder( parent, items ) {
		var child = Object.create( FieldsBuilder.prototype );

		child.root = parent.root;
		child.items = items;
		child.list = el( 'div', { class: 'wpcmb-builder__list' } );
		child.sync = function () {
			parent.sync();
		};
		child.validate = function () {};

		return child;
	}

	/**
	 * A blank field definition.
	 *
	 * @return {Object} The field.
	 */
	function blankField() {
		return normaliseField( {
			key: generateKey( 'field' ),
			name: '',
			label: '',
			type: 'text',
			instructions: '',
			required: false,
			default: '',
			placeholder: '',
			wrapper: { width: '', class: '', id: '' },
			settings: {},
		} );
	}

	/**
	 * Render a nested field list for types that hold sub fields.
	 *
	 * The nested list is the same code as the top-level one, operating on
	 * `field.sub_fields` and reporting changes upward. That is what makes a
	 * repeater inside a repeater work in the editor without a second, subtly
	 * different implementation of every field row.
	 *
	 * @param {Object} field Field being edited.
	 * @return {HTMLElement|null} The sub field section, or null.
	 */
	FieldsBuilder.prototype.renderSubFields = function ( field ) {
		if ( -1 === ( config.subFieldTypes || [] ).indexOf( field.type ) ) {
			return null;
		}

		field.sub_fields = field.sub_fields || [];

		var child = childBuilder( this, field.sub_fields );
		var add = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addSubField } );

		add.addEventListener( 'click', function () {
			child.items.push( blankField() );
			child.render();
			child.open( child.items.length - 1 );
		} );

		child.render();

		return el( 'details', { class: 'wpcmb-subsection', open: field.sub_fields.length ? 'open' : false }, [
			summaryWithCount( i18n.subFields, field.sub_fields.length ),
			child.list,
			el( 'p', {}, [ add ] ),
		] );
	};

	/**
	 * Render the layout editor for flexible content.
	 *
	 * Each layout is a name, a label, an icon, a category and its own nested
	 * field list — the same field list used everywhere else, so a repeater
	 * inside a layout inside a repeater needs no extra code.
	 *
	 * @param {Object} field Field being edited.
	 * @param {number} index Field position.
	 * @return {HTMLElement|null} The layouts section, or null.
	 */
	FieldsBuilder.prototype.renderLayouts = function ( field, index ) {
		if ( -1 === ( config.layoutFieldTypes || [] ).indexOf( field.type ) ) {
			return null;
		}

		var builder = this;

		field.layouts = field.layouts || [];

		var list = el( 'div', { class: 'wpcmb-layout-list' } );

		field.layouts.forEach( function ( layout, layoutIndex ) {
			var nameEdited = !! layout.name;

			layout.settings = asObject( layout.settings );

			var labelInput = builder.bind(
				el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: layout.label || '' } ),
				layout,
				'label',
				function () {
					if ( ! nameEdited ) {
						layout.name = toName( layout.label );
						nameInput.value = layout.name;
						builder.sync();
					}
				}
			);

			var nameInput = builder.bind(
				el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: layout.name || '' } ),
				layout,
				'name',
				function () {
					nameEdited = true;
				}
			);

			var remove = el( 'button', { type: 'button', class: 'button-link-delete', text: '×' } );

			remove.addEventListener( 'click', function () {
				if ( window.confirm( i18n.confirmRemoveLayout ) ) {
					field.layouts.splice( layoutIndex, 1 );
					builder.render();
					builder.open( index );
				}
			} );

			layout.sub_fields = layout.sub_fields || [];

			var child = childBuilder( builder, layout.sub_fields );
			var addField = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addSubField } );

			addField.addEventListener( 'click', function () {
				child.items.push( blankField() );
				child.render();
				child.open( child.items.length - 1 );
			} );

			child.render();

			list.appendChild(
				el( 'div', { class: 'wpcmb-layout' }, [
					el( 'div', { class: 'wpcmb-field-row__grid' }, [
						labelled( i18n.label, labelInput ),
						labelled( i18n.name, nameInput ),
						labelled(
							i18n.icon,
							builder.bind(
								el( 'input', {
									type: 'text',
									class: 'wpcmb-field-row__control',
									value: layout.settings.icon || '',
									placeholder: 'dashicons-layout',
								} ),
								layout.settings,
								'icon'
							)
						),
						labelled(
							i18n.category,
							builder.bind(
								el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: layout.settings.category || '' } ),
								layout.settings,
								'category'
							)
						),
						labelled(
							i18n.maxPerField,
							builder.bind(
								el( 'input', { type: 'number', min: '0', class: 'wpcmb-field-row__control', value: layout.settings.max || '' } ),
								layout.settings,
								'max'
							)
						),
					] ),
					child.list,
					el( 'p', {}, [ addField, remove ] ),
				] )
			);
		} );

		var addLayout = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addLayout } );

		addLayout.addEventListener( 'click', function () {
			field.layouts.push( {
				key: generateKey( 'layout' ),
				name: '',
				label: '',
				settings: { icon: '', category: '', max: '' },
				sub_fields: [],
			} );
			builder.render();
			builder.open( index );
		} );

		return el( 'details', { class: 'wpcmb-subsection', open: field.layouts.length ? 'open' : false }, [
			summaryWithCount( i18n.layouts, field.layouts.length ),
			list,
			el( 'p', {}, [ addLayout ] ),
		] );
	};

	/**
	 * Render the settings the current field type declares.
	 *
	 * The schema comes from the PHP field type, so a third-party type gets a
	 * settings UI without touching this file.
	 *
	 * @param {Object} field Field being edited.
	 * @return {HTMLElement} The settings section.
	 */
	FieldsBuilder.prototype.renderSettings = function ( field, tab ) {
		var builder = this;
		var schema = ( config.fieldSettings || {} )[ field.type ] || {};

		field.settings = asObject( field.settings );

		var names = Object.keys( schema ).filter( function ( name ) {
			return ( schema[ name ].tab || 'general' ) === tab;
		} );

		var body = el( 'div', { class: 'wpcmb-field-row__grid' } );

		// A tab with no settings of its own contributes nothing, and tabbed()
		// drops empty panels rather than showing a tab that opens on nothing.
		if ( ! names.length ) {
			return null;
		}

		names.forEach( function ( name ) {
			var setting = schema[ name ];
			var control;

			if ( 'select' === setting.type ) {
				control = builder.bind( select( setting.choices || {}, field.settings[ name ], false ), field.settings, name );
			} else if ( 'toggle' === setting.type ) {
				control = builder.bind( toggleSelect( field.settings[ name ] ), field.settings, name );
			} else if ( 'textarea' === setting.type || 'choices' === setting.type ) {
				control = builder.bind( el( 'textarea', { rows: '4', class: 'wpcmb-field-row__control' } ), field.settings, name );
				control.value = field.settings[ name ] || '';
			} else {
				control = builder.bind(
					el( 'input', {
						type: 'number' === setting.type ? 'number' : 'text',
						class: 'wpcmb-field-row__control',
						value: field.settings[ name ] || '',
					} ),
					field.settings,
					name
				);
			}

			var cell = labelled( setting.label || name, control );

			if ( setting.help ) {
				cell.appendChild( el( 'p', { class: 'description', text: setting.help } ) );
			}

			body.appendChild( cell );
		} );

		// No wrapper: the tab already groups these, and a collapsible section
		// inside a tab panel is one layer of chrome too many.
		return body;
	};

	/**
	 * Give a copied field tree fresh keys.
	 *
	 * Duplicating without this leaves two fields sharing a key, which breaks
	 * conditional logic — a rule pointing at that key would match whichever
	 * copy was found first.
	 *
	 * @param {Object} field Field definition, edited in place.
	 */
	function rekey( field ) {
		field.key = generateKey( 'field' );

		( field.sub_fields || [] ).forEach( rekey );

		( field.layouts || [] ).forEach( function ( layout ) {
			layout.key = generateKey( 'layout' );
			( layout.sub_fields || [] ).forEach( rekey );
		} );

		// A rule pointing outside the copied tree is still meaningful; one
		// pointing inside it now names a key that no longer exists, so the
		// copy starts without logic rather than with logic that misfires.
		field.conditional = { action: 'show', logic: 'all', rules: [] };
	}

	/**
	 * Render the conditional logic editor for a field.
	 *
	 * Rules reference sibling field keys, so a field can only depend on
	 * another field in the same group — which is exactly what the server-side
	 * evaluator can resolve.
	 *
	 * @param {Object} field Field being edited.
	 * @param {number} index Field position.
	 * @return {HTMLElement} The conditional logic section.
	 */
	FieldsBuilder.prototype.renderConditional = function ( field, index ) {
		var builder = this;
		var others = this.items.filter( function ( other, i ) {
			return i !== index && other.key && other.name;
		} );

		if ( ! others.length ) {
			return el( 'details', { class: 'wpcmb-subsection' }, [
				el( 'summary', { text: i18n.logic } ),
				el( 'p', { class: 'description', text: i18n.noOtherFields } ),
			] );
		}

		// normaliseField() guarantees this shape on load and for new fields;
		// re-checking here keeps a field injected by other code from throwing.
		var logic = asObject( field.conditional );

		field.conditional = {
			action: logic.action || 'show',
			logic: logic.logic || 'all',
			rules: Array.isArray( logic.rules ) ? logic.rules : [],
		};

		var targets = {};
		others.forEach( function ( other ) {
			targets[ other.key ] = other.label || other.name;
		} );

		var header = el( 'div', { class: 'wpcmb-rule' }, [
			this.bind(
				select( { show: i18n.showThisField, hide: i18n.hideThisField }, field.conditional.action, false ),
				field.conditional,
				'action'
			),
			this.bind(
				select( { all: i18n.allRules, any: i18n.anyRule }, field.conditional.logic, false ),
				field.conditional,
				'logic'
			),
		] );

		var list = el( 'div' );

		field.conditional.rules.forEach( function ( rule, ruleIndex ) {
			var remove = el( 'button', { type: 'button', class: 'button-link-delete', text: '×' } );

			remove.addEventListener( 'click', function () {
				field.conditional.rules.splice( ruleIndex, 1 );
				builder.render();
				builder.open( index );
			} );

			list.appendChild(
				el( 'div', { class: 'wpcmb-rule' }, [
					builder.bind( select( targets, rule.field, false ), rule, 'field' ),
					builder.bind(
						select(
							{
								'==': i18n.isEqual,
								'!=': i18n.isNotEqual,
								contains: 'contains',
								not_contains: 'does not contain',
								'>': 'is greater than',
								'<': 'is less than',
								empty: 'is empty',
								not_empty: 'is not empty',
								pattern: 'matches pattern',
							},
							rule.operator,
							false
						),
						rule,
						'operator'
					),
					builder.bind(
						el( 'input', { type: 'text', class: 'wpcmb-field-row__control', value: rule.value || '' } ),
						rule,
						'value'
					),
					remove,
				] )
			);
		} );

		var add = el( 'button', { type: 'button', class: 'button button-small', text: i18n.addCondition } );

		add.addEventListener( 'click', function () {
			field.conditional.rules.push( { field: Object.keys( targets )[ 0 ], operator: '==', value: '' } );
			builder.render();
			builder.open( index );
		} );

		return el(
			'details',
			{ class: 'wpcmb-subsection', open: field.conditional.rules.length ? 'open' : false },
			[ summaryWithCount( i18n.logic, field.conditional.rules.length ), header, list, el( 'p', {}, [ add ] ) ]
		);
	};

	/**
	 * The location rule builder: OR groups of AND rules.
	 *
	 * @param {HTMLElement} root Builder root.
	 * @constructor
	 */
	function LocationBuilder( root ) {
		Builder.call( this, root );

		var builder = this;

		this.buildSummary();

		root.querySelector( '[data-wpcmb-add-group]' ).addEventListener( 'click', function () {
			builder.items.push( [ builder.blankRule() ] );
			builder.render();
		} );

		this.render();
	}

	LocationBuilder.prototype = Object.create( Builder.prototype );
	LocationBuilder.prototype.constructor = LocationBuilder;

	LocationBuilder.prototype.render = function () {
		Builder.prototype.render.call( this );

		// A group with no rules never appears anywhere, which is the single
		// thing people most often miss. Say so rather than showing a blank.
		if ( ! this.items.length ) {
			this.list.appendChild( emptyState( i18n.noRulesYet, i18n.noRulesHint ) );
		}

		this.refreshSummary();
	};

	/**
	 * Build the summary line above the rule list, once.
	 *
	 * Rules read as an OR list of AND sets, which is the part people get
	 * wrong. A sentence saying where the group will actually appear answers
	 * that without asking anyone to reason about the nesting.
	 */
	LocationBuilder.prototype.buildSummary = function () {
		this.summary = el( 'p', { class: 'wpcmb-location-summary' } );
		this.list.parentNode.insertBefore( this.summary, this.list );
	};

	/**
	 * Restate the current rules in plain language.
	 *
	 * Mirrors Locations::describe(), which writes the same sentence for the
	 * field group list, so the editor and the list agree on what a rule set
	 * means.
	 */
	LocationBuilder.prototype.refreshSummary = function () {
		if ( ! this.summary ) {
			return;
		}

		var builder = this;

		var groups = this.items.map( function ( rules ) {
			return rules.map( function ( rule ) {
				// "is" / "is not", matching Locations::describe(), rather than
				// the dropdown's "is equal to" — the sentence has to read the
				// same here as it does in the field group list.
				return [
					builder.paramLabel( rule.param ),
					'!=' === rule.operator ? i18n.summaryIsNot : i18n.summaryIs,
					builder.valueLabel( rule ),
				].join( ' ' );
			} ).join( ' ' + i18n.andLabel + ' ' );
		} ).filter( Boolean );

		this.summary.textContent = groups.length
			? i18n.appearsWhen + ' ' + groups.join( ' ' + i18n.orLabel + ' ' )
			: i18n.appearsNowhere;

		this.summary.classList.toggle( 'is-empty', ! groups.length );
	};

	/**
	 * The human label for a rule parameter.
	 *
	 * @param {string} param Rule parameter.
	 * @return {string} The label.
	 */
	LocationBuilder.prototype.paramLabel = function ( param ) {
		var entry = ( config.locationParams || {} )[ param ];

		return entry ? entry.label : param;
	};

	/**
	 * The human label for a rule's value.
	 *
	 * @param {Object} rule The rule.
	 * @return {string} The label.
	 */
	LocationBuilder.prototype.valueLabel = function ( rule ) {
		var value = rule.value || '';

		if ( '' === value ) {
			return i18n.anythingLabel;
		}

		var choices = ( config.locationChoices || {} )[ rule.param ];

		if ( choices && choices[ value ] ) {
			return choices[ value ];
		}

		// An object rule stores an id; the readable name is whatever the
		// loaded dropdown is showing for it.
		var option = this.list.querySelector(
			'.wpcmb-rule__object option[value="' + String( value ).replace( /"/g, '' ) + '"]'
		);

		return option ? option.textContent : value;
	};

	LocationBuilder.prototype.blankRule = function () {
		var params = Object.keys( config.locationParams || {} );

		return { param: params[ 0 ] || 'post_type', operator: '==', value: '' };
	};

	/**
	 * Group the rule parameters into optgroups for the select.
	 *
	 * @return {Object} Labels keyed by parameter, grouped.
	 */
	LocationBuilder.prototype.paramOptions = function () {
		var grouped = {};

		Object.keys( config.locationParams || {} ).forEach( function ( param ) {
			var entry = config.locationParams[ param ];
			grouped[ entry.group ] = grouped[ entry.group ] || {};
			grouped[ entry.group ][ param ] = entry.label;
		} );

		return grouped;
	};

	/**
	 * The value control for a rule: a select when the parameter has a known
	 * set of values, a text input otherwise.
	 *
	 * @param {Object} rule Rule object.
	 * @return {HTMLElement} The control.
	 */
	LocationBuilder.prototype.valueControl = function ( rule ) {
		// A parameter pointing at a specific object is searched rather than
		// listed, so the rule builder never has to carry every post on the
		// site just in case somebody uses this rule.
		if ( ( config.locationObjects || {} )[ rule.param ] ) {
			return this.objectControl( rule );
		}

		var choices = ( config.locationChoices || {} )[ rule.param ];

		if ( choices && Object.keys( choices ).length ) {
			return this.bind( select( choices, rule.value, false ), rule, 'value' );
		}

		return this.bind(
			el( 'input', {
				type: 'text',
				class: 'wpcmb-field-row__control',
				value: rule.value || '',
				placeholder: i18n.idOrSlug,
			} ),
			rule,
			'value'
		);
	};

	/**
	 * A search box and a dropdown, filled from the site's own content.
	 *
	 * The dropdown is the value; the search box only narrows what it offers.
	 * A rule that already points at something keeps it selected even when it
	 * falls outside the results, so opening a group never silently repoints
	 * a rule at whatever happened to come first.
	 *
	 * @param {Object} rule The rule.
	 * @return {HTMLElement} The control.
	 */
	LocationBuilder.prototype.objectControl = function ( rule ) {
		var builder = this;
		var settings = config.locationObjects[ rule.param ] || {};

		var list = el( 'select', { class: 'wpcmb-field-row__control' } );
		var status = el( 'span', { class: 'wpcmb-rule__status', text: i18n.searching } );

		var search = el( 'input', {
			type: 'search',
			class: 'wpcmb-field-row__control wpcmb-rule__search',
			placeholder: settings.label || i18n.typeToSearch,
			'aria-label': settings.label || i18n.typeToSearch,
		} );

		/**
		 * Replace the dropdown's options.
		 *
		 * @param {Array} results Objects from the server.
		 */
		function fill( results ) {
			list.textContent = '';
			list.appendChild( el( 'option', { value: '', text: i18n.chooseOne } ) );

			results.forEach( function ( result ) {
				var option = el( 'option', { value: result.value, text: result.label } );
				option.selected = String( result.value ) === String( rule.value || '' );
				list.appendChild( option );
			} );

			status.textContent = results.length ? '' : i18n.noMatches;
		}

		/**
		 * Ask the server for matches.
		 *
		 * @param {string} term Search term.
		 */
		function load( term ) {
			status.textContent = i18n.searching;

			var body = new FormData();
			body.append( 'action', 'wpcmb_location_search' );
			body.append( 'nonce', config.nonce );
			body.append( 'param', rule.param );
			body.append( 'search', term || '' );

			// Sent so the server can keep the current value in the list even
			// when the search would not have returned it.
			body.append( 'value', rule.value || '' );

			window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( result && result.success ) {
						fill( result.data.results );
						return;
					}

					status.textContent = i18n.searchFailed;
				} )
				.catch( function () {
					status.textContent = i18n.searchFailed;
				} );
		}

		this.bind( list, rule, 'value' );

		// Debounced so typing does not fire a request per keystroke.
		var timer = null;

		search.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				load( search.value );
			}, 250 );
		} );

		load( '' );

		return el( 'span', { class: 'wpcmb-rule__object' }, [ search, list, status ] );
	};

	LocationBuilder.prototype.renderItem = function ( rules, groupIndex ) {
		var builder = this;

		var body = el( 'div', { class: 'wpcmb-rule-group__rules' } );

		rules.forEach( function ( rule, ruleIndex ) {
			var paramSelect = builder.bind(
				select( builder.paramOptions(), rule.param, true ),
				rule,
				'param',
				function () {
					rule.value = '';
					builder.render();
				}
			);

			var operatorSelect = builder.bind(
				select( { '==': i18n.isEqual, '!=': i18n.isNotEqual }, rule.operator, false ),
				rule,
				'operator'
			);

			var remove = el( 'button', {
				type: 'button',
				class: 'wpcmb-rule__remove',
				'aria-label': i18n.removeRule,
				title: i18n.removeRule,
				text: '×',
			} );

			remove.addEventListener( 'click', function () {
				rules.splice( ruleIndex, 1 );

				if ( ! rules.length ) {
					builder.items.splice( groupIndex, 1 );
				}

				builder.render();
			} );

			var entry = ( config.locationParams || {} )[ rule.param ] || {};

			var icon = el( 'span', {
				class: 'wpcmb-rule__icon dashicons ' + ( entry.icon || 'dashicons-admin-generic' ),
				'aria-hidden': 'true',
			} );

			// Rules inside a group are ANDed. Marking every rule after the
			// first shows that without asking anyone to infer it from nesting.
			var joiner = el( 'span', {
				class: 'wpcmb-rule__joiner',
				text: ruleIndex ? i18n.andLabel || 'and' : '',
			} );

			joiner.setAttribute( 'aria-hidden', ruleIndex ? 'false' : 'true' );

			paramSelect.classList.add( 'wpcmb-rule__param' );
			operatorSelect.classList.add( 'wpcmb-rule__operator' );

			body.appendChild(
				el( 'div', { class: 'wpcmb-rule' + ( ruleIndex ? ' is-joined' : '' ) }, [
					joiner,
					icon,
					paramSelect,
					operatorSelect,
					builder.valueControl( rule ),
					remove,
				] )
			);
		} );

		var add = el( 'button', { type: 'button', class: 'wpcmb-rule__add', text: i18n.addRule } );

		add.addEventListener( 'click', function () {
			rules.push( builder.blankRule() );
			builder.render();
		} );

		var duplicateGroup = el( 'button', {
			type: 'button',
			class: 'wpcmb-rule-group__action',
			'aria-label': i18n.duplicateGroup,
			title: i18n.duplicateGroup,
			text: '⧉',
		} );

		duplicateGroup.addEventListener( 'click', function () {
			builder.items.splice( groupIndex + 1, 0, JSON.parse( JSON.stringify( rules ) ) );
			builder.render();
		} );

		var removeGroup = el( 'button', {
			type: 'button',
			class: 'wpcmb-rule-group__action wpcmb-rule-group__action--delete',
			'aria-label': i18n.removeGroup,
			title: i18n.removeGroup,
			text: '×',
		} );

		removeGroup.addEventListener( 'click', function () {
			builder.items.splice( groupIndex, 1 );
			builder.render();
		} );

		var header = el( 'div', { class: 'wpcmb-rule-group__header' }, [
			el( 'span', {
				class: 'wpcmb-rule-group__title',
				text: i18n.ruleGroup.replace( '%d', String( groupIndex + 1 ) ),
			} ),
			el( 'span', { class: 'wpcmb-rule-group__buttons' }, [ duplicateGroup, removeGroup ] ),
		] );

		return el( 'div', { class: 'wpcmb-rule-group' }, [
			groupIndex ? el( 'div', { class: 'wpcmb-rule-group__or', text: i18n.orLabel || 'or' } ) : null,
			header,
			body,
			el( 'p', { class: 'wpcmb-rule-group__actions' }, [ add ] ),
		] );
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-wpcmb-builder]' ).forEach( function ( root ) {
			if ( 'fields' === root.dataset.wpcmbBuilder ) {
				new FieldsBuilder( root );
			} else {
				new LocationBuilder( root );
			}
		} );
	} );
}( window.jQuery ) );
