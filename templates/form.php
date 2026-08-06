<?php
/**
 * Front-end form template.
 *
 * Override by copying this file to `wp-custom-meta-box/form.php` in your
 * theme. The one variable in scope is $context:
 *
 * - `config`   array   The verified form configuration.
 * - `group`    FieldGroup
 * - `ref`      ObjectRef  The object being edited, invalid when creating one.
 * - `form_id`  string  A unique id for this form on the page.
 * - `state`    array   `payload` and `signature` — must be output unchanged.
 * - `renderer` Renderer
 * - `result`   array|null  The outcome of this visitor's last submission.
 * - `uploads`  bool    Whether media fields may upload.
 *
 * @package WPCMB
 *
 * @var array<string, mixed> $context Template context.
 */

defined( 'ABSPATH' ) || exit;

$wpcmb_config = $context['config'];
$wpcmb_group  = $context['group'];
$wpcmb_result = $context['result'];
$wpcmb_time   = time();
?>
<div class="wpcmb-form-wrap" id="<?php echo esc_attr( $context['form_id'] ); ?>-wrap">

	<?php if ( is_array( $wpcmb_result ) ) : ?>
		<p class="wpcmb-form__notice wpcmb-form__notice--<?php echo $wpcmb_result['success'] ? 'success' : 'error'; ?>" role="status">
			<?php echo esc_html( (string) $wpcmb_result['message'] ); ?>
		</p>
	<?php endif; ?>

	<form
		class="wpcmb-form"
		id="<?php echo esc_attr( $context['form_id'] ); ?>"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		enctype="multipart/form-data"
		data-wpcmb-form
		data-wpcmb-ajax="<?php echo esc_attr( (string) $wpcmb_config['ajax'] ); ?>"
	>
		<input type="hidden" name="action" value="<?php echo esc_attr( WPCMB\Frontend\Form::SHORTCODE ); ?>" />
		<?php wp_nonce_field( WPCMB\Frontend\Submission::NONCE, 'wpcmb_form_nonce' ); ?>

		<?php
		/*
		 * The signed configuration. Editing either of these in the page makes
		 * the submission fail rather than letting it act on changed settings.
		 */
		?>
		<input type="hidden" name="wpcmb_form[payload]" value="<?php echo esc_attr( $context['state']['payload'] ); ?>" />
		<input type="hidden" name="wpcmb_form[signature]" value="<?php echo esc_attr( $context['state']['signature'] ); ?>" />
		<input type="hidden" name="wpcmb_form[t]" value="<?php echo esc_attr( (string) $wpcmb_time ); ?>" />
		<input type="hidden" name="wpcmb_form[ts]" value="<?php echo esc_attr( wp_hash( 'wpcmb_form_time|' . $wpcmb_time ) ); ?>" />

		<?php
		/*
		 * The honeypot. Hidden from people with CSS rather than the `hidden`
		 * attribute, because form-filling scripts skip `hidden` inputs but
		 * happily fill a visible-to-them text field. `aria-hidden` and
		 * `tabindex` keep it away from screen readers and keyboard users.
		 */
		?>
		<div class="wpcmb-form__hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $context['form_id'] ); ?>-hp">
				<?php esc_html_e( 'Leave this field empty', 'wp-custom-meta-box' ); ?>
			</label>
			<input
				type="text"
				id="<?php echo esc_attr( $context['form_id'] ); ?>-hp"
				name="<?php echo esc_attr( WPCMB\Frontend\Form::HONEYPOT ); ?>"
				value=""
				tabindex="-1"
				autocomplete="off"
			/>
		</div>

		<?php if ( 'post' === $wpcmb_config['action'] && '1' === (string) $wpcmb_config['post_title'] ) : ?>
			<div class="wpcmb-field wpcmb-field--text">
				<div class="wpcmb-field__label">
					<label for="<?php echo esc_attr( $context['form_id'] ); ?>-title">
						<?php esc_html_e( 'Title', 'wp-custom-meta-box' ); ?>
						<abbr class="wpcmb-required" title="<?php esc_attr_e( 'Required', 'wp-custom-meta-box' ); ?>">*</abbr>
					</label>
				</div>
				<div class="wpcmb-field__control">
					<input
						type="text"
						class="wpcmb-input"
						id="<?php echo esc_attr( $context['form_id'] ); ?>-title"
						name="wpcmb_post_title"
						value="<?php echo esc_attr( $context['ref']->is_valid() ? get_the_title( (int) $context['ref']->id ) : '' ); ?>"
						required
					/>
					<p class="wpcmb-field__error" role="alert" data-wpcmb-error-for="post_title" hidden></p>
				</div>
			</div>
		<?php endif; ?>

		<?php
		if ( is_array( $wpcmb_result ) && ! empty( $wpcmb_result['errors'] ) ) {
			$context['renderer']->set_errors( (array) $wpcmb_result['errors'] );
		}

		$context['renderer']->group( $wpcmb_group, $context['ref'] );
		?>

		<?php if ( ! $context['uploads'] ) : ?>
			<p class="wpcmb-field__note">
				<?php esc_html_e( 'File and image fields are read-only here because uploading is not permitted for your account.', 'wp-custom-meta-box' ); ?>
			</p>
		<?php endif; ?>

		<p class="wpcmb-form__actions">
			<button type="submit" class="wpcmb-form__submit">
				<?php
				echo esc_html(
					'' !== (string) $wpcmb_config['submit_label']
						? (string) $wpcmb_config['submit_label']
						: __( 'Submit', 'wp-custom-meta-box' )
				);
				?>
			</button>
			<span class="wpcmb-form__status" role="status" aria-live="polite"></span>
		</p>
	</form>
</div>
