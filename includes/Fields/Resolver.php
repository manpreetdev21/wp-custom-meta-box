<?php
/**
 * Field group resolution.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which field groups and fields apply to an object.
 *
 * Location rules are evaluated against the already-cached group list, so
 * answering "what shows on this screen" costs no queries beyond whatever the
 * individual rules ask for. Results are memoised per context because the
 * meta box renderer, the validator and the REST controller all ask the same
 * question during one request.
 */
final class Resolver {

	/**
	 * Field group repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Memoised results, keyed by context signature.
	 *
	 * @var array<string, array<string, FieldGroup>>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Field group repository.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Active field groups that apply to a context, in display order.
	 *
	 * @param Context $context Context to match against.
	 *
	 * @return array<string, FieldGroup>
	 */
	public function groups( Context $context ): array {
		$signature = $this->signature( $context );

		if ( isset( $this->memo[ $signature ] ) ) {
			return $this->memo[ $signature ];
		}

		$matched = array();

		foreach ( $this->repository->all() as $key => $group ) {
			if ( Locations::match( $group->location, $context ) ) {
				$matched[ $key ] = $group;
			}
		}

		uasort(
			$matched,
			static fn( FieldGroup $a, FieldGroup $b ): int =>
				( (int) $a->settings['menu_order'] ) <=> ( (int) $b->settings['menu_order'] )
		);

		/**
		 * Filters the field groups that apply to a context.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, FieldGroup> $matched Matching groups, keyed by group key.
		 * @param Context                   $context Context matched against.
		 */
		$matched = (array) apply_filters( 'wpcmb/groups', $matched, $context );

		$this->memo[ $signature ] = $matched;

		return $matched;
	}

	/**
	 * Every field that applies to a context, keyed by field name.
	 *
	 * @param Context $context Context to match against.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( Context $context ): array {
		$fields = array();

		foreach ( $this->groups( $context ) as $group ) {
			foreach ( $group->fields as $field ) {
				$name = (string) ( $field['name'] ?? '' );

				if ( '' !== $name && ! isset( $fields[ $name ] ) ) {
					$fields[ $name ] = $field;
				}
			}
		}

		return $fields;
	}

	/**
	 * Whether any field group applies to a context.
	 *
	 * @param Context $context Context to match against.
	 */
	public function has_groups( Context $context ): bool {
		return array() !== $this->groups( $context );
	}

	/**
	 * A cache key identifying a context.
	 *
	 * The extras are part of the key because two contexts for the same object
	 * can differ — the same user matched on the add form and the edit form.
	 * The repository version is part of it so that saving, importing or
	 * registering a group expires these results without the repository
	 * needing to know this class exists.
	 *
	 * @param Context $context Context to identify.
	 */
	private function signature( Context $context ): string {
		return $this->repository->version() . '|' . $context->ref . '|' . $context->signature_extra();
	}
}
