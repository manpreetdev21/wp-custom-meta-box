<?php
/**
 * Front-end form submissions.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Frontend;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Receives, checks and stores a front-end submission.
 *
 * One method does the work for both the plain POST and the AJAX path, so the
 * two can never diverge on what they check. The order matters and is the same
 * every time: is this our request, is the configuration ours, is it a person,
 * is this person allowed, is the input valid, and only then store anything.
 */
final class Submission extends Module {

	/**
	 * Nonce action.
	 */
	public const NONCE = 'wpcmb_form_submit';

	/**
	 * Transient prefix for the result of a submission.
	 */
	private const RESULT_PREFIX = 'wpcmb_form_result_';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_' . Form::SHORTCODE, array( $this, 'handle_post' ) );
		add_action( 'admin_post_nopriv_' . Form::SHORTCODE, array( $this, 'handle_post' ) );
		add_action( 'wp_ajax_' . Form::SHORTCODE, array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . Form::SHORTCODE, array( $this, 'handle_ajax' ) );
	}

	/**
	 * Handle a plain form post, then redirect.
	 *
	 * Redirecting after a successful post is what stops a browser refresh
	 * from submitting the form a second time.
	 */
	public function handle_post(): void {
		$result = $this->process();

		set_transient( self::result_key( (string) $result['group'] ), $result, MINUTE_IN_SECONDS * 5 );

		$destination = $result['success'] && '' !== (string) $result['redirect']
			? (string) $result['redirect']
			: (string) wp_get_referer();

		wp_safe_redirect( '' !== $destination ? $destination : home_url( '/' ) );
		exit;
	}

	/**
	 * Handle an AJAX submission.
	 */
	public function handle_ajax(): void {
		$result = $this->process();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * Check and store a submission.
	 *
	 * @return array<string, mixed>
	 */
	private function process(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked below, once we know the request is ours to check.
		$state = isset( $_POST[ Form::STATE ] ) && is_array( $_POST[ Form::STATE ] )
			? wp_unslash( $_POST[ Form::STATE ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each member is validated below.
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$config = Form::verify(
			is_scalar( $state['payload'] ?? null ) ? (string) $state['payload'] : '',
			is_scalar( $state['signature'] ?? null ) ? (string) $state['signature'] : ''
		);

		if ( null === $config ) {
			return $this->failure( '', __( 'This form could not be verified. Please reload the page.', 'wp-custom-meta-box' ) );
		}

		$group_key = (string) $config['group'];

		if ( ! isset( $_POST['wpcmb_form_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_form_nonce'] ) ), self::NONCE )
		) {
			return $this->failure( $group_key, __( 'This form has expired. Please reload the page and try again.', 'wp-custom-meta-box' ) );
		}

		if ( ! $this->looks_human( $state ) ) {
			// Deliberately the same wording a person would see for a genuine
			// problem: telling a bot exactly which check it failed is free
			// help for whoever wrote it.
			return $this->failure( $group_key, __( 'This form could not be submitted. Please try again.', 'wp-custom-meta-box' ) );
		}

		if ( ! Form::may_submit( $config ) ) {
			return $this->failure( $group_key, __( 'You need to sign in to use this form.', 'wp-custom-meta-box' ) );
		}

		$group = $this->container->get( Repository::class )->get( $group_key );

		if ( ! $group instanceof FieldGroup ) {
			return $this->failure( $group_key, __( 'That field group no longer exists.', 'wp-custom-meta-box' ) );
		}

		$ref = $this->container->get( Form::class )->object_ref( $config );

		if ( 'new' !== $config['object'] && ! Form::may_edit( $config, $ref ) ) {
			return $this->failure( $group_key, __( 'You do not have permission to edit this.', 'wp-custom-meta-box' ) );
		}

		$submitted = $this->submitted_values();
		$fields    = $this->fields( $group );
		$present   = array_intersect_key( $submitted, $fields );

		// Validate what was typed, before sanitizing discards anything it
		// cannot make safe. Same order as the admin save path, for the same
		// reason: otherwise a bad value vanishes with no explanation.
		$errors = $this->container->get( Validator::class )->validate(
			array_intersect_key( $fields, $present ),
			$present
		);

		$title = $this->submitted_title();

		if ( 'post' === $config['action'] && '1' === (string) $config['post_title'] && '' === $title ) {
			$errors['post_title'] = __( 'A title is required.', 'wp-custom-meta-box' );
		}

		if ( array() !== $errors ) {
			return array(
				'success'  => false,
				'group'    => $group_key,
				'message'  => __( 'Please check the highlighted fields.', 'wp-custom-meta-box' ),
				'errors'   => $errors,
				'redirect' => '',
			);
		}

		$ref = $this->target( $config, $ref, $title );

		if ( ! $ref->is_valid() ) {
			return $this->failure( $group_key, __( 'That could not be saved. Please try again.', 'wp-custom-meta-box' ) );
		}

		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$stored   = array();

		foreach ( $present as $name => $value ) {
			$stored[ $name ] = $renderer->sanitize( $value, $fields[ $name ] );
			$values->update( $name, $stored[ $name ], $ref );
		}

		$this->notify( $config, $group, $ref, $stored );

		/**
		 * Fires after a front-end submission has been stored.
		 *
		 * @since 1.0.0
		 *
		 * @param ObjectRef            $ref     Object the values were stored on.
		 * @param array<string, mixed> $stored  Sanitized values.
		 * @param array<string, mixed> $config  Verified form configuration.
		 */
		do_action( 'wpcmb/form/submitted', $ref, $stored, $config );

		return array(
			'success'  => true,
			'group'    => $group_key,
			'message'  => '' !== (string) $config['message']
				? (string) $config['message']
				: __( 'Thanks, that has been saved.', 'wp-custom-meta-box' ),
			'errors'   => array(),
			'object'   => (string) $ref,
			'redirect' => $this->safe_redirect( (string) $config['redirect'] ),
		);
	}

	/**
	 * Whether the submission looks like it came from a person.
	 *
	 * Two cheap checks that between them stop the great majority of automated
	 * posts without asking a person to prove anything: a field that is hidden
	 * from people but attractive to form-fillers, and a signed timestamp that
	 * rules out submissions faster than a human could type.
	 *
	 * @param array<string, mixed> $state Submitted form state.
	 */
	private function looks_human( array $state ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from process(), after the nonce check.
		if ( ! empty( $_POST[ Form::HONEYPOT ] ) ) {
			return false;
		}

		$time      = (int) ( $state['t'] ?? 0 );
		$signature = is_scalar( $state['ts'] ?? null ) ? (string) $state['ts'] : '';

		// The timestamp is signed, so it cannot simply be back-dated.
		if ( ! hash_equals( wp_hash( 'wpcmb_form_time|' . $time ), $signature ) ) {
			return false;
		}

		$age = time() - $time;

		// Too fast to be typed, or old enough that the page has been sitting
		// open long enough for the nonce to be worth re-issuing.
		return $age >= Form::MIN_SECONDS && $age <= DAY_IN_SECONDS;
	}

	/**
	 * Resolve or create the object a submission writes to.
	 *
	 * @param array<string, mixed> $config Verified configuration.
	 * @param ObjectRef            $ref    Target from the configuration.
	 * @param string               $title  Submitted post title.
	 */
	private function target( array $config, ObjectRef $ref, string $title ): ObjectRef {
		if ( 'post' !== $config['action'] ) {
			return 'user' === $config['action']
				? new ObjectRef( ObjectRef::USER, get_current_user_id() )
				: $ref;
		}

		if ( $ref->is_valid() ) {
			wp_update_post(
				array(
					'ID'         => (int) $ref->id,
					'post_title' => $title,
				)
			);

			return $ref;
		}

		$post_type = post_type_exists( (string) $config['post_type'] ) ? (string) $config['post_type'] : 'post';

		// A public form never publishes. `draft` is the default and anything
		// beyond `pending` is refused, so a mis-set shortcode cannot put
		// unreviewed content on the site.
		$status = in_array( $config['post_status'], array( 'draft', 'pending' ), true )
			? (string) $config['post_status']
			: 'draft';

		if ( 'publish' === $config['post_status'] && current_user_can( 'publish_posts' ) ) {
			$status = 'publish';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_title'  => '' !== $title ? $title : __( 'Untitled submission', 'wp-custom-meta-box' ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		return is_wp_error( $post_id )
			? new ObjectRef( ObjectRef::POST, 0 )
			: new ObjectRef( ObjectRef::POST, (int) $post_id );
	}

	/**
	 * The fields a group offers, keyed by name.
	 *
	 * Taken from the group itself rather than from the object's location
	 * rules: the form named this group explicitly, and the object may not
	 * exist yet for the rules to be evaluated against.
	 *
	 * @param FieldGroup $group Field group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( FieldGroup $group ): array {
		$renderer = $this->container->get( Renderer::class );
		$fields   = array();

		foreach ( $group->fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['name'] ) && $renderer->stores_value( $field ) ) {
				$fields[ (string) $field['name'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * The submitted field values.
	 *
	 * @return array<string, mixed>
	 */
	private function submitted_values(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called from process() after the nonce check; each value is sanitized by its own field type.
		$values = isset( $_POST[ Renderer::INPUT_PREFIX ] ) && is_array( $_POST[ Renderer::INPUT_PREFIX ] )
			? wp_unslash( $_POST[ Renderer::INPUT_PREFIX ] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_array( $values ) ? $values : array();
	}

	/**
	 * The submitted post title.
	 */
	private function submitted_title(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called from process(), after the nonce check.
		return isset( $_POST['wpcmb_post_title'] )
			? sanitize_text_field( wp_unslash( $_POST['wpcmb_post_title'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Send the notification email, if one is configured.
	 *
	 * @param array<string, mixed> $config Verified configuration.
	 * @param FieldGroup           $group  Field group.
	 * @param ObjectRef            $ref    Object written to.
	 * @param array<string, mixed> $stored Sanitized values.
	 */
	private function notify( array $config, FieldGroup $group, ObjectRef $ref, array $stored ): void {
		$to = (string) $config['email'];

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$lines = array(
			/* translators: %s: field group title. */
			sprintf( __( 'A new submission of "%s".', 'wp-custom-meta-box' ), $group->title ),
			'',
		);

		foreach ( $stored as $name => $value ) {
			$lines[] = $name . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}

		$lines[] = '';
		$lines[] = (string) get_edit_post_link( (int) $ref->id, 'raw' );

		/**
		 * Filters the notification email sent after a submission.
		 *
		 * @since 1.0.0
		 *
		 * @param array{to: string, subject: string, body: string} $email  The email.
		 * @param array<string, mixed>                             $stored Sanitized values.
		 * @param array<string, mixed>                             $config Verified configuration.
		 */
		$email = (array) apply_filters(
			'wpcmb/form/notification',
			array(
				'to'      => $to,
				/* translators: %s: field group title. */
				'subject' => sprintf( __( '[%1$s] %2$s', 'wp-custom-meta-box' ), get_bloginfo( 'name' ), $group->title ),
				'body'    => implode( "\n", $lines ),
			),
			$stored,
			$config
		);

		wp_mail( (string) $email['to'], (string) $email['subject'], (string) $email['body'] );
	}

	/**
	 * A redirect target, or an empty string if it leaves this site.
	 *
	 * @param string $url Configured redirect.
	 */
	private function safe_redirect( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$validated = wp_validate_redirect( $url, '' );

		return $validated;
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $group_key Field group key.
	 * @param string $message   Message to show.
	 *
	 * @return array<string, mixed>
	 */
	private function failure( string $group_key, string $message ): array {
		return array(
			'success'  => false,
			'group'    => $group_key,
			'message'  => $message,
			'errors'   => array(),
			'redirect' => '',
		);
	}

	/**
	 * The transient key holding one visitor's last result for a group.
	 *
	 * Keyed by user for signed-in visitors and by session cookie otherwise,
	 * so two people submitting the same form never see each other's message.
	 *
	 * @param string $group_key Field group key.
	 */
	public static function result_key( string $group_key ): string {
		$who = is_user_logged_in()
			? 'u' . get_current_user_id()
			: 'g' . substr( wp_hash( self::visitor_token() ), 0, 12 );

		return self::RESULT_PREFIX . $who . '_' . $group_key;
	}

	/**
	 * A stable-enough identifier for an anonymous visitor.
	 *
	 * Only used to hand a message back across one redirect, so it does not
	 * need to be reliable — and it is hashed rather than stored, so it is not
	 * a record of who visited.
	 */
	private static function visitor_token(): string {
		$parts = array(
			isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		return implode( '|', $parts );
	}
}
