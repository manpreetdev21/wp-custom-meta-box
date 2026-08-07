<?php
/**
 * The field group list screens.
 *
 * Two things are pinned here.
 *
 * The row class on the core list table is added through `post_class`, which
 * WordPress runs for every post on the front end as well as in the admin. A
 * filter that global has to be provably inert everywhere except our own list
 * table.
 *
 * The custom screen's filtering is the only part of that screen with real
 * logic in it, and getting it wrong hides groups rather than failing loudly.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';
require_once __DIR__ . '/../vendor/autoload.php';

use WPCMB\Admin\FieldGroupList;
use WPCMB\Admin\GroupsPage;
use WPCMB\Container;
use WPCMB\Fields\FieldGroup;
use WPCMB\PostTypes\FieldGroupPostType;

/**
 * A stand-in for WP_Screen.
 */
final class WP_Screen { // phpcs:ignore

	/**
	 * Build a screen with a base.
	 *
	 * @param string $base Screen base.
	 */
	public function __construct( public string $base = '' ) {}
}

/* -------------------------------------------------------------------------
 * The row class on core's list table.
 * ---------------------------------------------------------------------- */

$wpcmb_list = new FieldGroupList( new Container() );

$GLOBALS['wpcmb_test_posts'] = array(
	new WP_Post( 11, 'A group', 'publish', FieldGroupPostType::POST_TYPE ),
	new WP_Post( 22, 'A page', 'publish', 'page' ),
);

/**
 * Run the row_class filter under a given context.
 *
 * @param FieldGroupList $list   List module.
 * @param bool           $admin  Whether this is an admin request.
 * @param object|null    $screen Current screen.
 * @param int            $id     Post id.
 *
 * @return array<int, string>
 */
function wpcmb_row_class( FieldGroupList $list, bool $admin, $screen, int $id ): array {
	$GLOBALS['wpcmb_test_is_admin'] = $admin;
	$GLOBALS['wpcmb_test_screen']   = $screen;

	return $list->row_class( array( 'post' ), array(), $id );
}

$wpcmb_edit = new WP_Screen( 'edit' );
$wpcmb_post = new WP_Screen( 'post' );

// The one case that should be tagged.
assert(
	in_array( 'wpcmb-row', wpcmb_row_class( $wpcmb_list, true, $wpcmb_edit, 11 ), true ),
	'our own list screen gets the row class'
);

// Everything else must come back exactly as it went in.
assert( array( 'post' ) === wpcmb_row_class( $wpcmb_list, false, null, 11 ), 'the front end is untouched' );
assert( array( 'post' ) === wpcmb_row_class( $wpcmb_list, true, $wpcmb_edit, 22 ), 'another post type list table is untouched' );
assert( array( 'post' ) === wpcmb_row_class( $wpcmb_list, true, $wpcmb_post, 11 ), 'our own editor screen is untouched' );
assert( array( 'post' ) === wpcmb_row_class( $wpcmb_list, true, null, 11 ), 'a request with no screen is untouched' );

// The filter is handed whatever the caller has; it must never assume an array.
$GLOBALS['wpcmb_test_is_admin'] = true;
$GLOBALS['wpcmb_test_screen']   = $wpcmb_edit;
assert( is_array( $wpcmb_list->row_class( 'not an array', array(), 11 ) ), 'a non-array argument is survivable' );

/* -------------------------------------------------------------------------
 * The custom screen's filtering.
 *
 * Reached by reflection: it is private because nothing outside the screen
 * should call it, but it is where the logic lives.
 * ---------------------------------------------------------------------- */

$wpcmb_page   = new GroupsPage( new Container() );
$wpcmb_filter = new ReflectionMethod( GroupsPage::class, 'filter' );
$wpcmb_filter->setAccessible( true );

$wpcmb_all = array(
	new FieldGroup( 1, 'group_hero00000001', 'Hero Banner', array(), array(), array( 'active' => true ) ),
	new FieldGroup( 2, 'group_seo000000001', 'SEO Meta', array(), array(), array( 'active' => false ) ),
	new FieldGroup( 3, 'group_team00000001', 'Team Member', array(), array(), array( 'active' => true ) ),
);

/**
 * Titles left after filtering.
 *
 * @param array<int, FieldGroup> $groups Result.
 *
 * @return array<int, string>
 */
function wpcmb_titles( array $groups ): array {
	return array_map( static fn( FieldGroup $group ): string => $group->title, $groups );
}

assert( 3 === count( $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, '', 'all' ) ), 'no filter shows everything' );

assert(
	array( 'Hero Banner', 'Team Member' ) === wpcmb_titles( $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, '', 'active' ) ),
	'the active filter hides inactive groups'
);
assert(
	array( 'SEO Meta' ) === wpcmb_titles( $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, '', 'inactive' ) ),
	'and the inactive filter is its exact complement'
);

// Search covers the key as well as the title: a developer looking for a group
// usually has the key in front of them, not the label.
assert(
	array( 'Hero Banner' ) === wpcmb_titles( $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, 'hero', 'all' ) ),
	'search matches a title'
);
assert(
	array( 'SEO Meta' ) === wpcmb_titles( $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, 'group_seo', 'all' ) ),
	'search matches a key'
);
assert(
	array( 'Hero Banner' ) === wpcmb_titles( $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, 'HERO', 'all' ) ),
	'search ignores case'
);
assert(
	array() === $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, 'nothing-like-this', 'all' ),
	'a miss returns nothing rather than everything'
);

// The two filters combine rather than one winning.
assert(
	array() === $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, 'hero', 'inactive' ),
	'search and status are ANDed'
);

// The result is renumbered: a gap would encode as a JSON object, not a list.
$wpcmb_left = $wpcmb_filter->invoke( $wpcmb_page, $wpcmb_all, '', 'inactive' );
assert(
	array_keys( $wpcmb_left ) === range( 0, count( $wpcmb_left ) - 1 ),
	'the result is a list, not a sparse array'
);

echo "list-check: OK\n";
