<?php
/**
 * Integration checks against a real WordPress install.
 *
 * The standalone checks run against hand-written shims, which has twice been
 * a problem: `sanitize_url()` and `wp_strip_all_tags()` both behaved more
 * leniently in the shim than in WordPress, so a check passed on behaviour
 * production did not have. This file exists to cover the places where being
 * wrong about WordPress would matter, using the real functions.
 *
 * Non-destructive by design. It creates its own posts, cleans them up, and
 * never touches anything it did not make — unlike the WordPress test suite,
 * which empties the database it is pointed at.
 *
 * Usage: php tests/integration.php
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A developer-run CLI script.

$wpcmb_wp = dirname( __DIR__, 4 );

if ( ! is_readable( $wpcmb_wp . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find WordPress above the plugin.\n" );
	exit( 1 );
}

define( 'WP_ADMIN', true );
require_once $wpcmb_wp . '/wp-load.php';

if ( ! function_exists( 'wpcmb' ) ) {
	fwrite( STDERR, "The plugin is not active on this install.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

/**
 * Everything this run created, so it can all be removed again.
 *
 * @var array<int, array{type: string, id: int}>
 */
$GLOBALS['wpcmb_created'] = array();

$GLOBALS['wpcmb_passed'] = 0;
$GLOBALS['wpcmb_failed'] = 0;

/**
 * Assert something, recording the outcome.
 *
 * @param bool   $condition   What must be true.
 * @param string $description What it means.
 */
function wpcmb_is( bool $condition, string $description ): void {
	if ( $condition ) {
		++$GLOBALS['wpcmb_passed'];

		return;
	}

	++$GLOBALS['wpcmb_failed'];

	printf( "  FAIL  %s\n", $description );
}

/**
 * Group a set of assertions under a heading.
 *
 * @param string   $name  Heading.
 * @param callable $tests The assertions.
 */
function wpcmb_group( string $name, callable $tests ): void {
	$before = $GLOBALS['wpcmb_failed'];

	printf( "%s\n", $name );

	try {
		$tests();
	} catch ( \Throwable $error ) {
		++$GLOBALS['wpcmb_failed'];
		printf( "  FAIL  threw: %s\n", $error->getMessage() );
	}

	if ( $before === $GLOBALS['wpcmb_failed'] ) {
		printf( "  ok\n" );
	}
}

/**
 * Create a field group, remembering it for cleanup.
 *
 * @param array<string, mixed> $config Group configuration.
 */
function wpcmb_group_fixture( array $config ): WPCMB\Fields\FieldGroup {
	$repository = wpcmb()->container()->get( WPCMB\Fields\Repository::class );

	$post_id = wp_insert_post(
		array(
			'post_type'   => WPCMB\PostTypes\FieldGroupPostType::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => (string) ( $config['title'] ?? 'Integration group' ),
		)
	);

	$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $post_id );

	$repository->save( (int) $post_id, WPCMB\Fields\FieldGroup::sanitize( $config ) );
	$repository->flush();

	return $repository->get( (int) $post_id );
}

/**
 * Create a post, remembering it for cleanup.
 *
 * @param string $type  Post type.
 * @param string $title Post title.
 */
function wpcmb_post_fixture( string $type = 'page', string $title = 'Integration target' ): int {
	$post_id = wp_insert_post(
		array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title )
	);

	$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $post_id );

	return (int) $post_id;
}

/* -------------------------------------------------------------------------
 * Sanitizing, against the real WordPress functions.
 *
 * These are the exact cases where the shims were once more lenient than
 * WordPress, so they are worth pinning to the real thing.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'sanitizing matches real WordPress',
	static function (): void {
		$input = wpcmb()->container()->get( WPCMB\Fields\Registry::class )->get( 'text' );
		$field = array( 'type' => 'url', 'name' => 'u', 'settings' => array() );

		// sanitize_url() prepends a scheme to anything, so free text would be
		// stored as http://not%20a%20url without the field's own guard.
		wpcmb_is( '' === $input->sanitize( 'not a url', $field ), 'free text is not stored as a URL' );
		wpcmb_is(
			'https://example.com/a?b=1' === $input->sanitize( ' https://example.com/a?b=1 ', $field ),
			'a real URL survives'
		);

		foreach ( array( '/about', '#top', 'mailto:a@b.com', 'tel:+441234567890' ) as $relative ) {
			wpcmb_is( '' !== $input->sanitize( $relative, $field ), "a URL field accepts {$relative}" );
		}

		// wp_strip_all_tags() removes script contents, not only the tags.
		$clean = WPCMB\Fields\FieldGroup::sanitize( array( 'title' => '<script>alert(1)</script>Hello' ) );

		wpcmb_is( 'Hello' === $clean['title'], 'a script element loses its contents as well as its tags' );

		// Field names must survive the same normalisation the builder applies.
		wpcmb_is(
			'first_name' === WPCMB\Fields\FieldGroup::sanitize_field_name( 'First Name' ),
			'a label becomes a meta-key-safe name'
		);
	}
);

/* -------------------------------------------------------------------------
 * Values, through real metadata.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'values round-trip through real metadata',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration values',
				'fields'   => array(
					array( 'name' => 'itest_text', 'label' => 'Text', 'type' => 'text' ),
					array( 'name' => 'itest_rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => array(
						array( 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
					) ),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture();

		// update_metadata() unslashes what it is given, so a value written
		// without slashing loses every backslash in it.
		$tricky = 'C:\\Users\\test "quoted" \\n literal';

		wpcmb_update_field( 'itest_text', $tricky, $page );

		wpcmb_is( $tricky === wpcmb_get_field( 'itest_text', $page ), 'backslashes survive a write and read' );
		wpcmb_is( $tricky === get_post_meta( $page, 'itest_text', true ), 'and are intact in the meta table itself' );

		$rows = array( array( 'title' => 'One' ), array( 'title' => 'Two' ) );
		wpcmb_update_field( 'itest_rows', $rows, $page );

		wpcmb_is( $rows === wpcmb_get_field( 'itest_rows', $page ), 'a repeater round-trips' );
		wpcmb_is( 1 === count( get_post_meta( $page, 'itest_rows', false ) ), 'a repeater uses one meta row' );

		wpcmb_delete_field( 'itest_text', $page );

		wpcmb_is( ! wpcmb_has_field( 'itest_text', $page ), 'a deleted value is gone' );
		wpcmb_is( ! metadata_exists( 'post', $page, 'itest_text' ), 'and left no empty row behind' );
	}
);

/* -------------------------------------------------------------------------
 * Location matching against real post types and objects.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'location rules match real objects',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration pages only',
				'fields'   => array( array( 'name' => 'itest_page_only', 'label' => 'Pages', 'type' => 'text' ) ),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture( 'page' );
		$post = wpcmb_post_fixture( 'post' );

		$on_page = array_map( static fn( $g ) => $g->title, wpcmb_get_field_groups( $page ) );
		$on_post = array_map( static fn( $g ) => $g->title, wpcmb_get_field_groups( $post ) );

		wpcmb_is( in_array( 'Integration pages only', $on_page, true ), 'the group applies to a page' );
		wpcmb_is( ! in_array( 'Integration pages only', $on_post, true ), 'and not to a post' );
	}
);

/* -------------------------------------------------------------------------
 * Rendering every registered type, looking for notices.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'every field type renders without a notice',
	static function (): void {
		$registry = wpcmb()->container()->get( WPCMB\Fields\Registry::class );
		$renderer = wpcmb()->container()->get( WPCMB\Fields\Renderer::class );

		$fields = array();

		foreach ( array_keys( $registry->all() ) as $type ) {
			$fields[] = array(
				'name'     => 'itest_' . $type,
				'label'    => ucfirst( $type ),
				'type'     => $type,
				'settings' => array( 'choices' => "a : Alpha\nb : Beta", 'message' => 'Hi' ),
			);
		}

		$group = wpcmb_group_fixture(
			array(
				'title'    => 'Integration all types',
				'fields'   => $fields,
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$notices = array();

		set_error_handler(
			static function ( int $number, string $message ) use ( &$notices ): bool {
				$notices[] = $message;

				return true;
			}
		);

		ob_start();
		$renderer->group( $group, new WPCMB\Fields\ObjectRef( 'post', wpcmb_post_fixture() ) );
		$html = (string) ob_get_clean();

		restore_error_handler();

		wpcmb_is( array() === $notices, 'no PHP notices: ' . implode( '; ', array_slice( $notices, 0, 3 ) ) );
		wpcmb_is( strlen( $html ) > 1000, 'the group produced markup' );
		wpcmb_is( count( $registry->all() ) >= 50, 'every type is registered' );
	}
);

/* -------------------------------------------------------------------------
 * REST, through the real server.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'REST enforces its permission boundaries',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration REST',
				'fields'   => array( array( 'name' => 'itest_rest', 'label' => 'Rest', 'type' => 'text' ) ),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture();

		do_action( 'init' );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		/**
		 * Dispatch a request.
		 *
		 * @param string                    $method Method.
		 * @param string                    $route  Route.
		 * @param array<string, mixed>|null $body   Body parameters.
		 */
		$dispatch = static function ( string $method, string $route, ?array $body = null ) use ( $server ) {
			$request = new WP_REST_Request( $method, $route );

			if ( null !== $body ) {
				$request->set_body_params( $body );
			}

			return $server->dispatch( $request );
		};

		wp_set_current_user( 0 );

		wpcmb_is( 401 === $dispatch( 'GET', '/wpcmb/v1/field-groups' )->get_status(), 'anon cannot list groups' );
		wpcmb_is( 200 === $dispatch( 'GET', '/wpcmb/v1/values/post/' . $page )->get_status(), 'anon can read a published page' );
		wpcmb_is(
			401 === $dispatch( 'POST', '/wpcmb/v1/values/post/' . $page, array( 'values' => array( 'itest_rest' => 'x' ) ) )->get_status(),
			'anon cannot write'
		);

		wp_set_current_user( 1 );

		$write = $dispatch( 'POST', '/wpcmb/v1/values/post/' . $page, array( 'values' => array( 'itest_rest' => '  spaced  ' ) ) );

		wpcmb_is( 200 === $write->get_status(), 'an admin can write' );
		wpcmb_is( 'spaced' === $write->get_data()['values']['itest_rest'], 'the value is sanitized on the way in' );

		$core = $dispatch( 'GET', '/wp/v2/pages/' . $page );
		$meta = $core->get_data()['meta'] ?? array();

		wpcmb_is( 'spaced' === ( $meta['itest_rest'] ?? null ), 'the value appears on the core endpoint too' );
	}
);

/* -------------------------------------------------------------------------
 * The publish guard.
 *
 * The browser gate is what an editor experiences, but it is a script, and a
 * script can be off, broken or bypassed. This is the half that holds anyway,
 * so it is checked against real posts with real statuses rather than shims.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'a post cannot be published while a required field is empty',
	static function (): void {
		$page = wpcmb_post_fixture( 'page', 'Integration required' );

		wpcmb_group_fixture(
			array(
				'title'    => 'Integration required',
				'fields'   => array(
					array( 'name' => 'itest_needed', 'label' => 'Needed', 'type' => 'text', 'required' => true ),
					array( 'name' => 'itest_spare', 'label' => 'Spare', 'type' => 'text' ),
				),
				'location' => array( array( array( 'param' => 'page', 'operator' => '==', 'value' => (string) $page ) ) ),
			)
		);

		/**
		 * Try to publish the page with the given values submitted.
		 *
		 * Builds the request a form submission would, because that is what the
		 * guard reads: the nonce is what tells it the values are ours to judge.
		 *
		 * @param array<string, mixed> $values Field values.
		 *
		 * @return string The status the post ended up with.
		 */
		$publish = static function ( array $values ) use ( $page ): string {
			wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );

			$_POST = array(
				'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
				'wpcmb_values'         => $values,
				'post_ID'              => $page,
				'post_status'          => 'publish',
				'original_post_status' => 'draft',
			);

			$_REQUEST = $_POST;

			wp_update_post(
				array(
					'ID'                   => $page,
					'post_status'          => 'publish',
					'original_post_status' => 'draft',
				)
			);

			$status = (string) get_post( $page )->post_status;

			$_POST    = array();
			$_REQUEST = array();

			return $status;
		};

		wpcmb_is( 'draft' === $publish( array() ), 'publishing with nothing submitted is refused' );
		wpcmb_is( 'draft' === $publish( array( 'itest_needed' => '' ) ), 'publishing with the field empty is refused' );

		// The refusal has to explain itself, or it reads as a broken button.
		$errors = get_transient( 'wpcmb_errors_' . get_current_user_id() . '_post_' . $page );

		wpcmb_is( is_array( $errors ) && isset( $errors['itest_needed'] ), 'the reason is recorded for the notice' );
		wpcmb_is(
			(bool) get_transient( 'wpcmb_blocked_' . get_current_user_id() . '_post_' . $page ),
			'the refusal is recorded, so the notice can say "not published"'
		);

		// Whatever else was typed is still kept: refusing to publish must not
		// also throw away the work.
		$publish( array( 'itest_spare' => 'kept' ) );

		wpcmb_is( 'kept' === get_post_meta( $page, 'itest_spare', true ), 'other values are still saved' );

		wpcmb_is( 'publish' === $publish( array( 'itest_needed' => 'filled in' ) ), 'publishing works once it is filled in' );

		// A draft is work in progress. Saving one must never be refused, or a
		// half-written page cannot be kept at all.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

		$_POST = array(
			'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
			'wpcmb_values'         => array( 'itest_needed' => '' ),
			'post_ID'              => $page,
			'post_status'          => 'draft',
			'original_post_status' => 'publish',
		);

		$_REQUEST = $_POST;

		wp_update_post( array( 'ID' => $page, 'post_status' => 'draft', 'original_post_status' => 'publish' ) );

		wpcmb_is( 'draft' === get_post( $page )->post_status, 'saving as a draft is never refused' );

		// An already-public post is left public. An edit that happens to leave
		// a required field empty must not take a live page off the site.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

		$_POST = array(
			'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
			'wpcmb_values'         => array( 'itest_needed' => '' ),
			'post_ID'              => $page,
			'post_status'          => 'publish',
			'original_post_status' => 'publish',
		);

		$_REQUEST = $_POST;

		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish', 'original_post_status' => 'publish' ) );

		wpcmb_is( 'publish' === get_post( $page )->post_status, 'a published page is never unpublished by the guard' );

		$_POST    = array();
		$_REQUEST = array();

		// Without the nonce this is somebody else's save, carrying none of our
		// values, and must pass through untouched.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish', 'original_post_status' => 'draft' ) );

		wpcmb_is( 'publish' === get_post( $page )->post_status, 'a save that is not ours is not judged' );
	}
);

/* -------------------------------------------------------------------------
 * Clean up everything this run created.
 * ---------------------------------------------------------------------- */

foreach ( $GLOBALS['wpcmb_created'] as $wpcmb_item ) {
	wp_delete_post( $wpcmb_item['id'], true );
}

printf(
	"\n%d assertions passed, %d failed. %d fixtures removed.\n",
	$GLOBALS['wpcmb_passed'],
	$GLOBALS['wpcmb_failed'],
	count( $GLOBALS['wpcmb_created'] )
);

exit( $GLOBALS['wpcmb_failed'] > 0 ? 1 : 0 );
