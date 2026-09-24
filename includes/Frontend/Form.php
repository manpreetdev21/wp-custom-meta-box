<?php
/**
 * Front-end form rendering.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Frontend;

use WPCMB\Abstracts\Module;
use WPCMB\Admin\Ajax;
use WPCMB\Admin\Assets;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\SubmissionPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Renders field groups as a public form.
 *
 * The form's own configuration — which group, what it creates, where it goes
 * afterwards, who gets notified — travels with the submission in a signed
 * field. Without the signature, any visitor could edit the markup and post
 * back `post_status=publish` or their own notification address; with it, the
 * server only ever acts on settings that it wrote itself.
 *
 * Fields are drawn by the same renderer the admin uses, so a field type
 * behaves identically on both sides and nothing has to be implemented twice.
 */
final class Form extends Module {

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'wpcmb_form';

	/**
	 * Request key holding the form's own state.
	 */
	public const STATE = 'wpcmb_form';

	/**
	 * Name of the honeypot field.
	 *
	 * Deliberately plausible: bots fill fields that look like real ones, and
	 * a field named `honeypot` is the one they learn to skip.
	 */
	public const HONEYPOT = 'wpcmb_website_url';

	/**
	 * Seconds a form must be on screen before a submission is believable.
	 */
	public const MIN_SECONDS = 2;

	/**
	 * How many forms have rendered this request, for unique ids.
	 *
	 * @var int
	 */
	private int $rendered = 0;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function shortcode( $atts ): string {
		return $this->render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * Default form configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Which field group to render.
			'group'        => '',

			/*
			 * What the submission does: store values against an object,
			 * create or update a post, or update the current user.
			 *
			 * `post` rather than `values`, because `values` needs an object to
			 * store against and the default `object` is `new` — together they
			 * described a form with nowhere to put anything, so a shortcode
			 * pasted with nothing but a group key rendered fine and then
			 * failed on submit with "That could not be saved". Creating a
			 * draft is the only thing a form can do without being told what
			 * it is editing.
			 */
			'action'       => 'post',

			/*
			 * For `post`: the type to create, and the status to create it
			 * with.
			 *
			 * An empty type means "this group's own submissions type", which
			 * cannot be named here because it is derived from the group and
			 * this list does not know which group it is for. render() fills it
			 * in before signing, so the submission handler receives a real
			 * name and the author can still override it with `post_type=""`.
			 *
			 * `publish` is honoured only for a post type nobody can view,
			 * which submissions are. A form pointed at real content still
			 * cannot publish it: see Submission::status_for().
			 */
			'post_type'    => '',
			'post_status'  => 'publish',
			'post_title'   => '1',

			// The object being edited. `new` creates one.
			'object'       => 'new',

			// Whether visitors who are not signed in may submit.
			'guests'       => '0',

			'submit_label' => '',
			'message'      => '',
			'redirect'     => '',
			'email'        => '',
			'ajax'         => '1',
		);
	}

	/**
	 * Render a form.
	 *
	 * @param array<string, mixed> $atts Form configuration.
	 */
	public function render( array $atts ): string {
		$config = shortcode_atts( self::defaults(), array_change_key_case( $atts ), self::SHORTCODE );
		$group  = $this->container->get( Repository::class )->get( (string) $config['group'] );

		if ( ! $group instanceof FieldGroup ) {
			return $this->notice( __( 'That field group does not exist.', 'wp-custom-meta-box' ) );
		}

		if ( ! $this->may_submit( $config ) ) {
			return $this->notice( __( 'You need to sign in to use this form.', 'wp-custom-meta-box' ) );
		}

		// Storing values needs something to store them against, and `new` is
		// not an object. Said here, at render time, because the alternative is
		// a form that looks right until the first person fills it in.
		if ( 'values' === $config['action'] && 'new' === $config['object'] ) {
			return $this->notice(
				__( 'This form has nowhere to store what it collects. Use action="post" to create a post, or object="post_12" to edit something that already exists.', 'wp-custom-meta-box' )
			);
		}

		$ref = $this->object_ref( $config );

		if ( 'new' !== $config['object'] && ! $this->may_edit( $config, $ref ) ) {
			return $this->notice( __( 'You do not have permission to edit this.', 'wp-custom-meta-box' ) );
		}

		// Derived here, where the group is known, and signed with the rest of
		// the configuration so the submission handler cannot be pointed at
		// some other post type by whoever is holding the form.
		if ( 'post' === $config['action'] && '' === (string) $config['post_type'] ) {
			$config['post_type'] = SubmissionPostType::post_type_for( $group );
		}

		++$this->rendered;

		$this->enqueue();

		$context = array(
			'config'   => $config,
			'group'    => $group,
			'ref'      => $ref,
			'form_id'  => 'wpcmb-form-' . $group->key . '-' . $this->rendered,
			'state'    => $this->sign( $config ),
			'renderer' => $this->container->get( Renderer::class ),
			'result'   => $this->last_result( $group->key ),
			'uploads'  => self::uploads_allowed(),
		);

		ob_start();

		/**
		 * Filters the template used to render a front-end form.
		 *
		 * Themes override by placing `wp-custom-meta-box/form.php` in the
		 * theme; this filter is for plugins that need to go further.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $template Absolute path to the template.
		 * @param array<string, mixed> $context  Template context.
		 */
		$template = (string) apply_filters( 'wpcmb/form/template', $this->locate_template(), $context );

		if ( is_readable( $template ) ) {
			// The template reads $context; extracting would let a future
			// context key silently shadow something the template relies on.
			include $template;
		}

		return (string) ob_get_clean();
	}

	/**
	 * The template path, preferring one supplied by the theme.
	 */
	private function locate_template(): string {
		$theme = locate_template( array( 'wp-custom-meta-box/form.php' ) );

		return '' !== $theme ? $theme : WPCMB_DIR . 'templates/form.php';
	}

	/**
	 * Load the field assets on a page that renders a form.
	 */
	private function enqueue(): void {
		wp_enqueue_style( 'wpcmb-fields', WPCMB_URL . 'assets/css/fields.css', array(), Assets::version( 'assets/css/fields.css' ) );
		wp_enqueue_style( 'wpcmb-form', WPCMB_URL . 'assets/css/form.css', array( 'wpcmb-fields' ), Assets::version( 'assets/css/form.css' ) );

		wp_enqueue_script( 'wpcmb-fields', WPCMB_URL . 'assets/js/fields.js', array(), Assets::version( 'assets/js/fields.js' ), true );
		wp_enqueue_script( 'wpcmb-form', WPCMB_URL . 'assets/js/form.js', array( 'wpcmb-fields' ), Assets::version( 'assets/js/form.js' ), true );
		wp_enqueue_script( Assets::CODES_HANDLE, WPCMB_URL . 'assets/js/codes.js', array(), Assets::version( 'assets/js/codes.js' ), true );
		wp_enqueue_script( Assets::ENHANCED_HANDLE, WPCMB_URL . 'assets/js/enhanced.js', array( 'wpcmb-fields', Assets::CODES_HANDLE ), Assets::version( 'assets/js/enhanced.js' ), true );
		wp_enqueue_script( Assets::REPEATER_HANDLE, WPCMB_URL . 'assets/js/repeater.js', array( 'wpcmb-fields' ), Assets::version( 'assets/js/repeater.js' ), true );

		wp_localize_script(
			'wpcmb-fields',
			'wpcmbFields',
			array(
				// No ajaxUrl and no Dashicons list: the embed preview endpoint
				// requires an editing capability, and the icon picker is an
				// admin control. Both degrade to the plain input out here.
				'i18n' => array(
					'remove'             => __( 'Remove', 'wp-custom-meta-box' ),
					'selectMedia'        => __( 'Select media', 'wp-custom-meta-box' ),
					'qrTooLong'          => __( 'That is too long to fit in a QR code.', 'wp-custom-meta-box' ),
					'barcodeUnsupported' => __( 'A barcode can only hold plain ASCII characters.', 'wp-custom-meta-box' ),

					// The multiple select draws itself from these, so a form
					// with one reads as an unlabelled box without them.
					'searchOptions'      => __( 'Search options', 'wp-custom-meta-box' ),
					'selectOptions'      => __( 'Select options', 'wp-custom-meta-box' ),
					/* translators: %d: number of options chosen. */
					'selectedCount'      => __( '%d selected', 'wp-custom-meta-box' ),
					'noMatches'          => __( 'No matches. Try a different search.', 'wp-custom-meta-box' ),
				),
			)
		);

		wp_localize_script(
			Assets::REPEATER_HANDLE,
			'wpcmbRepeater',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),

				/*
				 * No CSV out here. Importing and exporting rows in bulk is an
				 * editor's tool, and its endpoints require the editing
				 * capability — offering the buttons to a visitor would be
				 * offering two buttons that answer 403.
				 */
				'csv'     => false,
				'i18n'    => array(
					/* translators: %d: row number. Kept as a literal token so the script can substitute it. */
					'row'       => __( 'Row %d', 'wp-custom-meta-box' ),
					'rowFailed' => __( 'That row could not be added. Please try again.', 'wp-custom-meta-box' ),
				),
			)
		);

		wp_localize_script(
			'wpcmb-form',
			'wpcmbForm',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'submitting' => __( 'Sending…', 'wp-custom-meta-box' ),
					'failed'     => __( 'Something went wrong. Please try again.', 'wp-custom-meta-box' ),
				),
			)
		);
	}

	/**
	 * Sign the form configuration so the browser cannot change it.
	 *
	 * The configuration decides what the submission is allowed to do. It has
	 * to travel with the request, and it must not be editable in the page —
	 * otherwise a visitor could publish instead of drafting, redirect the
	 * notification email, or point the form at a different field group.
	 *
	 * @param array<string, mixed> $config Form configuration.
	 *
	 * @return array{payload: string, signature: string}
	 */
	public function sign( array $config ): array {
		$payload = (string) wp_json_encode( $config );

		return array(
			'payload'   => $payload,
			'signature' => self::signature( $payload ),
		);
	}

	/**
	 * The signature for a payload.
	 *
	 * @param string $payload Encoded configuration.
	 */
	public static function signature( string $payload ): string {
		// wp_hash() keys on the site's own salts, so a signature from one
		// site is meaningless on another and nothing needs storing.
		return wp_hash( 'wpcmb_form|' . $payload );
	}

	/**
	 * Verify and decode a signed configuration.
	 *
	 * @param string $payload   Encoded configuration.
	 * @param string $signature Claimed signature.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verify( string $payload, string $signature ): ?array {
		if ( ! hash_equals( self::signature( $payload ), $signature ) ) {
			return null;
		}

		$config = json_decode( $payload, true );

		if ( ! is_array( $config ) ) {
			return null;
		}

		// Only keys the plugin itself writes are honoured, so a signature
		// captured from one form cannot smuggle extra settings into another.
		return array_intersect_key( $config, self::defaults() ) + self::defaults();
	}

	/**
	 * Whether the current visitor may use this form at all.
	 *
	 * @param array<string, mixed> $config Form configuration.
	 */
	public static function may_submit( array $config ): bool {
		if ( is_user_logged_in() ) {
			return true;
		}

		return '1' === (string) ( $config['guests'] ?? '0' );
	}

	/**
	 * Whether the current visitor may edit the object a form targets.
	 *
	 * @param array<string, mixed> $config Form configuration.
	 * @param ObjectRef            $ref    Object reference.
	 */
	public static function may_edit( array $config, ObjectRef $ref ): bool {
		// Values inherit the permissions of the object they belong to, and
		// that rule lives in one place so the form, the edit screens and the
		// REST routes can never disagree about it.
		return Permissions::can_edit( $ref );
	}

	/**
	 * Whether file and image fields may upload on the front end.
	 *
	 * Off for anyone without `upload_files`. A public upload endpoint is a
	 * different security problem from a public form, and turning it on should
	 * be a deliberate decision with its own mime and size limits rather than
	 * something that arrives with a shortcode.
	 */
	public static function uploads_allowed(): bool {
		/**
		 * Filters whether a front-end form may accept uploads.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allowed Whether uploads are allowed.
		 */
		return (bool) apply_filters( 'wpcmb/form/allow_uploads', current_user_can( 'upload_files' ) );
	}

	/**
	 * The object a form targets.
	 *
	 * @param array<string, mixed> $config Form configuration.
	 */
	public function object_ref( array $config ): ObjectRef {
		$object = (string) $config['object'];

		if ( 'new' === $object ) {
			return new ObjectRef( ObjectRef::POST, 0 );
		}

		if ( 'current' === $object ) {
			return 'user' === $config['action']
				? new ObjectRef( ObjectRef::USER, get_current_user_id() )
				: ObjectRef::from( null );
		}

		return ObjectRef::from( $object );
	}

	/**
	 * The result of this visitor's last submission of a group, if any.
	 *
	 * Held in a transient keyed by group and session so a non-AJAX submission
	 * can redirect and still report what happened, without putting field
	 * values or error text in the URL.
	 *
	 * @param string $group_key Field group key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function last_result( string $group_key ): ?array {
		$key    = Submission::result_key( $group_key );
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * A message wrapped in the form's notice markup.
	 *
	 * @param string $message Message text.
	 */
	private function notice( string $message ): string {
		return sprintf( '<p class="wpcmb-form__notice">%s</p>', esc_html( $message ) );
	}
}
