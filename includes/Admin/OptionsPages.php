<?php
/**
 * Options pages, created from the location rules that ask for them.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "Options Page is site-settings" into an actual screen.
 *
 * The rule, the storage and the reading side all existed already: a group
 * could be located on an options page, values written against an `option`
 * reference were stored as prefixed options, and `wpcmb_get_field( 'name',
 * 'options_site-settings' )` read them back. The screen itself did not. So
 * choosing that rule produced a group that could never appear unless the site
 * registered a menu page and called the renderer itself — which is exactly
 * the code nobody should have to write in a theme.
 *
 * Every page here is discovered, never configured: the slug comes from the
 * rule, so there is no second list of pages to keep in step with the rules
 * that point at them. Delete the rule and the page goes with it.
 */
final class OptionsPages extends Module {

	/**
	 * Nonce action for saving a page.
	 */
	private const NONCE = 'wpcmb_save_options';

	/**
	 * Prefix for the admin page slug.
	 *
	 * The rule's value is the object id, not the menu slug: prefixing keeps a
	 * page called `general` or `tools` from colliding with a core screen or
	 * with another plugin that had the same idea.
	 */
	private const SLUG_PREFIX = 'wpcmb-options-';

	/**
	 * Only load in the admin.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * The options pages the location rules ask for.
	 *
	 * Only `==` rules name a page. A rule saying the group is *not* on some
	 * options page describes where it does not go, which is no reason to
	 * build anything.
	 *
	 * @return array<string, array{title: string, order: int}>
	 */
	public function pages(): array {
		$pages = array();

		foreach ( $this->container->get( Repository::class )->all() as $group ) {
			foreach ( $this->slugs_in( $group ) as $slug ) {
				$order = (int) ( $group->settings['menu_order'] ?? 0 );

				// Two groups can share a page. The first one, in the order the
				// list itself is sorted, names it.
				if ( isset( $pages[ $slug ] ) && $pages[ $slug ]['order'] <= $order ) {
					continue;
				}

				$pages[ $slug ] = array(
					'title' => '' !== $group->title ? $group->title : $this->humanise( $slug ),
					'order' => $order,
				);
			}
		}

		ksort( $pages );

		/**
		 * Filters the options pages discovered from location rules.
		 *
		 * Adding an entry here creates a page with no rule pointing at it,
		 * which is how a site can build one in code and still have the
		 * plugin render and save it.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, array{title: string, order: int}> $pages Keyed by slug.
		 */
		return (array) apply_filters( 'wpcmb/options_pages', $pages );
	}

	/**
	 * The options page slugs one group's rules name.
	 *
	 * @param FieldGroup $group Field group.
	 *
	 * @return array<int, string>
	 */
	private function slugs_in( FieldGroup $group ): array {
		$slugs = array();

		foreach ( $group->location as $rule_group ) {
			foreach ( (array) $rule_group as $rule ) {
				if ( ! is_array( $rule ) || 'options_page' !== ( $rule['param'] ?? '' ) ) {
					continue;
				}

				if ( '==' !== ( $rule['operator'] ?? '==' ) ) {
					continue;
				}

				// Sanitized the same way ObjectRef does, so the menu slug and
				// the object the values are stored against cannot drift.
				$slug = sanitize_key( (string) ( $rule['value'] ?? '' ) );

				if ( '' !== $slug ) {
					$slugs[ $slug ] = $slug;
				}
			}
		}

		return array_values( $slugs );
	}

	/**
	 * Add a menu entry for each discovered page.
	 *
	 * Top level, in the main menu, because that is where an options page an
	 * editor uses every day belongs — not three clicks deep under Settings.
	 * Set `parent` through `wpcmb/options_page/args` to move one.
	 *
	 * Both names carry the slug, and the page title carries the plugin's name
	 * as well: a site can have several of these, they are created from
	 * whatever somebody typed into a rule, and "Site Options" alone tells you
	 * neither which plugin made it nor which slug it stores against.
	 */
	public function register(): void {
		// Core puts Settings at 80, so this sits immediately below it: an
		// options screen reads as a settings screen, and that is where
		// somebody looking for one will look.
		$position = 80.9;

		foreach ( $this->pages() as $slug => $page ) {
			$args = array(
				'parent'     => null,
				'page_title' => sprintf(
					/* translators: 1: plugin name, 2: page title, 3: options page slug. */
					__( '%1$s: %2$s (%3$s)', 'wp-custom-meta-box' ),
					self::plugin_name(),
					$page['title'],
					$slug
				),
				'menu_title' => self::menu_label( $page['title'], $slug ),
				'capability' => 'manage_options',
				'icon'       => 'dashicons-admin-generic',

				// Fractional, so several of these keep their order among
				// themselves without displacing anything already at 81.
				'position'   => $position,
			);

			$position += 0.01;

			/**
			 * Filters how one options page is added to the admin menu.
			 *
			 * Set `parent` to a menu file to move it — `null` makes it a
			 * top-level menu of its own.
			 *
			 * @since 1.3.0
			 *
			 * @param array<string, mixed> $args Menu arguments.
			 * @param string               $slug Options page slug.
			 */
			$args = (array) apply_filters( 'wpcmb/options_page/args', $args, $slug );

			$render = function () use ( $slug ): void {
				$this->render( $slug );
			};

			$hook = null === ( $args['parent'] ?? null )
				? add_menu_page(
					(string) $args['page_title'],
					(string) $args['menu_title'],
					(string) $args['capability'],
					self::SLUG_PREFIX . $slug,
					$render,
					(string) $args['icon'],
					null === $args['position'] ? null : (float) $args['position']
				)
				: add_submenu_page(
					(string) $args['parent'],
					(string) $args['page_title'],
					(string) $args['menu_title'],
					(string) $args['capability'],
					self::SLUG_PREFIX . $slug,
					$render,
					null === $args['position'] ? null : (int) $args['position']
				);

			if ( ! is_string( $hook ) || '' === $hook ) {
				continue;
			}

			// Saving happens on `load-`, before anything is sent, so a
			// successful save can redirect and a refresh cannot repeat it.
			add_action(
				'load-' . $hook,
				function () use ( $slug ): void {
					$this->handle_save( $slug );
				}
			);
		}
	}

	/**
	 * Store a submitted page, then send the browser back to it.
	 *
	 * @param string $slug Options page slug.
	 */
	private function handle_save( string $slug ): void {
		if ( ! isset( $_POST['wpcmb_options_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'wpcmb_options_nonce' );

		$ref = new ObjectRef( ObjectRef::OPTION, $slug );

		if ( ! Permissions::can_edit( $ref ) ) {
			wp_die( esc_html__( 'You do not have permission to edit these options.', 'wp-custom-meta-box' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is validated and then sanitized by its own field type below; a blanket sanitizer here would flatten the arrays that composite types post.
		$submitted = isset( $_POST[ Renderer::INPUT_PREFIX ] ) && is_array( $_POST[ Renderer::INPUT_PREFIX ] )
			? wp_unslash( $_POST[ Renderer::INPUT_PREFIX ] )
			: array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$fields   = array();
		$present  = array();

		/*
		 * Every field on the page is written, including the ones the request
		 * left out. This is the opposite of the meta box save path, which
		 * skips absent fields so a partial submission cannot wipe what it
		 * never showed — but this form always carries every field, so absent
		 * means emptied. Without it a checkbox could be ticked and never
		 * unticked again.
		 */
		foreach ( $this->container->get( Resolver::class )->fields( new Context( $ref ) ) as $name => $field ) {
			if ( ! $renderer->stores_value( $field ) ) {
				continue;
			}

			$fields[ $name ]  = $field;
			$present[ $name ] = $submitted[ $name ] ?? null;
		}

		$errors = $this->container->get( Validator::class )->validate( $fields, $present );

		foreach ( $present as $name => $value ) {
			$values->update( $name, $renderer->sanitize( $value, $fields[ $name ] ), $ref );
		}

		/**
		 * Fires after an options page has been saved.
		 *
		 * @since 1.3.0
		 *
		 * @param string                $slug   Options page slug.
		 * @param array<string, mixed>  $values Submitted values, before sanitizing.
		 * @param array<string, string> $errors Validation errors, if any.
		 */
		do_action( 'wpcmb/options_page/saved', $slug, $present, $errors );

		set_transient( $this->result_key( $slug ), $errors, MINUTE_IN_SECONDS * 5 );

		wp_safe_redirect(
			add_query_arg(
				'wpcmb-saved',
				array() === $errors ? '1' : '0',
				menu_page_url( self::SLUG_PREFIX . $slug, false )
			)
		);

		exit;
	}

	/**
	 * Render one options page.
	 *
	 * @param string $slug Options page slug.
	 */
	private function render( string $slug ): void {
		$ref = new ObjectRef( ObjectRef::OPTION, $slug );

		if ( ! Permissions::can_edit( $ref ) ) {
			wp_die( esc_html__( 'You do not have permission to view these options.', 'wp-custom-meta-box' ) );
		}

		$pages    = $this->pages();
		$title    = (string) ( $pages[ $slug ]['title'] ?? $this->humanise( $slug ) );
		$groups   = $this->container->get( Resolver::class )->groups( new Context( $ref ) );
		$renderer = $this->container->get( Renderer::class );

		$this->render_notice( $slug );
		?>
		<div class="wrap wpcmb-wrap">
			<span class="wpcmb-eyebrow wpcmb-options-eyebrow"><?php echo esc_html( self::plugin_name() ); ?></span>
			<h1>
				<?php echo esc_html( $title ); ?>
				<code class="wpcmb-options-slug" title="<?php esc_attr_e( 'The options page slug these values are stored against', 'wp-custom-meta-box' ); ?>"><?php echo esc_html( $slug ); ?></code>
			</h1>

			<?php if ( array() === $groups ) : ?>
				<div class="wpcmb-empty wpcmb-empty--page">
					<span class="wpcmb-empty__title"><?php esc_html_e( 'No fields here yet', 'wp-custom-meta-box' ); ?></span>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: options page slug. */
								__( 'Add fields to a group whose location is "Options Page is %s" and they will appear here.', 'wp-custom-meta-box' ),
								$slug
							)
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( menu_page_url( self::SLUG_PREFIX . $slug, false ) ); ?>">
					<?php
					wp_nonce_field( self::NONCE, 'wpcmb_options_nonce' );

					$renderer->set_errors( $this->take_errors( $slug ) );

					foreach ( $groups as $group ) {
						echo '<section class="wpcmb-card wpcmb-options-group">';

						if ( '' !== $group->title && count( $groups ) > 1 ) {
							printf( '<h2>%s</h2>', esc_html( $group->title ) );
						}

						$renderer->group( $group, $ref );

						echo '</section>';
					}
					?>

					<p class="wpcmb-options-actions">
						<button type="submit" class="wpcmb-btn wpcmb-btn--primary wpcmb-btn--lg">
							<?php esc_html_e( 'Save options', 'wp-custom-meta-box' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Report the outcome of the last save.
	 *
	 * @param string $slug Options page slug.
	 */
	private function render_notice( string $slug ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag; the save itself verified its nonce.
		if ( ! isset( $_GET['wpcmb-saved'] ) ) {
			return;
		}

		$errors = $this->take_errors( $slug );

		if ( array() === $errors ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Options saved.', 'wp-custom-meta-box' )
			);

			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p><ul class="wpcmb-error-list">',
			esc_html__( 'Your options were saved, but some fields need attention:', 'wp-custom-meta-box' )
		);

		foreach ( $errors as $message ) {
			printf( '<li>%s</li>', esc_html( $message ) );
		}

		echo '</ul></div>';
	}

	/**
	 * The errors from the last save, read once.
	 *
	 * Read by both the notice and the renderer, so it is not consumed here:
	 * it expires on its own, and a page reloaded twice showing the same
	 * message is better than one showing it in only half the places.
	 *
	 * @param string $slug Options page slug.
	 *
	 * @return array<string, string>
	 */
	private function take_errors( string $slug ): array {
		$errors = get_transient( $this->result_key( $slug ) );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * The transient key holding one user's last result for a page.
	 *
	 * @param string $slug Options page slug.
	 */
	private function result_key( string $slug ): string {
		return 'wpcmb_options_result_' . get_current_user_id() . '_' . $slug;
	}

	/**
	 * The two-line label for one page in the admin menu.
	 *
	 * The name on one line and the slug under it. On a single line the two run
	 * together and the sidebar breaks the slug mid-word, which reads as a typo
	 * rather than as a name.
	 *
	 * WordPress prints a menu title as markup — that is how core's own update
	 * bubbles work — so the second line is a span. It is styled inline because
	 * the admin menu is on every screen in wp-admin while this plugin's
	 * stylesheet is deliberately only on its own; one label is not worth a
	 * stylesheet loaded everywhere.
	 *
	 * @param string $title Page title.
	 * @param string $slug  Options page slug.
	 */
	private static function menu_label( string $title, string $slug ): string {
		return sprintf(
			'%1$s<span style="display:block;font-size:11px;font-weight:400;line-height:1.5;opacity:0.65">(%2$s)</span>',
			esc_html( $title ),
			esc_html( $slug )
		);
	}

	/**
	 * The plugin's name, as WordPress knows it.
	 *
	 * Read from the plugin header so it cannot drift from what the Plugins
	 * screen says, and falls back to the menu's own wording when the header
	 * cannot be read — which is the case on a front-end request, where
	 * `get_plugin_data()` is not loaded.
	 */
	private static function plugin_name(): string {
		static $name = null;

		if ( null !== $name ) {
			return $name;
		}

		$name = __( 'Meta Boxes', 'wp-custom-meta-box' );

		if ( ! function_exists( 'get_plugin_data' ) ) {
			return $name;
		}

		$data = get_plugin_data( WPCMB_DIR . 'wp-custom-meta-box.php', false, false );

		if ( '' !== (string) ( $data['Name'] ?? '' ) ) {
			$name = (string) $data['Name'];
		}

		return $name;
	}

	/**
	 * A readable title for a slug that no group gave one to.
	 *
	 * @param string $slug Options page slug.
	 */
	private function humanise( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * The options page the current screen is, if it is one.
	 *
	 * Used by the asset loader, which has to know that a plugin screen can
	 * hold fields — without it the field styles and the save gate never load
	 * here and the page renders unstyled controls that nothing validates.
	 */
	public static function current_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which screen is being shown, not acting on it.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return str_starts_with( $page, self::SLUG_PREFIX )
			? substr( $page, strlen( self::SLUG_PREFIX ) )
			: '';
	}
}
