<?php
/**
 * Import and export screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Exports field groups as JSON or PHP, and imports them back from JSON.
 *
 * JSON is the interchange format because it is exactly what is stored; PHP
 * export is a one-way convenience for putting groups under version control.
 */
final class ToolsPage extends Module {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wpcmb-tools';

	/**
	 * Nonce action for both forms.
	 */
	private const NONCE = 'wpcmb_tools';

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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'handle_request' ) );
	}

	/**
	 * Add the tools submenu.
	 */
	public function register_page(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Tools', 'wp-custom-meta-box' ),
			__( 'Tools', 'wp-custom-meta-box' ),
			FieldGroupPostType::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Dispatch an export or import request.
	 *
	 * Runs on admin_init so a JSON download can be streamed before any output.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below, once we know a request is ours.
		$action = isset( $_POST['wpcmb_tool'] ) ? sanitize_key( wp_unslash( $_POST['wpcmb_tool'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to use these tools.', 'wp-custom-meta-box' ) );
		}

		match ( $action ) {
			'export_json' => $this->export_json(),
			'import_json' => $this->import_json(),
			default       => null,
		};
	}

	/**
	 * Stream the selected groups as a JSON download.
	 */
	private function export_json(): void {
		$groups = $this->selected_groups();

		if ( array() === $groups ) {
			$this->redirect( 'nothing_selected' );
		}

		$payload = array_values( array_map( static fn( FieldGroup $g ): array => $g->to_array(), $groups ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wpcmb-field-groups-' . gmdate( 'Y-m-d' ) . '.json' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Import groups from an uploaded JSON file.
	 *
	 * A group whose key already exists is updated in place; anything else is
	 * created as a draft, so an import can never silently activate a group.
	 */
	private function import_json(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_request() verified the nonce before dispatching here.
		if ( empty( $_FILES['wpcmb_import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wpcmb_import']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A path, validated by is_uploaded_file().
			$this->redirect( 'import_failed' );
		}

		$contents = file_get_contents( $_FILES['wpcmb_import']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Local upload, not a remote request.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$decoded = is_string( $contents ) ? json_decode( $contents, true ) : null;

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			$this->redirect( 'import_failed' );
		}

		// Accept both a single exported group and a list of them.
		if ( isset( $decoded['key'] ) || isset( $decoded['fields'] ) ) {
			$decoded = array( $decoded );
		}

		$repository = $this->container->get( Repository::class );
		$imported   = 0;

		foreach ( $decoded as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$config   = FieldGroup::sanitize( $raw );
			$existing = $repository->get( $config['key'] );
			$post_id  = $existing instanceof FieldGroup ? $existing->id : 0;

			if ( 0 === $post_id ) {
				$post_id = wp_insert_post(
					array(
						'post_type'   => FieldGroupPostType::POST_TYPE,
						'post_status' => 'draft',
						'post_title'  => '' !== $config['title'] ? $config['title'] : __( 'Imported field group', 'wp-custom-meta-box' ),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					continue;
				}
			} else {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $config['title'],
					)
				);
			}

			$repository->save( (int) $post_id, $config );
			++$imported;
		}

		$this->redirect( 0 === $imported ? 'import_failed' : 'imported', $imported );
	}

	/**
	 * The groups checked on the export form.
	 *
	 * @return array<string, FieldGroup>
	 */
	private function selected_groups(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_request() verified the nonce before dispatching here.
		$keys = isset( $_POST['wpcmb_groups'] ) && is_array( $_POST['wpcmb_groups'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['wpcmb_groups'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$all = $this->container->get( Repository::class )->all( true );

		return array_intersect_key( $all, array_flip( $keys ) );
	}

	/**
	 * Redirect back to this screen with a result code.
	 *
	 * @param string $result Result code.
	 * @param int    $count  Optional count to report.
	 */
	private function redirect( string $result, int $count = 0 ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'wpcmb_result' => $result,
					'wpcmb_count'  => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the tools screen.
	 */
	public function render(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-custom-meta-box' ) );
		}

		$groups = $this->container->get( Repository::class )->all( true );

		$this->render_notice();
		?>
		<div class="wrap wpcmb-wrap">
			<h1><?php esc_html_e( 'Tools', 'wp-custom-meta-box' ); ?></h1>

			<div class="wpcmb-cards">
				<div class="wpcmb-card">
					<h2><?php esc_html_e( 'Export', 'wp-custom-meta-box' ); ?></h2>
					<p><?php esc_html_e( 'Download the selected field groups as JSON, or copy them as PHP.', 'wp-custom-meta-box' ); ?></p>

					<form method="post">
						<?php wp_nonce_field( self::NONCE ); ?>

						<?php if ( array() === $groups ) : ?>
							<p><em><?php esc_html_e( 'No field groups yet.', 'wp-custom-meta-box' ); ?></em></p>
						<?php else : ?>
							<fieldset class="wpcmb-fieldset">
								<?php foreach ( $groups as $group ) : ?>
									<label class="wpcmb-checkbox">
										<input type="checkbox" name="wpcmb_groups[]" value="<?php echo esc_attr( $group->key ); ?>" checked />
										<?php echo esc_html( '' !== $group->title ? $group->title : $group->key ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>

							<p>
								<button type="submit" class="button button-primary" name="wpcmb_tool" value="export_json">
									<?php esc_html_e( 'Download JSON', 'wp-custom-meta-box' ); ?>
								</button>
							</p>
						<?php endif; ?>
					</form>
				</div>

				<div class="wpcmb-card">
					<h2><?php esc_html_e( 'Import', 'wp-custom-meta-box' ); ?></h2>
					<p><?php esc_html_e( 'Upload a JSON export. Groups with a matching key are updated; new groups are created as drafts.', 'wp-custom-meta-box' ); ?></p>

					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( self::NONCE ); ?>
						<p><input type="file" name="wpcmb_import" accept="application/json,.json" required /></p>
						<p>
							<button type="submit" class="button button-primary" name="wpcmb_tool" value="import_json">
								<?php esc_html_e( 'Import', 'wp-custom-meta-box' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>

			<?php if ( array() !== $groups ) : ?>
				<h2><?php esc_html_e( 'PHP export', 'wp-custom-meta-box' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Drop this into your theme or plugin to register these groups in code.', 'wp-custom-meta-box' ); ?></p>
				<textarea class="widefat code wpcmb-php-export" rows="16" readonly><?php echo esc_textarea( $this->php_export( $groups ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build a PHP snippet that registers the given groups.
	 *
	 * @param array<string, FieldGroup> $groups Field groups.
	 */
	private function php_export( array $groups ): string {
		$lines = array( '<?php', '', "add_action( 'wpcmb/register_field_groups', function () {" );

		foreach ( $groups as $group ) {
			$exported = var_export( $group->to_array(), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Deliberate code generation.
			$exported = str_replace( array( 'array (', "\n" ), array( 'array(', "\n\t" ), $exported );
			$lines[]  = "\twpcmb_register_field_group( {$exported} );";
			$lines[]  = '';
		}

		$lines[] = '} );';

		return implode( "\n", $lines );
	}

	/**
	 * Show the result of the last import or export attempt.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags.
		$result = isset( $_GET['wpcmb_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcmb_result'] ) ) : '';
		$count  = isset( $_GET['wpcmb_count'] ) ? absint( wp_unslash( $_GET['wpcmb_count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'imported'         => array(
				'success',
				/* translators: %d: number of field groups imported. */
				sprintf( _n( '%d field group imported.', '%d field groups imported.', $count, 'wp-custom-meta-box' ), $count ),
			),
			'import_failed'    => array( 'error', __( 'That file could not be read as a field group export.', 'wp-custom-meta-box' ) ),
			'nothing_selected' => array( 'warning', __( 'Select at least one field group to export.', 'wp-custom-meta-box' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}
}
