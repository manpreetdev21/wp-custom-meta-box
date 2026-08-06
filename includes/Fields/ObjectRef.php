<?php
/**
 * Object reference.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the thing a field value belongs to.
 *
 * Every public entry point accepts the loose identifiers people actually type
 * — an id, a `WP_Post`, the string `term_5`, nothing at all — and resolves
 * them here once. Everything downstream then works with a known type and id
 * rather than re-guessing.
 *
 * The five types cover every supported location: menus and menu items are
 * terms and posts, media is a post, widgets and options pages are options.
 */
final class ObjectRef {

	/**
	 * Post, page, custom post type, attachment or menu item.
	 */
	public const POST = 'post';

	/**
	 * Term, including nav menus.
	 */
	public const TERM = 'term';

	/**
	 * User.
	 */
	public const USER = 'user';

	/**
	 * Comment.
	 */
	public const COMMENT = 'comment';

	/**
	 * Options page or widget, stored in the options table.
	 */
	public const OPTION = 'option';

	/**
	 * Default options page id.
	 */
	public const DEFAULT_OPTION_ID = 'options';

	/**
	 * Object type, one of the class constants.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * Object id. An integer for meta-backed types, a slug for options.
	 *
	 * @var int|string
	 */
	public readonly int|string $id;

	/**
	 * Constructor.
	 *
	 * @param string     $type Object type.
	 * @param int|string $id   Object id.
	 */
	public function __construct( string $type, int|string $id ) {
		$this->type = $type;
		$this->id   = $id;
	}

	/**
	 * Resolve a loose identifier.
	 *
	 * Accepted forms:
	 * - `null` or `false`: the post in the current loop or admin screen.
	 * - `int` or a numeric string: a post id.
	 * - `WP_Post`, `WP_Term`, `WP_User`, `WP_Comment`: that object.
	 * - `post_12`, `term_5`, `user_2`, `comment_9`: an explicit type and id.
	 * - `option`, `options`: the default options page.
	 * - `option_my_page`, `options_my_page`: a named options page.
	 *
	 * Anything unrecognised resolves to a post reference with id 0, which
	 * reads and writes nothing rather than touching an unintended object.
	 *
	 * @param mixed $identifier Loose identifier.
	 */
	public static function from( mixed $identifier = null ): self {
		if ( $identifier instanceof self ) {
			return $identifier;
		}

		if ( null === $identifier || false === $identifier || '' === $identifier ) {
			return new self( self::POST, self::current_post_id() );
		}

		if ( $identifier instanceof \WP_Post ) {
			return new self( self::POST, $identifier->ID );
		}

		if ( $identifier instanceof \WP_Term ) {
			return new self( self::TERM, $identifier->term_id );
		}

		if ( $identifier instanceof \WP_User ) {
			return new self( self::USER, $identifier->ID );
		}

		if ( $identifier instanceof \WP_Comment ) {
			return new self( self::COMMENT, (int) $identifier->comment_ID );
		}

		if ( is_int( $identifier ) ) {
			return new self( self::POST, $identifier );
		}

		if ( is_string( $identifier ) ) {
			return self::from_string( $identifier );
		}

		return new self( self::POST, 0 );
	}

	/**
	 * Resolve a string identifier.
	 *
	 * @param string $identifier String identifier.
	 */
	private static function from_string( string $identifier ): self {
		$identifier = trim( $identifier );

		if ( is_numeric( $identifier ) ) {
			return new self( self::POST, (int) $identifier );
		}

		if ( 'option' === $identifier || 'options' === $identifier ) {
			return new self( self::OPTION, self::DEFAULT_OPTION_ID );
		}

		if ( ! preg_match( '/^(post|term|user|comment|options?)_(.+)$/', $identifier, $matches ) ) {
			/**
			 * Filters an object reference the built-in forms could not resolve.
			 *
			 * Return an ObjectRef to claim the identifier. This is how a custom
			 * location adds its own addressing scheme.
			 *
			 * @since 1.0.0
			 *
			 * @param ObjectRef|null $ref        Resolved reference, or null.
			 * @param string         $identifier Unresolved identifier.
			 */
			$custom = apply_filters( 'wpcmb/object_ref', null, $identifier );

			return $custom instanceof self ? $custom : new self( self::POST, 0 );
		}

		if ( str_starts_with( $matches[1], 'option' ) ) {
			return new self( self::OPTION, sanitize_key( $matches[2] ) );
		}

		return new self( $matches[1], (int) $matches[2] );
	}

	/**
	 * The post currently being displayed or edited, or 0.
	 */
	private static function current_post_id(): int {
		$id = get_the_ID();

		if ( is_int( $id ) && $id > 0 ) {
			return $id;
		}

		$post = get_post();

		return $post instanceof \WP_Post ? $post->ID : 0;
	}

	/**
	 * Whether this reference points at something addressable.
	 */
	public function is_valid(): bool {
		return self::OPTION === $this->type ? '' !== (string) $this->id : (int) $this->id > 0;
	}

	/**
	 * The canonical string form, as accepted by from().
	 */
	public function __toString(): string {
		return $this->type . '_' . $this->id;
	}
}
