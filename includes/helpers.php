<?php
/**
 * Helper utilities: logging, IP detection, export paths, redirects, and filesystem initialization.
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
 * Safely gets the client IP address.
 *
 * @since 1.0.0
 * @return string Client IP address or 'unknown' if not available.
 */
function sse_get_client_ip(): string {
	$client_ip = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );

	if ( is_string( $client_ip ) ) {
		return $client_ip;
	}

	return 'unknown';
}

/**
 * Narrows a value returned by an untyped WordPress boundary to an array.
 *
 * @since 2.1.1
 * @param mixed $value Value to normalize.
 * @return array<array-key, mixed> The supplied array, or an empty array.
 */
function sse_normalize_array_value( mixed $value ): array {
	return is_array( $value ) ? $value : [];
}

/**
 * Narrows a value returned by an untyped WordPress boundary to a string.
 *
 * @since 2.1.1
 * @param mixed  $value    Value to normalize.
 * @param string $fallback Value returned when the supplied value is not a string.
 * @return string The supplied string or fallback.
 */
function sse_normalize_string_value( mixed $value, string $fallback = '' ): string {
	return is_string( $value ) ? $value : $fallback;
}

/**
 * Narrows a numeric boundary value to a non-negative integer.
 *
 * @since 2.1.1
 * @param mixed $value Value to normalize.
 * @return int|false Non-negative integer, or false for an invalid value.
 */
function sse_normalize_nonnegative_integer( mixed $value ): int|false {
	if ( ! is_numeric( $value ) ) {
		return false;
	}

	$integer = (int) $value;
	return $integer >= 0 ? $integer : false;
}

/**
 * Narrows a filesystem metadata value to its supported scalar types.
 *
 * @since 2.1.1
 * @param mixed $value Value returned by the WordPress Filesystem API.
 * @return int|string|false Supported metadata value, or false.
 */
function sse_normalize_filesystem_scalar( mixed $value ): int|string|false {
	return is_int( $value ) || is_string( $value ) ? $value : false;
}

/**
 * Stores important log messages in the database for review.
 *
 * @since 1.0.0
 * @param string $message The log message.
 * @param string $level   The log level.
 * @return void
 */
function sse_store_log_in_database( string $message, string $level ): void {
	$logs = sse_get_retained_stored_logs( get_option( 'sse_error_logs', [] ), time() - ( 7 * DAY_IN_SECONDS ) );

	$logs[] = [
		'time'    => time(),
		'level'   => sanitize_key( $level ),
		'message' => sse_sanitize_stored_log_message( $message ),
		'user_id' => get_current_user_id(),
		'ip'      => sse_get_client_ip(),
	];

	// Keep only the most recent 20 logs.
	if ( count( $logs ) > 20 ) {
		$logs = array_slice( $logs, -20 );
	}

	update_option( 'sse_error_logs', $logs, false );
}

/**
 * Normalizes stored error/security logs and removes expired records.
 *
 * @since 2.1.1
 * @param mixed $stored_logs Untrusted option value.
 * @param int   $cutoff      Oldest retained Unix timestamp.
 * @return array<int,array{time:int,level:string,message:string,user_id:int,ip:string}> Retained records.
 */
function sse_get_retained_stored_logs( mixed $stored_logs, int $cutoff ): array {
	$logs     = sse_normalize_array_value( $stored_logs );
	$retained = [];

	foreach ( $logs as $log ) {
		if (
			! is_array( $log )
			|| ! isset( $log['time'], $log['level'], $log['message'], $log['user_id'], $log['ip'] )
			|| ! is_numeric( $log['time'] )
			|| (int) $log['time'] < $cutoff
			|| ! is_string( $log['level'] )
			|| ! is_string( $log['message'] )
			|| ! is_numeric( $log['user_id'] )
			|| ! is_string( $log['ip'] )
		) {
			continue;
		}

		$retained[] = [
			'time'    => (int) $log['time'],
			'level'   => sanitize_key( $log['level'] ),
			'message' => sse_sanitize_stored_log_message( $log['message'] ),
			'user_id' => (int) $log['user_id'],
			'ip'      => sanitize_text_field( $log['ip'] ),
		];
	}

	return array_slice( $retained, -20 );
}

/**
 * Prunes expired stored error/security logs without requiring a later append.
 *
 * @since 2.1.1
 * @return int Number of records removed or normalized away.
 */
function sse_prune_expired_stored_logs(): int {
	$stored_logs = sse_normalize_array_value( get_option( 'sse_error_logs', [] ) );
	$original    = $stored_logs;
	$retained    = sse_get_retained_stored_logs( $stored_logs, time() - ( 7 * DAY_IN_SECONDS ) );
	$removed     = max( 0, count( $original ) - count( $retained ) );

	// update_option() avoids a database write when the normalized value is unchanged.
	update_option( 'sse_error_logs', $retained, false );

	return $removed;
}

/**
 * Removes local absolute paths and bounds a database-stored log message.
 *
 * @since 2.1.1
 * @param string $message Log message.
 * @return string Sanitized, bounded message.
 */
function sse_sanitize_stored_log_message( string $message ): string {
	$known_paths = [ ABSPATH, get_temp_dir() ];
	foreach ( $known_paths as $known_path ) {
		if ( '' !== $known_path ) {
			$message = str_replace( [ $known_path, wp_normalize_path( $known_path ) ], '[path]/', $message );
		}
	}

	$without_paths = preg_replace( '#(?<![A-Za-z0-9])(?:[A-Za-z]:[\\\\/]|/)(?:[^\s|]+[\\\\/])*[^\s|]+#', '[path]', $message );
	if ( is_string( $without_paths ) ) {
		$message = $without_paths;
	}

	return substr( sanitize_text_field( $message ), 0, 1000 );
}

/**
 * Outputs a message to the WordPress debug log.
 *
 * @since 1.0.0
 * @param string $formatted_message The formatted log message.
 * @return void
 */
function sse_output_log_message( string $formatted_message ): void {
	if ( function_exists( 'wp_debug_log' ) ) {
		wp_debug_log( $formatted_message );
		return;
	}

	error_log( $formatted_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WordPress has no universally available arbitrary debug-log wrapper; sse_log() already checks WP_DEBUG_LOG.
}

/**
 * Logs plugin messages when WordPress debug logging is enabled.
 *
 * @since 1.0.0
 * @param string $message The message to log.
 * @param string $level   The log level (error, warning, info, or security).
 * @return void
 */
function sse_log( string $message, string $level = 'info' ): void {
	// Check if WP_DEBUG is enabled.
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	// Format the message with a timestamp (using GMT to avoid timezone issues).
	$formatted_message = sprintf(
		'[%s] [%s] %s: %s',
		gmdate( 'Y-m-d H:i:s' ),
		'EngineScript Site Exporter',
		strtoupper( $level ),
		$message
	);

	// Only log if debug logging is enabled.
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	sse_output_log_message( $formatted_message );

	// Store only errors and security events as bounded database records.
	if ( 'error' === $level || 'security' === $level ) {
		sse_store_log_in_database( $message, $level );
	}
}

/**
 * Safely gets the PHP execution time limit.
 *
 * @since 1.0.0
 * @return int Current PHP execution time limit in seconds.
 */
function sse_get_execution_time_limit(): int {
	// Get the current execution time limit.
	$max_exec_time = ini_get( 'max_execution_time' );

	// Handle all possible return types from ini_get().
	if ( false === $max_exec_time ) {
		// Ini_get failed.
		return 30;
	}

	if ( '' === $max_exec_time ) {
		// Empty string returned.
		return 30;
	}

	if ( ! is_numeric( $max_exec_time ) ) {
		// Non-numeric value returned.
		return 30;
	}

	return (int) $max_exec_time;
}

/**
 * Builds the canonical combined EngineScript archive filename.
 *
 * @since 2.0.0
 * @param string $site_identifier Sanitized site identifier.
 * @param string $timestamp       Export timestamp in Ymd_His format.
 * @return string Combined ZIP filename.
 */
function sse_get_engine_script_archive_filename( string $site_identifier, string $timestamp ): string {
	return $site_identifier . '_' . SSE_EXPORT_ARCHIVE_MARKER . '_' . $timestamp . '.zip';
}

/**
 * Gets the validation regex for combined EngineScript archive filenames.
 *
 * @since 2.0.0
 * @return non-empty-string Regex pattern with site_identifier and timestamp capture groups.
 */
function sse_get_engine_script_archive_filename_pattern(): string {
	return '/^(?P<site_identifier>[a-zA-Z0-9._-]+)_' . preg_quote( SSE_EXPORT_ARCHIVE_MARKER, '/' ) . '_(?P<timestamp>\d{8}_\d{6})\.zip$/';
}

/**
 * Checks whether a filename matches the canonical combined archive format.
 *
 * @since 2.0.0
 * @param string $filename Filename to validate.
 * @return bool True when the filename matches the generated archive format.
 */
function sse_is_engine_script_archive_filename( string $filename ): bool {
	if ( ! preg_match( sse_get_engine_script_archive_filename_pattern(), $filename, $matches ) ) {
		return false;
	}

	$site_identifier = $matches['site_identifier'] ?? '';
	$timestamp       = $matches['timestamp'] ?? '';

	if ( '' === $site_identifier || '' === $timestamp ) {
		return false;
	}

	return sse_get_engine_script_archive_filename( $site_identifier, $timestamp ) === $filename;
}

/**
 * Gets the private export directory path.
 *
 * Exports contain a full database dump and site files, so they should not live
 * in the public uploads tree. WordPress supplies the temporary directory;
 * export setup rejects it if it resolves inside the web root. Hosts can set
 * WP_TEMP_DIR to a private writable location.
 *
 * @since 2.0.0
 * @return string|WP_Error Export directory path on success, WP_Error on failure.
 */
function sse_get_export_directory_path(): string|WP_Error {
	$temp_dir = get_temp_dir();
	if ( '' === $temp_dir ) {
		return new WP_Error( 'temp_dir_unavailable', __( 'Could not determine a private temporary directory for exports.', 'enginescript-site-exporter' ) );
	}

	return trailingslashit( $temp_dir ) . SSE_EXPORT_DIR_NAME;
}

/**
 * Gets the capability used to show the exporter menu.
 *
 * @since 2.1.1
 * @return string WordPress capability.
 */
function sse_get_exporter_menu_capability(): string {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Checks whether the current user may perform full-site export actions.
 *
 * On multisite, the export contains the full database and files under ABSPATH,
 * so site admins are not sufficient.
 *
 * @since 2.1.1
 * @return bool True when the current user may export, download, or delete exports.
 */
function sse_current_user_can_export_site(): bool {
	if ( is_multisite() ) {
		return is_super_admin() || current_user_can( 'manage_network_options' );
	}

	return current_user_can( 'manage_options' );
}

/**
 * Gets the validation regex for private per-export directory names.
 *
 * @since 2.1.1
 * @return non-empty-string Regex pattern for generated private directory names.
 */
function sse_get_export_private_directory_name_pattern(): string {
	return '/^' . preg_quote( SSE_EXPORT_PRIVATE_DIR_PREFIX, '/' ) . '\d{8}_\d{6}-[a-f0-9]{32}$/';
}

/**
 * Checks whether a directory name matches the generated private export format.
 *
 * @since 2.1.1
 * @param string $directory_name Directory basename to validate.
 * @return bool True when the directory name matches the generated format.
 */
function sse_is_export_private_directory_name( string $directory_name ): bool {
	return 1 === preg_match( sse_get_export_private_directory_name_pattern(), $directory_name );
}

/**
 * Generates a private per-export directory name.
 *
 * @since 2.1.1
 * @return string Private export directory basename.
 */
function sse_generate_private_export_directory_name(): string {
	try {
		$random_suffix = bin2hex( random_bytes( 16 ) );
	} catch ( Exception ) {
		$random_suffix = '';
		for ( $index = 0; $index < 32; ++$index ) {
			$random_suffix .= dechex( wp_rand( 0, 15 ) );
		}
	}

	return SSE_EXPORT_PRIVATE_DIR_PREFIX . gmdate( 'Ymd_His' ) . '-' . $random_suffix;
}

/**
 * Checks whether a filesystem path has no group or public permission bits.
 *
 * @since 2.1.1
 * @param string $path Path to inspect.
 * @return bool True when group/other permissions are not set.
 */
function sse_has_private_mode( string $path ): bool {
	$permissions = sse_get_filesystem_mode( $path );
	if ( false === $permissions ) {
		return false;
	}

	return 0 === ( $permissions & 0077 );
}

/**
 * Gets a path mode through the WordPress Filesystem API.
 *
 * @since 2.1.1
 * @param string $path Path to inspect.
 * @return int|false Octal permissions as an integer, or false on failure.
 */
function sse_get_filesystem_mode( string $path ): int|false {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return false;
	}

	$chmod = sse_normalize_filesystem_scalar( $filesystem->getchmod( $path ) );
	if ( false === $chmod ) {
		return false;
	}

	if ( ! preg_match( '/([0-7]{3,4})$/', (string) $chmod, $matches ) ) {
		return false;
	}

	return (int) octdec( $matches[1] );
}

/**
 * Checks whether a file exists and has content using the WordPress Filesystem API.
 *
 * @since 2.1.1
 * @param string $file_path File path to inspect.
 * @return bool True when the file exists and is non-empty.
 */
function sse_filesystem_file_has_content( string $file_path ): bool {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return false;
	}

	if ( ! $filesystem->exists( $file_path ) || ! $filesystem->is_file( $file_path ) ) {
		return false;
	}

	$file_size = sse_normalize_nonnegative_integer( $filesystem->size( $file_path ) );
	return false !== $file_size && $file_size > 0;
}

/**
 * Applies and verifies a private filesystem mode.
 *
 * @since 2.1.1
 * @param string $path Path to chmod.
 * @param int    $mode Mode to apply.
 * @return bool True when chmod succeeds and no group/public bits remain.
 */
function sse_chmod_private_path( string $path, int $mode ): bool {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return false;
	}

	if ( ! $filesystem->chmod( $path, $mode ) ) {
		return false;
	}

	clearstatcache( true, $path );

	return sse_has_private_mode( $path );
}

/**
 * Applies and verifies private directory permissions.
 *
 * @since 2.1.1
 * @param string $directory Directory path.
 * @return bool True when the directory is private.
 */
function sse_chmod_private_directory( string $directory ): bool {
	return sse_chmod_private_path( $directory, SSE_PRIVATE_DIR_MODE );
}

/**
 * Applies and verifies private file permissions.
 *
 * @since 2.1.1
 * @param string $file_path File path.
 * @return bool True when the file is private.
 */
function sse_chmod_private_file( string $file_path ): bool {
	return sse_chmod_private_path( $file_path, SSE_PRIVATE_FILE_MODE );
}

/**
 * Gets the canonical exporter page URL for this installation mode.
 *
 * Single-site installs use the Tools page. Multisite installs use the one
 * network-level page registered beneath Network Settings.
 *
 * @since 2.1.1
 * @param array<string, string> $args Optional query arguments.
 * @return string Canonical exporter admin URL.
 */
function sse_get_exporter_admin_page_url( array $args = [] ): string {
	$page_args = array_merge(
		$args,
		[ 'page' => 'enginescript-site-exporter' ]
	);
	$admin_url = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' );

	return add_query_arg( $page_args, $admin_url );
}

/**
 * Redirects back to the exporter admin page.
 *
 * @since 2.0.0
 * @param array<string, string> $args Optional query args.
 * @return never
 */
function sse_redirect_to_exporter_page( array $args = [] ): never {
	wp_safe_redirect( sse_get_exporter_admin_page_url( $args ) );
	exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Required after wp_safe_redirect().
}

/**
 * Stops execution with an escaped WordPress error response.
 *
 * @since 2.0.0
 * @param string $message  Error message.
 * @param int    $response HTTP response code.
 * @return never
 *
 * @psalm-suppress InvalidReturnType WordPress exits from wp_die() at runtime.
 */
function sse_wp_die( string $message, int $response = 500 ): never {
	wp_die(
		esc_html( $message ),
		'',
		[
			'response' => (int) absint( $response ),
		]
	);
}

/**
 * Gets WordPress' mutable global filesystem transport when it is direct.
 *
 * @since 2.1.1
 * @return WP_Filesystem_Direct|null Current verified direct transport, or null.
 */
function sse_get_direct_global_filesystem(): ?WP_Filesystem_Direct {
	if ( isset( $GLOBALS['wp_filesystem'] ) && $GLOBALS['wp_filesystem'] instanceof WP_Filesystem_Direct ) {
		return $GLOBALS['wp_filesystem'];
	}

	return null;
}

/**
 * Gets WordPress' database object from its mutable global boundary.
 *
 * @since 2.1.1
 * @return wpdb|null Current database object, or null when unavailable.
 */
function sse_get_wordpress_database(): ?wpdb {
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
		return $GLOBALS['wpdb'];
	}

	return null;
}

/**
 * Gets a verified direct WordPress filesystem instance.
 *
 * Export operations require local path semantics for archive streaming,
 * executable validation, and atomic cleanup. Credential-backed transports do
 * not provide those guarantees, so fail closed unless WordPress selects the
 * direct transport.
 *
 * @since 2.1.1
 * @return WP_Filesystem_Direct|WP_Error Direct filesystem instance on success.
 */
function sse_get_filesystem(): WP_Filesystem_Direct|WP_Error {
	$filesystem = sse_get_direct_global_filesystem();
	if ( null !== $filesystem ) {
		return $filesystem;
	}

	/**
	 * WordPress core is available at runtime.
	 *
	 * @psalm-suppress MissingFile
	 */
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( ! WP_Filesystem() ) {
		sse_log( 'Failed to initialize the WordPress Filesystem API.', 'error' );
		return new WP_Error( 'filesystem_init_failed', __( 'Failed to initialize the WordPress Filesystem API.', 'enginescript-site-exporter' ) );
	}

	$filesystem = sse_get_direct_global_filesystem();
	if ( null === $filesystem ) {
		sse_log( 'Export operations require the direct WordPress filesystem transport.', 'error' );
		return new WP_Error( 'filesystem_method_unsupported', __( 'This server does not provide the direct filesystem access required for secure exports.', 'enginescript-site-exporter' ) );
	}

	return $filesystem;
}
