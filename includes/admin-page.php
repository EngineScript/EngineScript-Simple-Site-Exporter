<?php
/**
 * Admin page: menu registration, asset enqueueing, page rendering, notices.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Prevent direct execution of this component.
 *
 * @psalm-suppress ParadoxicalCondition Files may be requested outside the loaded plugin bootstrap.
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Records the exact hook suffix returned for the current exporter page.
 *
 * @since 2.1.1
 * @param string|false $hook_suffix Registered page hook, or false on failure.
 * @return void
 */
function sse_set_exporter_page_hook_suffix( string|false $hook_suffix ): void {
	if ( false === $hook_suffix || '' === $hook_suffix ) {
		unset( $GLOBALS['sse_exporter_page_hook_suffix'] );
		return;
	}

	$GLOBALS['sse_exporter_page_hook_suffix'] = $hook_suffix;
}

/**
 * Gets the exact registered hook suffix for this request.
 *
 * @since 2.1.1
 * @return string Registered page hook, or an empty string when unavailable.
 */
function sse_get_exporter_page_hook_suffix(): string {
	return sse_normalize_string_value( $GLOBALS['sse_exporter_page_hook_suffix'] ?? '' );
}

/**
 * Adds the Site Exporter page beneath Tools on a single-site installation.
 *
 * @since 1.0.0
 * @return void
 */
function sse_admin_menu(): void {
	if ( is_multisite() ) {
		return;
	}

	$page_hook = add_management_page(
		__( 'EngineScript Site Exporter', 'enginescript-site-exporter' ), // Page title (escaped by WordPress core).
		__( 'Site Exporter', 'enginescript-site-exporter' ),               // Menu title (escaped by WordPress core).
		sse_get_exporter_menu_capability(), // Capability required.
		'enginescript-site-exporter',
		'sse_exporter_page_html'
	);
	sse_set_exporter_page_hook_suffix( $page_hook );
}

/**
 * Adds one network-level Site Exporter page beneath Network Settings.
 *
 * @since 2.1.1
 * @return void
 */
function sse_network_admin_menu(): void {
	if ( ! is_multisite() ) {
		return;
	}

	$page_hook = add_submenu_page(
		'settings.php',
		__( 'EngineScript Site Exporter', 'enginescript-site-exporter' ),
		__( 'Site Exporter', 'enginescript-site-exporter' ),
		sse_get_exporter_menu_capability(),
		'enginescript-site-exporter',
		'sse_exporter_page_html'
	);
	sse_set_exporter_page_hook_suffix( $page_hook );
}

/**
 * Enqueues admin CSS and JS on the Site Exporter page only.
 *
 * @since 2.0.0
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function sse_enqueue_admin_assets( string $hook_suffix ): void {
	$exporter_page_hook = sse_get_exporter_page_hook_suffix();
	if ( '' === $exporter_page_hook || $exporter_page_hook !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'sse-admin',
		plugin_dir_url( SSE_PLUGIN_FILE ) . 'css/admin.css',
		[],
		ES_SITE_EXPORTER_VERSION
	);

	wp_enqueue_script(
		'sse-admin',
		plugin_dir_url( SSE_PLUGIN_FILE ) . 'js/admin.js',
		[],
		ES_SITE_EXPORTER_VERSION,
		[
			'in_footer' => true,
			'strategy'  => 'defer',
		]
	);
}

/**
 * Gets the current user's exporter notice transient key.
 *
 * @since 2.0.0
 * @return string Notice transient key.
 */
function sse_get_exporter_notice_key(): string {
	return 'sse_exporter_notice_' . get_current_user_id();
}

/**
 * Stores an exporter notice for display after admin-post redirects.
 *
 * @since 2.0.0
 * @param array<string, mixed> $notice Notice data.
 * @return void
 */
function sse_set_exporter_notice( array $notice ): void {
	set_transient( sse_get_exporter_notice_key(), $notice, 10 * MINUTE_IN_SECONDS );
}

/**
 * Renders any pending exporter notice.
 *
 * @since 2.0.0
 * @return void
 */
function sse_render_exporter_notices(): void {
	$notice = sse_normalize_array_value( get_transient( sse_get_exporter_notice_key() ) );
	if ( [] === $notice ) {
		return;
	}

	delete_transient( sse_get_exporter_notice_key() );

	$type    = isset( $notice['type'] ) && is_string( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'info';
	$message = isset( $notice['message'] ) && is_string( $notice['message'] ) ? $notice['message'] : '';

	if ( 'export_success' === $type && isset( $notice['zip_result'] ) && is_array( $notice['zip_result'] ) ) {
		$zip_result = $notice['zip_result'];
		if ( isset( $zip_result['filename'], $zip_result['filepath'] ) && is_string( $zip_result['filename'] ) && is_string( $zip_result['filepath'] ) ) {
			sse_render_export_success_notice(
				[
					'filename' => $zip_result['filename'],
					'filepath' => $zip_result['filepath'],
				]
			);
			return;
		}
	}

	$notice_class = 'error' === $type ? 'notice-error' : 'notice-success';
	?>
	<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
		<p><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
}

/**
 * Renders a successful export notice with download and delete actions.
 *
 * @since 2.0.0
 * @param array{filename: string, filepath: string} $zip_result The ZIP file information.
 * @return void
 */
function sse_render_export_success_notice( array $zip_result ): void {
	$export_dir_name = wp_basename( dirname( $zip_result['filepath'] ) );
	$validation      = sse_validate_basic_export_file( $zip_result['filename'], $export_dir_name );
	if (
		! sse_is_export_private_directory_name( $export_dir_name )
		|| wp_basename( $zip_result['filepath'] ) !== $zip_result['filename']
		|| is_wp_error( $validation )
		|| wp_normalize_path( $validation['filepath'] ) !== wp_normalize_path( $zip_result['filepath'] )
	) {
		sse_log( 'Skipped export success actions because the private file contract was invalid.', 'security' );
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'The export was created, but secure download controls could not be generated.', 'enginescript-site-exporter' ); ?></p>
		</div>
		<?php
		return;
	}

	$download_args         = [
		'action'     => 'sse_secure_download',
		'file'       => $zip_result['filename'],
		'export_dir' => $export_dir_name,
	];
	$download_nonce_action = 'sse_secure_download_' . $zip_result['filename'] . '_' . $export_dir_name;

	$download_url = wp_nonce_url(
		add_query_arg(
			$download_args,
			admin_url( 'admin-post.php' )
		),
		$download_nonce_action
	);

	$display_zip_path = wp_normalize_path( $zip_result['filepath'] );
	$delete_confirm   = __( 'Are you sure you want to delete this export file?', 'enginescript-site-exporter' );
	$delete_nonce     = 'sse_delete_export_' . $zip_result['filename'] . '_' . $export_dir_name;
	?>
	<div class="notice notice-success is-dismissible">
		<div class="sse-notice-actions">
			<span><?php esc_html_e( 'Site export successfully created!', 'enginescript-site-exporter' ); ?></span>
			<a href="<?php echo esc_url( $download_url ); ?>" class="button sse-action-button">
				<?php esc_html_e( 'Download Export File', 'enginescript-site-exporter' ); ?>
			</a>
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="sse-inline-form sse-confirm-delete"
					data-sse-confirm-message="<?php echo esc_attr( $delete_confirm ); ?>"
				>
					<input type="hidden" name="action" value="sse_delete_export">
					<input type="hidden" name="file" value="<?php echo esc_attr( $zip_result['filename'] ); ?>">
					<input type="hidden" name="export_dir" value="<?php echo esc_attr( $export_dir_name ); ?>">
					<?php wp_nonce_field( $delete_nonce ); ?>
					<button type="submit" class="button button-secondary sse-action-button">
						<?php esc_html_e( 'Delete Export File', 'enginescript-site-exporter' ); ?>
					</button>
				</form>
		</div>
		<p><small>
			<?php
			printf(
				/* translators: %s: file path */
				esc_html__( 'Private file location: %s', 'enginescript-site-exporter' ),
				'<code>' . esc_html( $display_zip_path ) . '</code>'
			);
			?>
		</small></p>
	</div>
	<?php
}

/**
 * Renders the exporter page HTML interface.
 *
 * @since 1.0.0
 * @return void
 */
function sse_exporter_page_html(): void {
	if ( ! sse_current_user_can_export_site() ) {
		sse_wp_die( __( 'You do not have permission to view this page.', 'enginescript-site-exporter' ), 403 );
	}

	$export_dir_path = sse_get_export_directory_path();
	if ( is_wp_error( $export_dir_path ) ) {
		sse_wp_die( $export_dir_path->get_error_message() );
	}
	$display_path = wp_normalize_path( $export_dir_path );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php sse_render_exporter_notices(); ?>
		<p><?php esc_html_e( 'Click the button below to generate an EngineScript-compatible site archive containing your WordPress files and the database.', 'enginescript-site-exporter' ); ?></p>
		<p><strong><?php esc_html_e( 'Warning:', 'enginescript-site-exporter' ); ?></strong> <?php esc_html_e( 'This can take a long time and consume significant server resources, especially on large sites. Ensure your server has sufficient disk space and execution time.', 'enginescript-site-exporter' ); ?></p>
		<p class="sse-section-spacing">
			<?php
			// printf is standard in WordPress for translatable strings with placeholders. All variables are escaped.
			printf(
				// translators: %s: directory path.
				esc_html__( 'Exported ZIP files will be staged in the following private server directory: %s', 'enginescript-site-exporter' ),
				'<code>' . esc_html( $display_path ) . '</code>'
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sse-section-spacing">
			<?php wp_nonce_field( 'sse_export_action', 'sse_export_nonce' ); ?>
			<input type="hidden" name="action" value="sse_export_site">

			<table class="form-table sse-form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sse_max_file_size"><?php esc_html_e( 'Maximum File Size', 'enginescript-site-exporter' ); ?></label>
						</th>
						<td>
							<select name="sse_max_file_size" id="sse_max_file_size">
								<option value="0"><?php esc_html_e( 'No per-file limit', 'enginescript-site-exporter' ); ?></option>
								<option value="104857600"><?php esc_html_e( '100 MiB', 'enginescript-site-exporter' ); ?></option>
								<option value="524288000"><?php esc_html_e( '500 MiB', 'enginescript-site-exporter' ); ?></option>
								<option value="1073741824"><?php esc_html_e( '1 GiB', 'enginescript-site-exporter' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Files larger than this size are excluded. "No per-file limit" removes this size filter, but other exclusions and overall export limits still apply.', 'enginescript-site-exporter' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Export Site', 'enginescript-site-exporter' ) ); ?>
		</form>
		<hr>
		<p>
			<?php esc_html_e( 'This plugin is part of the EngineScript project.', 'enginescript-site-exporter' ); ?>
			<a href="https://github.com/EngineScript/EngineScript" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Visit the EngineScript GitHub page', 'enginescript-site-exporter' ); ?>
			</a>
		</p>
		<p class="sse-warning-text">
			<?php esc_html_e( 'Security Notice:', 'enginescript-site-exporter' ); ?>
			<?php esc_html_e( 'The exported ZIP file is available only to authorized administrators. Automatic deletion is scheduled for 5 minutes after creation, but may be delayed if WordPress cron does not run. Download the file promptly and delete it when finished.', 'enginescript-site-exporter' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Shows an error notice to the user.
 *
 * @since 1.0.0
 * @param string $message The error message to display.
 * @return void
 */
function sse_show_error_notice( string $message ): void {
	sse_set_exporter_notice(
		[
			'type'    => 'error',
			'message' => $message,
		]
	);
	sse_log( 'Export error: ' . $message, 'error' );
}

/**
 * Shows a success notice to the user.
 *
 * @since 1.0.0
 * @param array{filename: string, filepath: string} $zip_result The ZIP file information.
 * @return void
 */
function sse_show_success_notice( array $zip_result ): void {
	sse_set_exporter_notice(
		[
			'type'       => 'export_success',
			'zip_result' => $zip_result,
		]
	);
	sse_log( 'Export successful. File saved to ' . $zip_result['filepath'], 'info' );
}
