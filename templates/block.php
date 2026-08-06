<?php
/**
 * Default block template.
 *
 * A block renders through this until a theme supplies its own, so a new block
 * shows its content immediately rather than an empty region that looks broken.
 *
 * Override per block by copying this to your theme as
 * `wp-custom-meta-box/blocks/{block-name}.php`, or for every block as
 * `wp-custom-meta-box/block.php`. Or filter `wpcmb/block/template`.
 *
 * $context holds:
 *
 * - `group`      FieldGroup
 * - `fields`     array   Formatted values keyed by field name.
 * - `attributes` array   Block attributes.
 * - `content`    string  Inner blocks output, empty when not enabled.
 * - `is_preview` bool    Whether this is an editor preview.
 * - `class_name` string  A class for the wrapper.
 *
 * `wpcmb_get_field( 'name' )` also works here and returns this block's value.
 *
 * @package WPCMB
 *
 * @var array<string, mixed> $context Template context.
 */

defined( 'ABSPATH' ) || exit;

$wpcmb_fields = (array) $context['fields'];
$wpcmb_labels = array();

foreach ( $context['group']->fields as $wpcmb_field ) {
	if ( is_array( $wpcmb_field ) && ! empty( $wpcmb_field['name'] ) ) {
		$wpcmb_labels[ (string) $wpcmb_field['name'] ] = (string) ( $wpcmb_field['label'] ?? $wpcmb_field['name'] );
	}
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => $context['class_name'] ) ) ); ?>>

	<?php if ( array() === array_filter( $wpcmb_fields, static fn( $v ): bool => '' !== $v && array() !== $v && null !== $v ) ) : ?>
		<?php if ( $context['is_preview'] ) : ?>
			<p class="wpcmb-block__placeholder">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: block title. */
						__( '%s — switch to Edit to fill this in.', 'wp-custom-meta-box' ),
						$context['group']->title
					)
				);
				?>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<dl class="wpcmb-block__fields">
			<?php foreach ( $wpcmb_fields as $wpcmb_name => $wpcmb_value ) : ?>
				<?php if ( '' === $wpcmb_value || null === $wpcmb_value || array() === $wpcmb_value ) : ?>
					<?php continue; ?>
				<?php endif; ?>

				<dt class="wpcmb-block__label"><?php echo esc_html( $wpcmb_labels[ $wpcmb_name ] ?? $wpcmb_name ); ?></dt>
				<dd class="wpcmb-block__value">
					<?php
					if ( is_scalar( $wpcmb_value ) ) {
						echo esc_html( (string) $wpcmb_value );
					} else {
						/*
						 * Structured values (repeaters, groups, links) have no
						 * single sensible default rendering, so the fallback
						 * shows their shape rather than guessing at markup a
						 * theme would have to undo.
						 */
						echo '<code>' . esc_html( (string) wp_json_encode( $wpcmb_value ) ) . '</code>';
					}
					?>
				</dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>

	<?php
	// Inner blocks, when the block allows them. Already-rendered block output.
	echo wp_kses_post( (string) $context['content'] );
	?>
</div>
