<?php
/**
 * Uninstall routine.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Data removal is opt-in. Deleting a plugin to reinstall it is common and
 * silently dropping field groups would be unrecoverable.
 */
if ( ! get_option( 'wpcmb_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$wpcmb_groups = get_posts(
	array(
		'post_type'      => 'wpcmb_field_group',
		'post_status'    => 'any',
		'posts_per_page' => -1,
	)
);

/*
 * Collect every field name before deleting the groups that define them.
 * After the groups are gone there is no record of which meta keys belonged
 * to this plugin, so the order here matters.
 */
$wpcmb_names = array();

foreach ( $wpcmb_groups as $wpcmb_group ) {
	$wpcmb_config = json_decode( (string) get_post_meta( $wpcmb_group->ID, '_wpcmb_config', true ), true );

	foreach ( ( is_array( $wpcmb_config ) ? $wpcmb_config['fields'] ?? array() : array() ) as $wpcmb_field ) {
		if ( is_array( $wpcmb_field ) && ! empty( $wpcmb_field['name'] ) ) {
			$wpcmb_names[] = (string) $wpcmb_field['name'];
		}
	}
}

$wpcmb_names = array_unique( $wpcmb_names );

// Values live in the native meta tables, so removing them is a metadata
// delete per object type rather than a table drop.
foreach ( $wpcmb_names as $wpcmb_name ) {
	foreach ( array( 'post', 'term', 'user', 'comment' ) as $wpcmb_type ) {
		delete_metadata( $wpcmb_type, 0, $wpcmb_name, '', true );
	}
}

// Options-page and widget values are prefixed options; there is no meta
// table to sweep, so match them by name.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API enumerates options by prefix, and caching a one-shot uninstall sweep would be pointless.
$wpcmb_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wpcmb_' ) . '%'
	)
);

foreach ( $wpcmb_options as $wpcmb_option ) {
	delete_option( $wpcmb_option );
}

// Field groups are posts, so deleting them is a normal post delete. Their
// configuration meta and revisions go with them.
foreach ( $wpcmb_groups as $wpcmb_group ) {
	wp_delete_post( $wpcmb_group->ID, true );
}

wp_cache_delete( 'field_groups', 'wpcmb' );
wp_cache_delete( 'location_choices', 'wpcmb' );
