<?php
/**
 * The field group list screen.
 *
 * The row class is added through `post_class`, which WordPress runs for every
 * post on the front end as well as in the admin. A filter that global has to
 * be provably inert everywhere except our own list table, so that is what
 * these assertions pin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';
require_once __DIR__ . '/../vendor/autoload.php';

use WPCMB\Admin\FieldGroupList;
use WPCMB\Container;
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

echo "list-check: OK\n";
