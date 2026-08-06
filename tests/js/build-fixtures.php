<?php
/**
 * Generate browser-test fixtures from the real renderer.
 *
 * The fixtures are actual production markup, not a hand-written likeness:
 * a test written against an approximation passes while the real page is
 * broken, which is the exact failure the browser tests exist to catch.
 *
 * Run with `php tests/js/build-fixtures.php` after changing the renderer.
 * The generated files are committed, so `npm test` needs no WordPress.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A developer-run CLI script.

$wpcmb_root = dirname( __DIR__, 5 );

if ( ! is_readable( $wpcmb_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php above the plugin.\n" );
	exit( 1 );
}

define( 'WP_ADMIN', true );
require_once $wpcmb_root . '/wp-load.php';

wp_set_current_user( 1 );

$wpcmb_container = wpcmb()->container();
$wpcmb_renderer  = $wpcmb_container->get( WPCMB\Fields\Renderer::class );
$wpcmb_out       = __DIR__ . '/fixtures';

if ( ! is_dir( $wpcmb_out ) ) {
	mkdir( $wpcmb_out, 0755, true );
}

/**
 * Render a group configuration to markup, without touching the database.
 *
 * @param array<string, mixed> $config Group configuration.
 *
 * @return string
 */
function wpcmb_fixture( array $config ): string {
	global $wpcmb_renderer;

	$group = WPCMB\Fields\FieldGroup::from_array( WPCMB\Fields\FieldGroup::sanitize( $config ) );

	ob_start();
	$wpcmb_renderer->group( $group, new WPCMB\Fields\ObjectRef( 'post', 0 ) );

	return (string) ob_get_clean();
}

$wpcmb_fixtures = array();

/*
 * Conditional logic: a toggle that reveals a required text field, and a
 * second field hidden by the same toggle.
 */
$wpcmb_fixtures['conditional'] = wpcmb_fixture(
	array(
		'title'  => 'Conditional',
		'fields' => array(
			array(
				'key'      => 'field_toggle00001',
				'name'     => 'show_more',
				'label'    => 'Show more',
				'type'     => 'toggle',
			),
			array(
				'key'         => 'field_extra000001',
				'name'        => 'extra',
				'label'       => 'Extra',
				'type'        => 'text',
				'required'    => true,
				'conditional' => array(
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => array( array( 'field' => 'field_toggle00001', 'operator' => '==', 'value' => '1' ) ),
				),
			),
			array(
				'key'         => 'field_hidden00001',
				'name'        => 'inverse',
				'label'       => 'Inverse',
				'type'        => 'text',
				'conditional' => array(
					'action' => 'hide',
					'logic'  => 'all',
					'rules'  => array( array( 'field' => 'field_toggle00001', 'operator' => '==', 'value' => '1' ) ),
				),
			),
			array(
				'key'   => 'field_always00001',
				'name'  => 'always',
				'label' => 'Always',
				'type'  => 'text',
			),
		),
	)
);

/* A repeater with two rows already present, and a nested repeater. */
$wpcmb_fixtures['repeater'] = wpcmb_fixture(
	array(
		'title'  => 'Repeater',
		'fields' => array(
			array(
				'key'        => 'field_team000001',
				'name'       => 'team',
				'label'      => 'Team',
				'type'       => 'repeater',
				'settings'   => array( 'csv' => '1', 'row_label' => 'Member {index}' ),
				'sub_fields' => array(
					array( 'key' => 'field_mname00001', 'name' => 'mname', 'label' => 'Name', 'type' => 'text' ),
					array(
						'key'        => 'field_links000001',
						'name'       => 'links',
						'label'      => 'Links',
						'type'       => 'repeater',
						'sub_fields' => array(
							array( 'key' => 'field_url00000001', 'name' => 'url', 'label' => 'URL', 'type' => 'url' ),
						),
					),
				),
			),
		),
	)
);

/* Tabs, which the script rearranges into panels. */
$wpcmb_fixtures['tabs'] = wpcmb_fixture(
	array(
		'title'  => 'Tabs',
		'fields' => array(
			array( 'key' => 'field_tab100000001', 'name' => 'tab_one', 'label' => 'First', 'type' => 'tab' ),
			array( 'key' => 'field_one000000001', 'name' => 'one', 'label' => 'One', 'type' => 'text' ),
			array( 'key' => 'field_tab200000001', 'name' => 'tab_two', 'label' => 'Second', 'type' => 'tab' ),
			array( 'key' => 'field_two000000001', 'name' => 'two', 'label' => 'Two', 'type' => 'text' ),
		),
	)
);

/* A media field, for the picker. */
$wpcmb_fixtures['media'] = wpcmb_fixture(
	array(
		'title'  => 'Media',
		'fields' => array(
			array( 'key' => 'field_img000000001', 'name' => 'hero', 'label' => 'Hero', 'type' => 'image' ),
		),
	)
);

/* A colour field: a swatch and a hex code that have to stay in step. */
$wpcmb_fixtures['color'] = wpcmb_fixture(
	array(
		'title'  => 'Colour',
		'fields' => array(
			array( 'key' => 'field_shade00000001', 'name' => 'shade', 'label' => 'Shade', 'type' => 'color' ),
		),
	)
);

/* The values the repeater's AJAX endpoint returns for a fresh row. */
$wpcmb_repeater_group = WPCMB\Fields\FieldGroup::from_array(
	WPCMB\Fields\FieldGroup::sanitize(
		array(
			'fields' => array(
				array(
					'key'        => 'field_team000001',
					'name'       => 'team',
					'type'       => 'repeater',
					'sub_fields' => array(
						array( 'key' => 'field_mname00001', 'name' => 'mname', 'label' => 'Name', 'type' => 'text' ),
						array(
							'key'        => 'field_links000001',
							'name'       => 'links',
							'label'      => 'Links',
							'type'       => 'repeater',
							'sub_fields' => array(
								array( 'key' => 'field_url00000001', 'name' => 'url', 'label' => 'URL', 'type' => 'url' ),
							),
						),
					),
				),
			),
		)
	)
);

$wpcmb_repeater_field = $wpcmb_repeater_group->fields[0];
$wpcmb_handler        = $wpcmb_container->get( WPCMB\Fields\Registry::class )->get( 'repeater' );

ob_start();
$wpcmb_handler->render_row(
	$wpcmb_repeater_field,
	$wpcmb_handler->sub_fields( $wpcmb_repeater_field ),
	array(),
	99,
	'wpcmb_values[team]',
	'wpcmb-team'
);
$wpcmb_fixtures['repeater-row'] = (string) ob_get_clean();

/* A front-end form. */
$wpcmb_form_group = wp_insert_post(
	array(
		'post_type'   => 'wpcmb_field_group',
		'post_status' => 'publish',
		'post_title'  => 'Fixture Form',
	)
);

$wpcmb_container->get( WPCMB\Fields\Repository::class )->save(
	(int) $wpcmb_form_group,
	WPCMB\Fields\FieldGroup::sanitize(
		array(
			'title'  => 'Fixture Form',
			'fields' => array(
				array( 'key' => 'field_email0000001', 'name' => 'sender_email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
				array( 'key' => 'field_note00000001', 'name' => 'sender_note', 'label' => 'Note', 'type' => 'textarea' ),
			),
		)
	)
);

$wpcmb_container->get( WPCMB\Fields\Repository::class )->flush();
$wpcmb_saved = $wpcmb_container->get( WPCMB\Fields\Repository::class )->get( (int) $wpcmb_form_group );

$wpcmb_fixtures['form'] = $wpcmb_container->get( WPCMB\Frontend\Form::class )->render(
    array( 'group' => $wpcmb_saved->key, 'guests' => '1', 'action' => 'post' )
);

wp_delete_post( (int) $wpcmb_form_group, true );

foreach ( $wpcmb_fixtures as $wpcmb_name => $wpcmb_html ) {
	file_put_contents( $wpcmb_out . '/' . $wpcmb_name . '.html', $wpcmb_html );
	printf( "%-16s %6d bytes\n", $wpcmb_name, strlen( $wpcmb_html ) );
}

echo "fixtures written to tests/js/fixtures\n";
