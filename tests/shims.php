<?php
/**
 * Autoloader and minimal WordPress function shims for the standalone checks.
 *
 * Not a WordPress emulator: only the handful of functions the classes under
 * test touch, with just enough behaviour for the assertions to mean
 * something. Phase 12 replaces this with the WordPress test suite.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

define( 'WPCMB_VERSION', '1.0.0' );
define( 'WPCMB_BASENAME', 'wp-custom-meta-box/wp-custom-meta-box.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'WPCMB\\' ) ) {
			return;
		}
		$path = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', substr( $class, 6 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

$GLOBALS['wpcmb_test_filters'] = array();

/**
 * Register a filter callback for the checks.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 */
function wpcmb_test_filter( string $hook, callable $callback ): void {
	$GLOBALS['wpcmb_test_filters'][ $hook ][] = $callback;
}

/**
 * Apply registered filters.
 *
 * @param string $hook    Hook name.
 * @param mixed  $value   Value to filter.
 * @param mixed  ...$args Extra arguments.
 *
 * @return mixed
 */
function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['wpcmb_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

/**
 * No-op action dispatcher.
 *
 * @param string $hook    Hook name.
 * @param mixed  ...$args Arguments.
 */
function do_action( string $hook, ...$args ): void {}

/**
 * No-op action registration.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 */
function add_action( string $hook, callable $callback, int $priority = 10 ): void {}

/**
 * How many times an action has fired. Always zero here.
 *
 * @param string $hook Hook name.
 */
function did_action( string $hook ): int {
	return (int) ( $GLOBALS['wpcmb_test_did_action'][ $hook ] ?? 0 );
}

/**
 * Pass-through translation.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 */
function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore
	return $text;
}

/**
 * Escape for HTML output.
 *
 * @param string $text Text.
 */
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Reduce a string to lowercase alphanumerics, dashes and underscores.
 *
 * @param string $key Key.
 */
function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
}

/**
 * Strip tags and control characters from a single-line string.
 *
 * @param string $text Text.
 */
function sanitize_text_field( string $text ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( $text ) ) ?? '' );
}

/**
 * Strip tags from a multi-line string.
 *
 * @param string $text Text.
 */
function sanitize_textarea_field( string $text ): string {
	return trim( wp_strip_all_tags( $text ) );
}

/**
 * Reduce a string to a valid HTML class name.
 *
 * @param string $class_name Class name.
 */
function sanitize_html_class( string $class_name ): string {
	return preg_replace( '/[^A-Za-z0-9_\-]/', '', $class_name ) ?? '';
}

/**
 * Remove all markup, matching wp_strip_all_tags().
 *
 * Script and style elements lose their contents as well as their tags. A
 * shim that only stripped tags would leave `alert(1)` where WordPress leaves
 * nothing, which would let a check pass on behaviour the real function does
 * not have.
 *
 * @param string $text Text.
 */
function wp_strip_all_tags( string $text ): string {
	$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

	return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Shim.
}

/**
 * Stand-in for wp_kses_post(): the checks only need markup to survive.
 *
 * @param string $text Text.
 */
function wp_kses_post( string $text ): string {
	return strip_tags( $text, '<a><em><strong><code><br>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Shim.
}

/**
 * JSON encode, matching wp_json_encode()'s signature.
 *
 * @param mixed $data  Data.
 * @param int   $flags Encoding flags.
 *
 * @return string|false
 */
function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Shim.
}

/**
 * Object cache stub backed by a static array.
 *
 * @param string $key   Cache key.
 * @param string $group Cache group.
 *
 * @return mixed
 */
function wp_cache_get( string $key, string $group = '' ) {
	return $GLOBALS['wpcmb_test_cache'][ $group ][ $key ] ?? false;
}

/**
 * Object cache stub setter.
 *
 * @param string $key    Cache key.
 * @param mixed  $data   Value.
 * @param string $group  Cache group.
 * @param int    $expire Ignored.
 */
function wp_cache_set( string $key, $data, string $group = '', int $expire = 0 ): bool {
	$GLOBALS['wpcmb_test_cache'][ $group ][ $key ] = $data;

	return true;
}

/**
 * Object cache stub deleter.
 *
 * @param string $key   Cache key.
 * @param string $group Cache group.
 */
function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $GLOBALS['wpcmb_test_cache'][ $group ][ $key ] );

	return true;
}

/**
 * Minimal WP_Post stand-in.
 */
class WP_Post { // phpcs:ignore
	/**
	 * Construct from properties.
	 *
	 * @param int    $ID          Post id.
	 * @param string $post_title  Title.
	 * @param string $post_status Status.
	 * @param string $post_type   Post type.
	 */
	public function __construct(
		public int $ID = 0, // phpcs:ignore
		public string $post_title = '',
		public string $post_status = 'publish',
		public string $post_type = 'post'
	) {}
}

/** Minimal WP_Term stand-in. */
class WP_Term { // phpcs:ignore
	/**
	 * Construct from properties.
	 *
	 * @param int $term_id Term id.
	 */
	public function __construct( public int $term_id = 0 ) {}
}

/** Minimal WP_User stand-in. */
class WP_User { // phpcs:ignore
	/**
	 * Construct from properties.
	 *
	 * @param int $ID User id.
	 */
	public function __construct( public int $ID = 0 ) {} // phpcs:ignore
}

/** Minimal WP_Comment stand-in. */
class WP_Comment { // phpcs:ignore
	/**
	 * Construct from properties.
	 *
	 * @param int $comment_ID Comment id.
	 */
	public function __construct( public int $comment_ID = 0 ) {} // phpcs:ignore
}

/**
 * Recursively add slashes, as wp_slash() does.
 *
 * @param mixed $value Value.
 *
 * @return mixed
 */
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

/**
 * Recursively strip slashes, as wp_unslash() does.
 *
 * @param mixed $value Value.
 *
 * @return mixed
 */
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Metadata store getter.
 *
 * @param string $type   Object type.
 * @param int    $id     Object id.
 * @param string $key    Meta key.
 * @param bool   $single Whether to return a single value.
 *
 * @return mixed
 */
function get_metadata( string $type, int $id, string $key = '', bool $single = false ) {
	$stored = $GLOBALS['wpcmb_test_meta'][ $type ][ $id ][ $key ] ?? null;

	if ( null === $stored ) {
		return $single ? '' : array();
	}

	return $single ? $stored : array( $stored );
}

/**
 * Metadata store setter. Unslashes like the real thing.
 *
 * @param string $type  Object type.
 * @param int    $id    Object id.
 * @param string $key   Meta key.
 * @param mixed  $value Value.
 */
function update_metadata( string $type, int $id, string $key, $value ): bool {
	$GLOBALS['wpcmb_test_meta'][ $type ][ $id ][ $key ] = wp_unslash( $value );

	return true;
}

/**
 * Metadata store deleter.
 *
 * @param string $type  Object type.
 * @param int    $id    Object id.
 * @param string $key   Meta key.
 * @param mixed  $value Ignored.
 * @param bool   $all   Ignored.
 */
function delete_metadata( string $type, int $id, string $key, $value = '', bool $all = false ): bool {
	$existed = isset( $GLOBALS['wpcmb_test_meta'][ $type ][ $id ][ $key ] );
	unset( $GLOBALS['wpcmb_test_meta'][ $type ][ $id ][ $key ] );

	return $existed;
}

/**
 * Post meta store getter.
 *
 * @param int    $id     Post id.
 * @param string $key    Meta key.
 * @param bool   $single Whether to return a single value.
 *
 * @return mixed
 */
function get_post_meta( int $id, string $key = '', bool $single = false ) {
	return get_metadata( 'post', $id, $key, $single );
}

/**
 * Option store getter.
 *
 * @param string $name    Option name.
 * @param mixed  $default_value Default when unset.
 *
 * @return mixed
 */
function get_option( string $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['wpcmb_test_options'] ?? array() )
		? $GLOBALS['wpcmb_test_options'][ $name ]
		: $default_value;
}

/**
 * Option store setter.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param bool   $autoload Ignored.
 */
function update_option( string $name, $value, bool $autoload = false ): bool {
	$GLOBALS['wpcmb_test_options'][ $name ] = $value;

	return true;
}

/**
 * Option store deleter.
 *
 * @param string $name Option name.
 */
function delete_option( string $name ): bool {
	$existed = array_key_exists( $name, $GLOBALS['wpcmb_test_options'] ?? array() );
	unset( $GLOBALS['wpcmb_test_options'][ $name ] );

	return $existed;
}

/**
 * Post store query, filtered by post type.
 *
 * @param array<string, mixed> $args Query args.
 *
 * @return array<int, WP_Post>
 */
function get_posts( array $args = array() ): array {
	return array_values(
		array_filter(
			$GLOBALS['wpcmb_test_posts'] ?? array(),
			static fn( WP_Post $post ): bool => ! isset( $args['post_type'] ) || $post->post_type === $args['post_type']
		)
	);
}

/**
 * Post store getter.
 *
 * @param int $id Post id.
 */
function get_post( int $id = 0 ): ?WP_Post {
	foreach ( $GLOBALS['wpcmb_test_posts'] ?? array() as $post ) {
		if ( $post->ID === $id ) {
			return $post;
		}
	}

	return null;
}

/**
 * Current post id, for ObjectRef's implicit reference.
 */
function get_the_ID() { // phpcs:ignore
	return $GLOBALS['wpcmb_test_current_post'] ?? false;
}

/**
 * Keyed hash, standing in for wp_hash().
 *
 * A fixed key is fine here: the checks only assert that the same input hashes
 * the same way and a different one does not.
 *
 * @param string $data   Data to hash.
 * @param string $scheme Ignored.
 */
function wp_hash( string $data, string $scheme = 'auth' ): string {
	return hash_hmac( 'sha256', $data, 'wpcmb-test-salt' );
}

/**
 * Escape for an HTML attribute.
 *
 * @param string $text Text.
 */
function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Whether a visitor is signed in.
 */
function is_user_logged_in(): bool {
	return (bool) ( $GLOBALS['wpcmb_test_logged_in'] ?? false );
}

/**
 * The signed-in user's id.
 */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['wpcmb_test_current_user'] ?? 0 );
}

/**
 * Capability check stub.
 *
 * @param string $capability Capability.
 * @param mixed  ...$args    Extra arguments.
 */
function current_user_can( string $capability, ...$args ): bool {
	return (bool) ( $GLOBALS['wpcmb_test_can'] ?? false );
}

/**
 * Loose email check, matching is_email()'s contract closely enough.
 *
 * @param string $email Address.
 *
 * @return string|false
 */
function is_email( string $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

/**
 * Trim to a valid email, or false.
 *
 * @param string $email Address.
 *
 * @return string
 */
function sanitize_email( string $email ): string {
	$email = trim( $email );

	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}

/**
 * Stand-in for sanitize_url().
 *
 * Mirrors the behaviour that matters here: relative paths, fragments and
 * non-http schemes pass through, and anything else gains an http:// prefix
 * rather than being rejected. A stricter shim would make the URL field's own
 * check look correct while the real function behaved differently.
 *
 * @param string $url URL.
 */
function sanitize_url( string $url ): string {
	$url = trim( $url );

	if ( '' === $url ) {
		return '';
	}

	if ( str_starts_with( $url, '/' ) || str_starts_with( $url, '#' ) ) {
		return $url;
	}

	// Core filters the scheme against wp_allowed_protocols() and returns an
	// empty string for anything else. A shim that waves every scheme through
	// makes `javascript:` look survivable in a test and not in production.
	if ( 1 === preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $scheme ) ) {
		$allowed = array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );

		return in_array( strtolower( $scheme[1] ), $allowed, true ) ? $url : '';
	}

	return 'http://' . str_replace( ' ', '%20', $url );
}

/**
 * Keep a three or six digit hex colour.
 *
 * @param string $color Colour.
 *
 * @return string|null
 */
function sanitize_hex_color( string $color ): ?string {
	return 1 === preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : null;
}

/**
 * Slugify a string.
 *
 * @param string $title Title.
 */
function sanitize_title( string $title ): string {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ) ?? '', '-' );
}

/**
 * Whether a string is a UUID.
 *
 * @param string $uuid    Candidate.
 * @param int    $version Ignored.
 */
function wp_is_uuid( string $uuid, int $version = 4 ): bool {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
}

/**
 * Generate a version 4 UUID.
 */
function wp_generate_uuid4(): string {
	return sprintf(
		'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0x0fff ),
		wp_rand( 0, 0x3fff ) | 0x8000,
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff )
	);
}

/**
 * Random integer.
 *
 * @param int $min Minimum.
 * @param int $max Maximum.
 */
function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min, 0 === $max ? PHP_INT_MAX : $max );
}

/**
 * Pluralisation stub. The checks never assert on plural forms.
 *
 * @param string $single Singular form.
 * @param string $plural Plural form.
 * @param int    $number Count.
 * @param string $domain Text domain.
 */
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}
