<?php
/**
 * Standalone self-check for block settings.
 *
 * Run with `php tests/blocks-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\FieldGroup;

/* -------------------------------------------------------------------------
 * Block settings survive the sanitizer, and only in the shapes allowed.
 * ---------------------------------------------------------------------- */

$clean = FieldGroup::sanitize(
	array(
		'title'    => 'Hero',
		'settings' => array(
			'block_enabled'      => '1',
			'block_name'         => 'Hero Banner',
			'block_icon'         => 'cover-image',
			'block_category'     => 'Layout Things',
			'block_description'  => '<b>A hero</b>',
			'block_keywords'     => 'hero, banner',
			'block_mode'         => 'preview',
			'block_supports'     => array( 'align', 'anchor', 'evil', 'color' ),
			'block_inner_blocks' => '1',
		),
		'fields'   => array( array( 'name' => 'heading', 'type' => 'text' ) ),
	)
);

$settings = $clean['settings'];

assert( true === $settings['block_enabled'], 'a group can opt in to being a block' );

// A block name is part of saved content, so it must be a stable key rather
// than whatever was typed.
assert( 'hero_banner' === $settings['block_name'], 'the block name is a key, got: ' . $settings['block_name'] );

assert( 'cover-image' === $settings['block_icon'], 'the icon survives' );
assert( 'layout_things' === $settings['block_category'], 'the category is a key, got: ' . $settings['block_category'] );
assert( 'A hero' === $settings['block_description'], 'the description is plain text' );
assert( 'preview' === $settings['block_mode'], 'a known mode survives' );
assert( true === $settings['block_inner_blocks'], 'inner blocks can be enabled' );

// Only supports the plugin knows how to map are kept.
assert( array( 'align', 'anchor', 'color' ) === $settings['block_supports'], 'unknown supports are dropped' );

// Unknown modes fall back rather than reaching register_block_type().
$fallback = FieldGroup::sanitize( array( 'settings' => array( 'block_mode' => 'nonsense' ) ) );
assert( 'auto' === $fallback['settings']['block_mode'], 'an unknown block mode falls back to auto' );

// A group that says nothing about blocks is not a block.
$plain = FieldGroup::sanitize( array( 'title' => 'Ordinary' ) );
assert( false === $plain['settings']['block_enabled'], 'a group is not a block unless it says so' );
assert( '' === $plain['settings']['block_name'], 'a group without a block name has none' );
assert( array() === $plain['settings']['block_supports'], 'no supports by default' );

// Every block setting is present after sanitizing, so registration never has
// to check whether a key exists.
foreach ( array_keys( FieldGroup::default_settings() ) as $key ) {
	assert( array_key_exists( $key, $plain['settings'] ), "sanitizing fills in {$key}" );
}

/* -------------------------------------------------------------------------
 * A block must name itself. Deriving the name from the title would mean a
 * rename silently orphans every block already placed in content.
 * ---------------------------------------------------------------------- */

$unnamed = FieldGroup::sanitize(
	array(
		'title'    => 'Some Title',
		'settings' => array( 'block_enabled' => '1' ),
	)
);

assert( '' === $unnamed['settings']['block_name'], 'the block name is never derived from the title' );

/* -------------------------------------------------------------------------
 * Round trip through the model.
 * ---------------------------------------------------------------------- */

$group = FieldGroup::from_array( $clean );

assert( 'hero_banner' === $group->settings['block_name'], 'the model keeps the block name' );
assert( true === $group->settings['block_inner_blocks'], 'the model keeps the inner blocks setting' );

// Settings a code-registered group omits still come back with defaults.
$registered = FieldGroup::from_array( array( 'key' => 'group_x0000000001', 'settings' => array( 'block_enabled' => true ) ) );
assert( 'auto' === $registered->settings['block_mode'], 'defaults fill in for a partial settings array' );

echo "blocks-check: OK\n";
