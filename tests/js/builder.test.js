/**
 * Browser tests for assets/js/builder.js.
 *
 * The builder is the screen a site owner spends the most time on, and it
 * writes the JSON the whole plugin is configured from. What matters here is
 * that the state it posts is the shape FieldGroup::sanitize() expects.
 *
 * @package WPCMB
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { setup, jqueryStub } = require( './harness' );

const globals = {
	wpcmbBuilder: {
		locationParams: {
			post_type: { label: 'Post Type', icon: 'dashicons-admin-post', group: 'Post' },
			page_template: { label: 'Page Template', icon: 'dashicons-layout', group: 'Page' },
		},
		locationChoices: {
			post_type: { post: 'Posts', page: 'Pages' },
		},
		fieldTypes: {
			Basic: { text: 'Text', number: 'Number', select: 'Select' },
			Layout: { repeater: 'Repeater', flexible_content: 'Flexible Content' },
		},
		// Shaped like Registry::editor_settings(), which stamps a tab on every
		// setting before it reaches the browser.
		fieldSettings: {
			number: {
				min: { label: 'Minimum', type: 'number', tab: 'validation' },
				step: { label: 'Step', type: 'text', help: 'A step value.', tab: 'validation' },
				return_format: { label: 'Return format', type: 'text', tab: 'advanced' },
			},
			// Shaped like Choice::settings_schema( 'select' ). The toggle is
			// what "Allow multiple" is, on both the choice and the
			// relationship types.
			select: {
				choices: { label: 'Choices', type: 'choices', tab: 'general' },
				multiple: { label: 'Allow multiple', type: 'toggle', tab: 'advanced' },
			},
		},
		fieldIcons: { text: 'dashicons-edit', number: 'dashicons-calculator', repeater: 'dashicons-controls-repeat', flexible_content: 'dashicons-layout' },
		subFieldTypes: [ 'repeater', 'group' ],
		layoutFieldTypes: [ 'flexible_content' ],
		i18n: {
			confirmRemove: 'Remove this field?',
			confirmRemoveLayout: 'Remove this layout?',
			newField: 'New Field',
			orLabel: 'or',
			andLabel: 'and',
			nameRequired: 'Every field needs a name.',
			duplicateName: 'Field names must be unique within a group.',
			label: 'Label',
			name: 'Name',
			type: 'Type',
			defaultValue: 'Default value',
			placeholder: 'Placeholder',
			width: 'Width (%)',
			instructions: 'Instructions',
			required: 'Required',
			reorder: 'Drag to reorder',
			isEqual: 'is equal to',
			isNotEqual: 'is not equal to',
			idOrSlug: 'ID or slug',
			addRule: '+ Add rule',
			searching: 'Searching…',
			noMatches: 'No matches. Try a different search.',
			searchFailed: 'The search failed. Type an ID instead.',
			chooseOne: '— Choose —',
			typeToSearch: 'Type to search…',
			noFieldsYet: 'No fields yet',
			noFieldsHint: 'Add a field to decide what this group collects.',
			noRulesYet: 'No location rules',
			noRulesHint: 'Without a rule this group stays hidden. Add one to choose where it appears.',
			searchFields: 'Search fields',
			expandAll: 'Expand all',
			collapseAll: 'Collapse all',
			fieldCount: '%d fields',
			fieldCountFiltered: '%1$d of %2$d fields',
			wrapperClass: 'CSS class',
			wrapperId: 'CSS id',
			copyKey: 'Copy field name',
			copiedKey: 'Field name copied',
			fieldKey: 'Field key',
			tabGeneral: 'General',
			tabValidation: 'Validation',
			tabAppearance: 'Appearance',
			tabLogic: 'Logic',
			tabAdvanced: 'Advanced',
			collapseField: 'Collapse field',
			expandField: 'Expand field',
			duplicateField: 'Duplicate field',
			deleteField: 'Delete field',
			copySuffix: '(copy)',
			ruleGroup: 'Rule group %d',
			duplicateGroup: 'Duplicate rule group',
			removeGroup: 'Remove rule group',
			removeRule: 'Remove rule',
			appearsWhen: 'Appears when:',
			appearsNowhere: 'This group has no rules, so it will not appear anywhere.',
			anythingLabel: '(anything)',
			summaryIs: 'is',
			summaryIsNot: 'is not',
			settings: 'Settings',
			logic: 'Conditional logic',
			showThisField: 'Show this field when',
			hideThisField: 'Hide this field when',
			allRules: 'all rules match',
			anyRule: 'any rule matches',
			addCondition: '+ Add condition',
			noOtherFields: 'Add another field first.',
			noSettings: 'No extra settings.',
			subFields: 'Sub fields',
			addSubField: 'Add sub field',
			layouts: 'Layouts',
			addLayout: 'Add layout',
			icon: 'Icon',
			category: 'Category',
			maxPerField: 'Max uses',
			yes: 'Yes',
			no: 'No',
		},
	},
};

/*
 * Every string the script asks for must be defined above. A missing key
 * renders as empty text in the page, which is easy to miss in a test that is
 * only checking structure — this has already happened twice.
 */
test( 'the test globals define every string builder.js reads', () => {
	const used = require( 'node:fs' )
		.readFileSync( require( 'node:path' ).join( __dirname, '..', '..', 'assets', 'js', 'builder.js' ), 'utf8' )
		.match( /i18n\.([A-Za-z]+)/g )
		.map( ( match ) => match.slice( 5 ) );

	const missing = [ ...new Set( used ) ].filter( ( key ) => undefined === globals.wpcmbBuilder.i18n[ key ] );

	assert.deepEqual( missing, [], 'undefined i18n keys render as blank text' );
} );

/**
 * The builder markup, matching what FieldGroupEditor renders.
 *
 * @param {string} fields   Initial fields JSON.
 * @param {string} location Initial location JSON.
 * @return {string} The markup.
 */
function markup( fields = '[]', location = '[]' ) {
	return `
		<div class="wpcmb-builder" data-wpcmb-builder="fields">
			<textarea class="wpcmb-builder__state" name="wpcmb_fields_json" hidden>${ fields }</textarea>
			<div class="wpcmb-builder__list" data-wpcmb-list></div>
			<p class="wpcmb-builder__actions">
				<button type="button" data-wpcmb-add-field>Add Field</button>
			</p>
		</div>
		<div class="wpcmb-builder" data-wpcmb-builder="location">
			<textarea class="wpcmb-builder__state" name="wpcmb_location_json" hidden>${ location }</textarea>
			<div class="wpcmb-builder__list" data-wpcmb-list></div>
			<p class="wpcmb-builder__actions">
				<button type="button" data-wpcmb-add-group>Add Rule Group</button>
			</p>
		</div>`;
}

/**
 * A builder page.
 *
 * @param {string} fields   Initial fields JSON.
 * @param {string} location Initial location JSON.
 * @return {Object} The harness.
 */
function builderPage( fields, location ) {
	const page = setup( {
		html: markup( fields, location ),
		scripts: [ 'builder.js' ],
		globals: Object.assign( {}, globals, { jQuery: jqueryStub() } ),
	} );

	/**
	 * The fields state, as the form would post it.
	 *
	 * @return {Array} Field definitions.
	 */
	page.fields = () => JSON.parse( page.$( '[name="wpcmb_fields_json"]' ).value );

	/**
	 * The location state, as the form would post it.
	 *
	 * @return {Array} Rule groups.
	 */
	page.location = () => JSON.parse( page.$( '[name="wpcmb_location_json"]' ).value );

	page.addField = () => page.click( page.$( '[data-wpcmb-add-field]' ) );

	return page;
}

test( 'adding a field produces a row and a state entry', () => {
	const page = builderPage();

	page.addField();

	assert.equal( page.$$( '.wpcmb-builder[data-wpcmb-builder="fields"] .wpcmb-field-row' ).length, 1 );

	const fields = page.fields();

	assert.equal( fields.length, 1 );
	assert.match( fields[ 0 ].key, /^field_[a-z0-9]{13}$/, 'a key is generated in the shape PHP accepts' );
	assert.equal( fields[ 0 ].type, 'text', 'a new field defaults to text' );
} );

test( 'typing a label fills in the name the same way PHP would', () => {
	const page = builderPage();

	page.addField();

	const row = page.$( '.wpcmb-field-row' );
	const label = row.querySelector( '[data-wpcmb-focus]' );

	page.fill( label, 'First Name' );

	// This must match FieldGroup::sanitize_field_name(). If the two disagree,
	// the field is silently renamed on save and its stored values orphaned.
	assert.equal( page.fields()[ 0 ].name, 'first_name', 'spaces become underscores, not nothing' );
	assert.equal( page.fields()[ 0 ].label, 'First Name' );
} );

test( 'an edited name is not overwritten by later label changes', () => {
	const page = builderPage();

	page.addField();

	const row = page.$( '.wpcmb-field-row' );
	const inputs = row.querySelectorAll( 'input[type="text"]' );

	page.fill( inputs[ 0 ], 'First Name' );
	page.fill( inputs[ 1 ], 'custom_name' );
	page.fill( inputs[ 0 ], 'Something Else' );

	assert.equal( page.fields()[ 0 ].name, 'custom_name', 'a deliberate name wins' );
} );

test( 'duplicate names are reported', () => {
	const page = builderPage();

	page.addField();
	page.addField();

	const labels = page.$$( '.wpcmb-field-row [data-wpcmb-focus]' );

	page.fill( labels[ 0 ], 'Title' );
	page.fill( labels[ 1 ], 'Title' );

	const notice = page.$( '.wpcmb-builder__notice' );

	assert.equal( notice.hidden, false, 'the collision is visible' );
	assert.match( notice.textContent, /unique/i );
} );

test( 'a field with no name is reported', () => {
	const page = builderPage();

	page.addField();

	assert.match( page.$( '.wpcmb-builder__notice' ).textContent, /needs a name/i );
} );

test( 'changing the type clears settings that belonged to the old type', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'n', label: 'N', type: 'number', settings: { min: '5' } } ] )
	);

	assert.equal( page.fields()[ 0 ].settings.min, '5', 'the setting starts out present' );

	const select = page.$( '.wpcmb-field-row select' );
	select.value = 'text';
	select.dispatchEvent( new page.window.Event( 'input', { bubbles: true } ) );

	// Settings belong to a type. Keeping them would silently apply a number's
	// minimum to a text field that has no such option.
	assert.deepEqual( page.fields()[ 0 ].settings, {}, 'the old type\'s settings are dropped' );
	assert.equal( page.fields()[ 0 ].type, 'text' );
} );

test( 'per-type settings appear on the tab the server assigned them', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'n', label: 'N', type: 'number', settings: {} } ] )
	);

	const tabs = page.$$( '.wpcmb-tabset__tab' ).map( ( t ) => t.textContent );

	assert.deepEqual(
		tabs,
		[ 'General', 'Validation', 'Appearance', 'Logic', 'Advanced' ],
		'settings are split across tabs rather than stacked'
	);

	// Looked up by tab name, so adding a tab later cannot silently move what
	// this test is actually asserting.
	const panelFor = ( name ) => {
		const tab = page.$$( '.wpcmb-tabset__tab' ).find( ( t ) => name === t.textContent );

		return page.$( '#' + tab.getAttribute( 'aria-controls' ) );
	};

	const labelsIn = ( panel ) => Array.from( panel.querySelectorAll( '.wpcmb-label' ) ).map( ( l ) => l.textContent );

	assert.ok( labelsIn( panelFor( 'Validation' ) ).includes( 'Minimum' ), 'min is on Validation' );
	assert.ok( labelsIn( panelFor( 'Validation' ) ).includes( 'Step' ), 'step is on Validation' );
	assert.ok( labelsIn( panelFor( 'Advanced' ) ).includes( 'Return format' ), 'return format is on Advanced' );
	assert.ok( labelsIn( panelFor( 'General' ) ).includes( 'Label' ), 'the basics stay on General' );
} );

test( 'only the first tab panel is shown, and arrow keys move between tabs', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'n', label: 'N', type: 'number', settings: {} } ] )
	);

	const tabs = page.$$( '.wpcmb-tabset__tab' );
	const panels = page.$$( '.wpcmb-tabset__panel' );

	assert.equal( panels[ 0 ].hidden, false );
	assert.equal( panels[ 1 ].hidden, true );

	// One stop in the tab order for the whole set, per the ARIA tabs pattern.
	assert.equal( tabs[ 0 ].getAttribute( 'tabindex' ), '0' );
	assert.equal( tabs[ 1 ].getAttribute( 'tabindex' ), '-1' );

	tabs[ 0 ].dispatchEvent( new page.window.KeyboardEvent( 'keydown', { key: 'ArrowRight', bubbles: true } ) );

	assert.equal( panels[ 1 ].hidden, false, 'the next panel opened' );
	assert.equal( tabs[ 1 ].getAttribute( 'aria-selected' ), 'true' );

	tabs[ 1 ].dispatchEvent( new page.window.KeyboardEvent( 'keydown', { key: 'End', bubbles: true } ) );

	assert.equal( panels[ panels.length - 1 ].hidden, false, 'End jumps to the last tab' );
} );

test( 'a field row shows an icon and a required marker', () => {
	const page = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'a', label: 'A', type: 'number', required: true, settings: {} },
			{ key: 'field_b000000001', name: 'b', label: 'B', type: 'text', required: false, settings: {} },
		] )
	);

	const icons = page.$$( '.wpcmb-field-row__icon' );

	assert.equal( icons.length, 2 );
	assert.ok( icons[ 0 ].className.includes( 'dashicons-calculator' ), 'the icon comes from the server map' );

	const markers = page.$$( '.wpcmb-field-row__required' );

	assert.equal( markers[ 0 ].hidden, false, 'a required field is marked' );
	assert.equal( markers[ 1 ].hidden, true, 'an optional one is not' );
} );

test( 'duplicating a field copies it with fresh keys and no name', () => {
	const page = builderPage(
		JSON.stringify( [ {
			key: 'field_a000000001', name: 'original', label: 'Original', type: 'repeater', settings: {},
			sub_fields: [ { key: 'field_s000000001', name: 's', label: 'S', type: 'text', settings: {} } ],
		} ] )
	);

	page.click( page.$( '.wpcmb-field-row__duplicate' ) );

	const fields = page.fields();

	assert.equal( fields.length, 2, 'the copy sits next to the original' );
	assert.notEqual( fields[ 1 ].key, fields[ 0 ].key, 'the copy gets its own key' );
	assert.notEqual(
		fields[ 1 ].sub_fields[ 0 ].key,
		fields[ 0 ].sub_fields[ 0 ].key,
		'and so does everything inside it'
	);

	// Two fields sharing a name overwrite each other's stored values, so the
	// copy starts unnamed and the duplicate-name notice asks for one.
	assert.equal( fields[ 1 ].name, '', 'the copy has no name yet' );
	assert.match( fields[ 1 ].label, /copy/ );
	assert.equal( fields[ 0 ].name, 'original', 'the original is untouched' );
} );

test( 'the toolbar counts fields and filters them without dropping any', () => {
	const page = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'heading', label: 'Heading', type: 'text', settings: {} },
			{ key: 'field_b000000001', name: 'byline', label: 'Byline', type: 'text', settings: {} },
			{ key: 'field_c000000001', name: 'total', label: 'Total', type: 'number', settings: {} },
		] )
	);

	assert.match( page.$( '.wpcmb-toolbar__count' ).textContent, /3 fields/ );

	page.fill( page.$( '.wpcmb-toolbar__search' ), 'head' );

	const visible = page.$$( '.wpcmb-field-row' ).filter( ( row ) => ! row.hidden );

	assert.equal( visible.length, 1, 'only the match is shown' );
	assert.match( page.$( '.wpcmb-toolbar__count' ).textContent, /1 of 3/ );

	// Filtering hides rows; it must never remove them from what gets posted.
	assert.equal( page.fields().length, 3, 'every field is still in the state' );

	page.fill( page.$( '.wpcmb-toolbar__search' ), '' );

	assert.equal( page.$$( '.wpcmb-field-row' ).filter( ( r ) => ! r.hidden ).length, 3 );
} );

test( 'search matches a field type as well as its label and name', () => {
	const page = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'heading', label: 'Heading', type: 'text', settings: {} },
			{ key: 'field_c000000001', name: 'total', label: 'Total', type: 'number', settings: {} },
		] )
	);

	page.fill( page.$( '.wpcmb-toolbar__search' ), 'number' );

	const visible = page.$$( '.wpcmb-field-row' ).filter( ( row ) => ! row.hidden );

	assert.equal( visible.length, 1 );
	assert.match( visible[ 0 ].textContent, /Total/ );
} );

test( 'collapse all and expand all act on every row', () => {
	const page = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'a', label: 'A', type: 'text', settings: {} },
			{ key: 'field_b000000001', name: 'b', label: 'B', type: 'text', settings: {} },
		] )
	);

	const collapseAll = page.$$( '.wpcmb-toolbar__button' ).find( ( b ) => 'Collapse all' === b.textContent );
	const expandAll = page.$$( '.wpcmb-toolbar__button' ).find( ( b ) => 'Expand all' === b.textContent );

	page.click( collapseAll );

	assert.equal( page.$$( '.wpcmb-field-row.is-collapsed' ).length, 2 );

	page.click( expandAll );

	assert.equal( page.$$( '.wpcmb-field-row.is-collapsed' ).length, 0 );
} );

test( 'editing a setting writes it into the state', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'n', label: 'N', type: 'number', settings: {} } ] )
	);

	const min = page.$$( '.wpcmb-field-row input[type="number"]' ).find(
		( input ) => 'Minimum' === input.previousElementSibling?.textContent
	);

	assert.ok( min, 'the Minimum control exists' );

	page.fill( min, '3' );

	assert.equal( page.fields()[ 0 ].settings.min, '3' );
} );

test( 'removing a field takes it out of the state', () => {
	const page = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'one', label: 'One', type: 'text', settings: {} },
			{ key: 'field_b000000001', name: 'two', label: 'Two', type: 'text', settings: {} },
		] )
	);

	page.window.confirm = () => true;

	page.click( page.$$( '.wpcmb-field-row__remove' )[ 0 ] );

	assert.deepEqual( page.fields().map( ( f ) => f.name ), [ 'two' ] );
} );

test( 'declining the confirmation keeps the field', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'one', label: 'One', type: 'text', settings: {} } ] )
	);

	page.window.confirm = () => false;

	page.click( page.$( '.wpcmb-field-row__remove' ) );

	assert.equal( page.fields().length, 1, 'nothing was removed' );
} );

test( 'a repeater gets a nested field list that writes to sub_fields', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_r000000001', name: 'rows', label: 'Rows', type: 'repeater', settings: {} } ] )
	);

	const summaries = page.$$( '.wpcmb-subsection > summary' ).map( ( s ) => s.textContent );

	assert.ok( summaries.includes( 'Sub fields' ), 'a repeater offers sub fields' );

	const addSub = page.$$( 'button' ).find( ( b ) => 'Add sub field' === b.textContent );
	page.click( addSub );

	const fields = page.fields();

	assert.equal( fields[ 0 ].sub_fields.length, 1, 'the sub field landed in the parent field' );
	assert.match( fields[ 0 ].sub_fields[ 0 ].key, /^field_[a-z0-9]{13}$/ );
} );

test( 'a plain text field is offered no sub fields', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'n', label: 'N', type: 'text', settings: {} } ] )
	);

	const summaries = page.$$( '.wpcmb-subsection > summary' ).map( ( s ) => s.textContent );

	assert.equal( summaries.includes( 'Sub fields' ), false );
} );

test( 'flexible content gets a layout editor', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_f000000001', name: 'sections', label: 'Sections', type: 'flexible_content', settings: {} } ] )
	);

	const summaries = page.$$( '.wpcmb-subsection > summary' ).map( ( s ) => s.textContent );

	assert.ok( summaries.includes( 'Layouts' ) );

	page.click( page.$$( 'button' ).find( ( b ) => 'Add layout' === b.textContent ) );

	const layouts = page.fields()[ 0 ].layouts;

	assert.equal( layouts.length, 1 );
	assert.match( layouts[ 0 ].key, /^layout_[a-z0-9]{13}$/, 'a layout key is generated' );
} );

test( 'a layout name is derived from its label the same way PHP derives it', () => {
	const page = builderPage(
		JSON.stringify( [ {
			key: 'field_f000000001', name: 'sections', label: 'Sections', type: 'flexible_content', settings: {},
			layouts: [ { key: 'layout_a000000001', name: '', label: '', settings: {}, sub_fields: [] } ],
		} ] )
	);

	const labelInput = page.$( '.wpcmb-layout input[type="text"]' );

	page.fill( labelInput, 'Hero Banner' );

	assert.equal( page.fields()[ 0 ].layouts[ 0 ].name, 'hero_banner' );
} );

test( 'conditional logic is offered once another field exists', () => {
	const one = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'one', label: 'One', type: 'text', settings: {} } ] )
	);

	assert.match(
		one.$$( '.wpcmb-subsection' ).map( ( s ) => s.textContent ).join( ' ' ),
		/Add another field first/,
		'a lone field cannot depend on anything'
	);

	const two = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'one', label: 'One', type: 'text', settings: {} },
			{ key: 'field_b000000001', name: 'two', label: 'Two', type: 'text', settings: {} },
		] )
	);

	const addCondition = two.$$( 'button' ).find( ( b ) => '+ Add condition' === b.textContent );

	assert.ok( addCondition, 'the control appears once there is a sibling' );

	two.click( addCondition );

	const conditional = two.fields()[ 0 ].conditional;

	assert.equal( conditional.action, 'show' );
	assert.equal( conditional.logic, 'all' );
	assert.equal( conditional.rules.length, 1 );
	assert.equal( conditional.rules[ 0 ].field, 'field_b000000001', 'the rule points at the sibling by key' );
} );

test( 'the location builder writes OR groups of AND rules', () => {
	const page = builderPage( '[]', '[]' );

	page.click( page.$( '[data-wpcmb-add-group]' ) );

	let location = page.location();

	assert.equal( location.length, 1, 'one rule group' );
	assert.equal( location[ 0 ].length, 1, 'holding one rule' );
	assert.equal( location[ 0 ][ 0 ].param, 'post_type', 'defaulting to the first parameter' );
	assert.equal( location[ 0 ][ 0 ].operator, '==' );

	page.click( page.$$( 'button' ).find( ( b ) => '+ Add rule' === b.textContent ) );

	location = page.location();

	assert.equal( location[ 0 ].length, 2, 'a second rule joins the same group' );
} );

test( 'a rule carries an icon for the kind of thing it matches', () => {
	const page = builderPage(
		'[]',
		JSON.stringify( [ [
			{ param: 'post_type', operator: '==', value: 'page' },
			{ param: 'page_template', operator: '==', value: '' },
		] ] )
	);

	const icons = page.$$( '.wpcmb-rule__icon' );

	assert.equal( icons.length, 2 );
	assert.ok( icons[ 0 ].className.includes( 'dashicons-admin-post' ), 'the icon comes from the server' );
	assert.ok( icons[ 1 ].className.includes( 'dashicons-layout' ) );
} );

test( 'only rules after the first are marked as joined', () => {
	const page = builderPage(
		'[]',
		JSON.stringify( [ [
			{ param: 'post_type', operator: '==', value: 'page' },
			{ param: 'post_type', operator: '==', value: 'post' },
		] ] )
	);

	const rules = page.$$( '.wpcmb-rule' );

	// The first rule has nothing above it to be ANDed with, so it carries
	// neither the joiner text nor the connector.
	assert.equal( rules[ 0 ].classList.contains( 'is-joined' ), false );
	assert.equal( rules[ 1 ].classList.contains( 'is-joined' ), true );

	const joiners = page.$$( '.wpcmb-rule__joiner' );

	assert.equal( joiners[ 0 ].textContent, '' );
	assert.equal( joiners[ 0 ].getAttribute( 'aria-hidden' ), 'true', 'an empty joiner is not announced' );
	assert.equal( joiners[ 1 ].textContent, 'and' );
} );

test( 'a parameter with known values renders a select, not a text box', () => {
	const page = builderPage( '[]', JSON.stringify( [ [ { param: 'post_type', operator: '==', value: '' } ] ] ) );

	const rule = page.$( '.wpcmb-rule' );
	const selects = rule.querySelectorAll( 'select' );

	// param, operator, value — the value control is a select because
	// post_type has a known set of choices.
	assert.equal( selects.length, 3, 'the value control is a select' );
	assert.equal( rule.querySelectorAll( 'input[type="text"]' ).length, 0 );
} );

/* -------------------------------------------------------------------------
 * Affordances that make a long field list readable.
 * ---------------------------------------------------------------------- */

test( 'a collapsed row still says what type the field is', () => {
	const page = builderPage(
		JSON.stringify( [
			{ key: 'field_a000000001', name: 'a', label: 'A', type: 'text', settings: [], conditional: [] },
			{ key: 'field_b000000001', name: 'b', label: 'B', type: 'repeater', settings: [], conditional: [] },
		] )
	);

	// Without this a row is just a name, and a repeater is indistinguishable
	// from a text field until you open it.
	const badges = page.$$( '.wpcmb-field-row__type' ).map( ( b ) => b.textContent );

	assert.deepEqual( badges.slice( 0, 2 ), [ 'Text', 'Repeater' ], 'the human label is shown, not the raw type' );
} );

test( 'an unregistered type still shows something rather than nothing', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_a000000001', name: 'a', label: 'A', type: 'from_an_addon', settings: [], conditional: [] } ] )
	);

	assert.equal( page.$( '.wpcmb-field-row__type' ).textContent, 'from_an_addon' );
} );

test( 'section headings carry a count so a closed section is still informative', () => {
	const page = builderPage(
		JSON.stringify( [ {
			key: 'field_r000000001', name: 'rows', label: 'Rows', type: 'repeater', settings: [], conditional: [],
			sub_fields: [
				{ key: 'field_s100000001', name: 's1', label: 'S1', type: 'text', settings: [], conditional: [] },
				{ key: 'field_s200000001', name: 's2', label: 'S2', type: 'text', settings: [], conditional: [] },
			],
		} ] )
	);

	const subSummary = page.$$( '.wpcmb-subsection > summary' ).find(
		( s ) => s.textContent.startsWith( 'Sub fields' )
	);

	assert.ok( subSummary, 'the sub fields section exists' );
	assert.equal( subSummary.querySelector( '.wpcmb-count' ).textContent, '2' );
} );

test( 'an empty section carries no count rather than a zero', () => {
	const page = builderPage(
		JSON.stringify( [ { key: 'field_r000000001', name: 'rows', label: 'Rows', type: 'repeater', settings: [], conditional: [] } ] )
	);

	const subSummary = page.$$( '.wpcmb-subsection > summary' ).find(
		( s ) => s.textContent.startsWith( 'Sub fields' )
	);

	assert.equal( subSummary.querySelector( '.wpcmb-count' ), null, 'a zero badge would be noise' );
} );

test( 'an empty field list invites the first field instead of showing nothing', () => {
	const page = builderPage( '[]' );

	const empty = page.$( '.wpcmb-builder[data-wpcmb-builder="fields"] .wpcmb-empty' );

	assert.ok( empty, 'a placeholder is shown' );
	assert.match( empty.textContent, /No fields yet/ );
	assert.match( empty.textContent, /Add a field/ );
} );

test( 'an empty rule list explains that the group will not appear', () => {
	const page = builderPage( '[]', '[]' );

	const empty = page.$( '.wpcmb-builder[data-wpcmb-builder="location"] .wpcmb-empty' );

	// This is the mistake people actually make: a group with fields, no
	// rules, and no idea why it never shows up.
	assert.ok( empty, 'a placeholder is shown' );
	assert.match( empty.textContent, /stays hidden/ );
} );

test( 'the placeholder disappears once something is added', () => {
	const page = builderPage( '[]' );

	page.addField();

	assert.equal( page.$( '.wpcmb-builder[data-wpcmb-builder="fields"] .wpcmb-empty' ), null );
} );

/* -------------------------------------------------------------------------
 * Object rules: searched from the site's own content instead of typed.
 * ---------------------------------------------------------------------- */

/**
 * A location builder whose object searches are answered by a stub.
 *
 * @param {Object} rule      The stored rule.
 * @param {Array}  results   What the search returns.
 * @param {Object} [options] `fail: true` to make the request fail.
 * @return {Object} The harness, plus the recorded searches.
 */
function objectRulePage( rule, results, options = {} ) {
	const searches = [];

	const page = setup( {
		html: markup( '[]', JSON.stringify( [ [ rule ] ] ) ),
		scripts: [ 'builder.js' ],
		globals: Object.assign( {}, globals, {
			jQuery: jqueryStub(),
			wpcmbBuilder: Object.assign( {}, globals.wpcmbBuilder, {
				ajaxUrl: 'http://localhost/wp-admin/admin-ajax.php',
				nonce: 'loc-nonce',
				locationObjects: {
					page: { kind: 'post', post_type: 'page', label: 'Search pages' },
				},
			} ),
		} ),
		fetch: ( url, init ) => {
			searches.push( {
				action: init.body.get( 'action' ),
				param: init.body.get( 'param' ),
				search: init.body.get( 'search' ),
				value: init.body.get( 'value' ),
				nonce: init.body.get( 'nonce' ),
			} );

			if ( options.fail ) {
				return Promise.resolve( { json: () => Promise.resolve( { success: false, data: {} } ) } );
			}

			return Promise.resolve( {
				json: () => Promise.resolve( { success: true, data: { results } } ),
			} );
		},
	} );

	page.searches = searches;
	page.location = () => JSON.parse( page.$( '[name="wpcmb_location_json"]' ).value );

	return page;
}

test( 'an object rule offers a dropdown built from the site, not a text box', async () => {
	const page = objectRulePage(
		{ param: 'page', operator: '==', value: '' },
		[ { value: '12', label: 'Home — Page (#12)' }, { value: '15', label: 'About — Page (#15)' } ]
	);

	await page.settle();

	const options = page.$$( '.wpcmb-rule__object select option' ).map( ( o ) => o.textContent );

	assert.ok( page.$( '.wpcmb-rule__object' ), 'the object control is used' );
	assert.deepEqual( options, [ '— Choose —', 'Home — Page (#12)', 'About — Page (#15)' ] );
	assert.equal( page.searches[ 0 ].action, 'wpcmb_location_search' );
	assert.equal( page.searches[ 0 ].param, 'page' );
	assert.equal( page.searches[ 0 ].nonce, 'loc-nonce', 'the request is nonced' );
} );

test( 'choosing an option writes the id into the rule', async () => {
	const page = objectRulePage(
		{ param: 'page', operator: '==', value: '' },
		[ { value: '12', label: 'Home' } ]
	);

	await page.settle();

	const list = page.$( '.wpcmb-rule__object select' );

	page.fill( list, '12' );

	assert.equal( page.location()[ 0 ][ 0 ].value, '12', 'the stored value is the id' );
} );

test( 'a rule already pointing at something keeps it selected', async () => {
	const page = objectRulePage(
		{ param: 'page', operator: '==', value: '15' },
		[ { value: '12', label: 'Home' }, { value: '15', label: 'About' } ]
	);

	await page.settle();

	const list = page.$( '.wpcmb-rule__object select' );

	assert.equal( list.value, '15', 'the existing choice is preselected' );

	// The stored value goes with the request so the server can keep it in
	// the results even when the search would not have returned it.
	assert.equal( page.searches[ 0 ].value, '15' );
	assert.equal( page.location()[ 0 ][ 0 ].value, '15', 'and is not overwritten by loading' );
} );

test( 'typing in the search box re-queries once, not per keystroke', async () => {
	const page = objectRulePage(
		{ param: 'page', operator: '==', value: '' },
		[ { value: '12', label: 'Home' } ]
	);

	await page.settle();

	const initial = page.searches.length;
	const search = page.$( '.wpcmb-rule__search' );

	search.value = 'ab';
	search.dispatchEvent( new page.window.Event( 'input', { bubbles: true } ) );
	search.value = 'abo';
	search.dispatchEvent( new page.window.Event( 'input', { bubbles: true } ) );
	search.value = 'about';
	search.dispatchEvent( new page.window.Event( 'input', { bubbles: true } ) );

	assert.equal( page.searches.length, initial, 'nothing fires while still typing' );

	await new Promise( ( resolve ) => setTimeout( resolve, 320 ) );

	assert.equal( page.searches.length, initial + 1, 'one request after the typing stops' );
	assert.equal( page.searches[ initial ].search, 'about', 'carrying the final term' );
} );

test( 'a search with no matches says so rather than looking broken', async () => {
	const page = objectRulePage( { param: 'page', operator: '==', value: '' }, [] );

	await page.settle();

	assert.match( page.$( '.wpcmb-rule__status' ).textContent, /No matches/ );
} );

test( 'a failed search tells the user they can still type an id', async () => {
	const page = objectRulePage( { param: 'page', operator: '==', value: '' }, [], { fail: true } );

	await page.settle();

	assert.match( page.$( '.wpcmb-rule__status' ).textContent, /failed/i );
} );

test( 'a parameter with no known values falls back to free text', () => {
	const page = builderPage( '[]', JSON.stringify( [ [ { param: 'page_template', operator: '==', value: '' } ] ] ) );

	const rule = page.$( '.wpcmb-rule' );

	assert.equal( rule.querySelectorAll( 'select' ).length, 2, 'only param and operator are selects' );
	assert.equal( rule.querySelectorAll( 'input[type="text"]' ).length, 1, 'the value is typed' );
} );

test( 'the location panel restates the rules in plain language', () => {
	const page = builderPage(
		'[]',
		JSON.stringify( [
			[
				{ param: 'post_type', operator: '==', value: 'page' },
				{ param: 'page_template', operator: '!=', value: 'full-width' },
			],
			[ { param: 'post_type', operator: '==', value: 'post' } ],
		] )
	);

	const summary = page.$( '.wpcmb-location-summary' ).textContent;

	// The same sentence Locations::describe() writes for the list table, so
	// the editor and the list never disagree about what a rule set means.
	assert.match( summary, /Post Type is Pages/ );
	assert.match( summary, / and Page Template is not full-width/ );
	assert.match( summary, / or Post Type is Posts/ );
} );

test( 'the summary says plainly when a group would appear nowhere', () => {
	const page = builderPage( '[]', '[]' );
	const summary = page.$( '.wpcmb-location-summary' );

	assert.match( summary.textContent, /not appear anywhere/ );
	assert.ok( summary.classList.contains( 'is-empty' ) );
} );

test( 'the summary follows an edit, not just a re-render', () => {
	const page = builderPage( '[]', JSON.stringify( [ [ { param: 'post_type', operator: '==', value: 'page' } ] ] ) );

	assert.match( page.$( '.wpcmb-location-summary' ).textContent, /Pages/ );

	const valueSelect = page.$( '.wpcmb-rule select:last-of-type' );

	page.fill( valueSelect, 'post' );

	assert.match( page.$( '.wpcmb-location-summary' ).textContent, /Posts/, 'the sentence keeps up with the controls' );
} );

test( 'a rule group can be duplicated and removed as a whole', () => {
	const page = builderPage( '[]', JSON.stringify( [ [ { param: 'post_type', operator: '==', value: 'page' } ] ] ) );

	page.click( page.$( '.wpcmb-rule-group__action' ) );

	assert.equal( page.location().length, 2, 'the group was duplicated' );
	assert.deepEqual( page.location()[ 1 ], page.location()[ 0 ], 'the copy matches' );

	page.click( page.$$( '.wpcmb-rule-group__action--delete' )[ 0 ] );

	assert.equal( page.location().length, 1, 'a whole group can be removed at once' );
} );

test( 'rule groups are numbered so the OR structure is readable', () => {
	const page = builderPage(
		'[]',
		JSON.stringify( [
			[ { param: 'post_type', operator: '==', value: 'page' } ],
			[ { param: 'post_type', operator: '==', value: 'post' } ],
		] )
	);

	const titles = page.$$( '.wpcmb-rule-group__title' ).map( ( t ) => t.textContent );

	assert.deepEqual( titles, [ 'Rule group 1', 'Rule group 2' ] );
	assert.equal( page.$$( '.wpcmb-rule-group__or' ).length, 1, 'one separator between two groups' );
} );

test( 'removing the last rule in a group removes the group', () => {
	const page = builderPage( '[]', JSON.stringify( [ [ { param: 'post_type', operator: '==', value: 'page' } ] ] ) );

	page.click( page.$( '.wpcmb-rule__remove' ) );

	assert.deepEqual( page.location(), [], 'an empty group is not left behind' );
} );

test( 'existing state is loaded rather than discarded', () => {
	const stored = [
		{ key: 'field_a000000001', name: 'byline', label: 'Byline', type: 'text', settings: {} },
		{ key: 'field_b000000001', name: 'count', label: 'Count', type: 'number', settings: { min: '1' } },
	];

	const page = builderPage( JSON.stringify( stored ) );

	assert.equal( page.$$( '.wpcmb-builder[data-wpcmb-builder="fields"] > .wpcmb-builder__list > .wpcmb-field-row' ).length, 2 );
	assert.deepEqual( page.fields().map( ( f ) => f.name ), [ 'byline', 'count' ] );
	assert.equal( page.fields()[ 1 ].settings.min, '1', 'stored settings survive a round trip' );
} );

/* -------------------------------------------------------------------------
 * PHP encodes an empty associative array as `[]`, not `{}`.
 *
 * These cover a bug found on a real site: a saved group would not render at
 * all. `[]` is truthy in JavaScript, so `field.conditional || {…}` kept the
 * empty array, `.rules` was undefined, and the render loop threw after having
 * already emptied the list — so every field disappeared from the screen.
 *
 * The fixture is the exact JSON that was in the database, not a likeness.
 * ---------------------------------------------------------------------- */

const storedFields = require( 'node:fs' ).readFileSync(
	require( 'node:path' ).join( __dirname, 'fixtures', 'stored-repeater.json' ),
	'utf8'
);

test( 'a saved repeater with sub fields renders instead of blanking the screen', () => {
	const page = builderPage( storedFields );

	const top = page.$$(
		'.wpcmb-builder[data-wpcmb-builder="fields"] > .wpcmb-builder__list > .wpcmb-field-row'
	);

	assert.equal( top.length, 1, 'the repeater is on screen' );

	const subs = page.$$( '.wpcmb-subsection .wpcmb-field-row' );

	assert.equal( subs.length, 2, 'and both sub fields with it' );
} );

test( 'an empty settings array does not swallow the settings that are then typed', () => {
	// The quiet half of the same bug. Writing a named property onto an Array
	// works in memory and vanishes at JSON.stringify, so a setting looked
	// saved and simply was not there afterwards.
	const page = builderPage(
		JSON.stringify( [ {
			key: 'field_a000000001', name: 'n', label: 'N', type: 'number',
			settings: [], conditional: [], wrapper: { width: '', class: '', id: '' },
		} ] )
	);

	assert.equal( Array.isArray( page.fields()[ 0 ].settings ), false, 'settings became an object' );

	const min = page.$$( '.wpcmb-field-row input[type="number"]' ).find(
		( input ) => 'Minimum' === input.previousElementSibling?.textContent
	);

	page.fill( min, '7' );

	assert.equal( page.fields()[ 0 ].settings.min, '7', 'the setting survives being written to state' );

	// The round trip through JSON is the part that used to lose it.
	assert.equal(
		JSON.parse( JSON.stringify( page.fields() ) )[ 0 ].settings.min,
		'7',
		'and survives being encoded for the form'
	);
} );

test( 'stored conditional logic is normalised without losing real rules', () => {
	const page = builderPage(
		JSON.stringify( [
			{
				key: 'field_a000000001', name: 'one', label: 'One', type: 'text', settings: [],
				conditional: {
					action: 'hide', logic: 'any',
					rules: [ { field: 'field_b000000001', operator: '!=', value: 'x' } ],
				},
			},
			{ key: 'field_b000000001', name: 'two', label: 'Two', type: 'text', settings: [], conditional: [] },
		] )
	);

	const stored = page.fields()[ 0 ].conditional;

	assert.equal( stored.action, 'hide', 'a real rule set is left alone' );
	assert.equal( stored.logic, 'any' );
	assert.equal( stored.rules.length, 1 );
	assert.equal( stored.rules[ 0 ].value, 'x' );

	// And the field that had none gets an empty, usable shape.
	assert.deepEqual( page.fields()[ 1 ].conditional.rules, [] );
} );

test( 'an empty layout settings array is normalised too', () => {
	const page = builderPage(
		JSON.stringify( [ {
			key: 'field_f000000001', name: 'sections', label: 'Sections', type: 'flexible_content',
			settings: [], conditional: [],
			layouts: [ { key: 'layout_a000000001', name: 'hero', label: 'Hero', settings: [], sub_fields: [] } ],
		} ] )
	);

	const icon = page.$$( '.wpcmb-layout input[type="text"]' )[ 2 ];

	page.fill( icon, 'dashicons-star-filled' );

	assert.equal(
		JSON.parse( JSON.stringify( page.fields() ) )[ 0 ].layouts[ 0 ].settings.icon,
		'dashicons-star-filled',
		'a layout icon survives the encode'
	);
} );

test( 'malformed stored state degrades to an empty builder rather than throwing', () => {
	assert.doesNotThrow( () => builderPage( 'not json at all' ) );

	const page = builderPage( 'not json at all' );

	assert.deepEqual( page.fields(), [] );
} );

/* -------------------------------------------------------------------------
 * Yes/no settings.
 *
 * "Allow multiple" is a toggle setting, and so are the CSV, accordion,
 * media-button and relationship-multiple settings. They were all built from
 * an options object keyed `{ '': No, 1: Yes }` — and JavaScript enumerates
 * index-like keys first, so the pair came back reversed.
 * ---------------------------------------------------------------------- */

/**
 * A builder page holding one select field, plus its "Allow multiple" control.
 *
 * @param {Object} [settings] Stored settings for the field.
 * @return {Object} The harness and the toggle control.
 */
function multiplePage( settings = {} ) {
	const page = builderPage(
		JSON.stringify( [
			{
				key: 'field_colours00001',
				name: 'colours',
				label: 'Colours',
				type: 'select',
				settings,
				wrapper: {},
				conditional: { action: 'show', logic: 'all', rules: [] },
			},
		] )
	);

	const cell = page
		.$$( '.wpcmb-field-row__cell' )
		.find( ( node ) => node.textContent.includes( 'Allow multiple' ) );

	return { page, cell, control: cell && cell.querySelector( 'select' ) };
}

test( 'the Allow multiple setting is offered for a select field', () => {
	const { control } = multiplePage();

	assert.ok( control, 'the control is rendered' );
} );

test( 'a yes/no setting lists No before Yes', () => {
	const { control } = multiplePage();

	// Not cosmetic: with no option matching an unset setting the browser
	// selects the first one, so a reversed list decides the default.
	assert.deepEqual(
		Array.from( control.options ).map( ( option ) => option.value ),
		[ '', '1' ]
	);
} );

test( 'an unset Allow multiple shows No, matching how the server reads it', () => {
	const { page, control } = multiplePage();

	assert.equal( control.value, '', 'the control shows off' );
	assert.equal(
		page.fields()[ 0 ].settings.multiple,
		undefined,
		'and the state agrees rather than claiming a value nobody chose'
	);
} );

test( 'turning Allow multiple on is written to the posted state', () => {
	const { page, control } = multiplePage();

	page.fill( control, '1' );

	assert.equal( page.fields()[ 0 ].settings.multiple, '1' );
} );

test( 'a stored Allow multiple comes back on', () => {
	const { page, control } = multiplePage( { multiple: '1' } );

	assert.equal( control.value, '1', 'the saved setting is reflected' );
	assert.equal( page.fields()[ 0 ].settings.multiple, '1', 'and survives a render untouched' );
} );

test( 'a stored no stays off rather than reading as a truthy string', () => {
	// PHP round-trips a "no" as the string '0', which is truthy in JavaScript.
	const { control } = multiplePage( { multiple: '0' } );

	assert.equal( control.value, '' );
} );

/* -------------------------------------------------------------------------
 * The field-name chip.
 * ---------------------------------------------------------------------- */

test( 'the field name is a real button, not decorative text', () => {
	const page = builderPage();

	page.addField();

	const chip = page.$( '.wpcmb-field-row__key' );

	// A button rather than a styled <code>: it does something, so it belongs
	// in the tab order and needs an accessible name.
	assert.equal( chip.tagName, 'BUTTON' );
	assert.equal( chip.type, 'button', 'and never submits the form it sits in' );
	assert.equal( chip.getAttribute( 'aria-label' ), 'Copy field name' );
} );

test( 'clicking the field name copies it', async () => {
	const page = builderPage();
	let copied = null;

	page.window.navigator.clipboard = {
		writeText: ( text ) => {
			copied = text;

			return Promise.resolve();
		},
	};

	page.addField();
	page.fill( page.$( '.wpcmb-field-row__body input[type="text"]' ), 'Hero heading' );

	const chip = page.$( '.wpcmb-field-row__key' );

	page.click( chip );

	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	assert.equal( copied, 'hero_heading', 'the stored name is what lands on the clipboard' );
	assert.ok( chip.classList.contains( 'is-copied' ), 'and the chip confirms it' );
	assert.equal(
		chip.getAttribute( 'aria-label' ),
		'Field name copied',
		'announced too, not only shown'
	);
} );

test( 'a clipboard the browser will not give us is not reported as a copy', () => {
	// The Clipboard API needs a secure context. An admin on plain http has no
	// navigator.clipboard at all, and claiming a copy that did not happen is
	// worse than staying quiet — the value is still on screen to select.
	const page = builderPage();

	page.addField();

	const chip = page.$( '.wpcmb-field-row__key' );

	assert.doesNotThrow( () => page.click( chip ) );
	assert.equal( chip.classList.contains( 'is-copied' ), false );
} );
