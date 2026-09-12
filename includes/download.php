<?php
/**
 * Download and deletion: secure file serving, rate limiting, export deletion.
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
 * Handles secure download requests for export files.
 *
 * @since 2.0.0
 * @return void
 */
function sse_handle_secure_download(): void { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$filename        = isset( $_GET['file'] ) && is_string( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
	$export_dir_name = isset( $_GET['export_dir'] ) && is_string( $_GET['export_dir'] ) ? sanitize_file_name( wp_unslash( $_GET['export_dir'] ) ) : '';
	if ( '' === $filename || '' === $export_dir_name ) {
		sse_wp_die( __( 'Invalid download request.', 'enginescript-site-exporter' ), 400 );
	}

	$nonce_action = 'sse_secure_download_' . $filename . '_' . $export_dir_name;

	check_admin_referer( $nonce_action );

	// Verify user capabilities.
	if ( ! sse_current_user_can_export_site() ) {
		sse_wp_die( __( 'You do not have permission to download export files.', 'enginescript-site-exporter' ), 403 );
	}

	$validation = sse_validate_export_file_for_download( $filename, $export_dir_name );

	if ( is_wp_error( $validation ) ) {
		sse_wp_die( $validation->get_error_message(), 404 );
	}

	// Rate limiting check.
	if ( ! sse_check_download_rate_limit() ) {
		sse_wp_die( __( 'Too many download requests. Please wait before trying again.', 'enginescript-site-exporter' ), 429 );
	}

	sse_serve_file_download( $validation );
}

/**
 * Handles manual deletion of export files.
 *
 * @since 2.0.0
 * @return void
 */
function sse_handle_export_deletion(): void { // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$filename        = isset( $_POST['file'] ) && is_string( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
	$export_dir_name = isset( $_POST['export_dir'] ) && is_string( $_POST['export_dir'] ) ? sanitize_file_name( wp_unslash( $_POST['export_dir'] ) ) : '';
	if ( '' === $filename || '' === $export_dir_name ) {
		sse_wp_die( __( 'Invalid deletion request.', 'enginescript-site-exporter' ), 400 );
	}

	$nonce_action = 'sse_delete_export_' . $filename . '_' . $export_dir_name;

	check_admin_referer( $nonce_action );

	// Verify user capabilities.
	if ( ! sse_current_user_can_export_site() ) {
		sse_wp_die( __( 'You do not have permission to delete export files.', 'enginescript-site-exporter' ), 403 );
	}

	$validation = sse_validate_basic_export_file( $filename, $export_dir_name );

	if ( is_wp_error( $validation ) ) {
		sse_wp_die( $validation->get_error_message(), 404 );
	}

	if ( sse_safely_delete_file( $validation['filepath'] ) ) {
		sse_log( 'Manual deletion of export file: ' . $validation['filepath'], 'info' );
		sse_set_exporter_notice(
			[
				'type'    => 'success',
				'message' => __( 'Export file successfully deleted.', 'enginescript-site-exporter' ),
			]
		);
		sse_redirect_to_exporter_page();
	}

	sse_log( 'Failed manual deletion of export file: ' . $validation['filepath'], 'error' );
	sse_set_exporter_notice(
		[
			'type'    => 'error',
			'message' => __( 'Failed to delete export file.', 'enginescript-site-exporter' ),
		]
	);
	sse_redirect_to_exporter_page();
}

/**
 * Implements basic rate limiting for downloads.
 *
 * @since 2.0.0
 * @return bool True if request is within rate limits, false otherwise.
 */
function sse_check_download_rate_limit(): bool {
	$user_id        = get_current_user_id();
	$rate_limit_key = 'sse_download_rate_limit_' . $user_id;
	$current_time   = time();

	$last_download = sse_normalize_nonnegative_integer( get_transient( $rate_limit_key ) );

	// Allow one download per minute per user.
	if ( false !== $last_download && ( $current_time - $last_download ) < 60 ) {
		return false;
	}

	set_transient( $rate_limit_key, $current_time, 60 );
	return true;
}

/**
 * Sets appropriate headers for file download.
 *
 * @since 2.0.0
 * @param string $filename  The filename for download.
 * @param int    $filesize  The file size in bytes.
 * @return void
 */
function sse_set_download_headers( string $filename, int $filesize ): void {
	// Security: Set safe Content-Type based on file extension to prevent XSS.
	$file_extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	switch ( $file_extension ) {
		case 'zip':
			$content_type = 'application/zip';
			break;
		default:
			// Security: Default to octet-stream for unknown types to prevent execution.
			$content_type = 'application/octet-stream';
			break;
	}

	// Security: Set headers to prevent XSS and ensure proper download behavior.
	header( 'Content-Type: ' . $content_type );
	header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
	header( 'Content-Length: ' . max( 0, $filesize ) );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	header( 'X-Content-Type-Options: nosniff' ); // Security: Prevent MIME sniffing.
	header( 'X-Frame-Options: DENY' ); // Security: Prevent framing.
}

/**
 * Validates file output security before serving download.
 *
 * Security: Returns the realpath()-resolved filepath after containment checks.
 *
 * @since 2.0.0
 * @param string $filepath The file path to validate.
 * @return string|WP_Error The realpath()-resolved file path or error.
 */
function sse_validate_file_output_security( string $filepath ): string|WP_Error {
	// Validate the local file type and containment before opening the download.
	if ( ! sse_validate_file_extension( $filepath ) ) {
		sse_log( 'Security: Blocked attempt to serve file with invalid extension: ' . pathinfo( $filepath, PATHINFO_EXTENSION ), 'security' );
		return new WP_Error( 'invalid_download_type', __( 'Access denied: invalid file type.', 'enginescript-site-exporter' ) );
	}

	// Security: Ensure file is within our controlled directory before serving.
	$export_dir = sse_get_export_directory_path();
	if ( is_wp_error( $export_dir ) ) {
		return $export_dir;
	}

	$real_file_path = sse_normalize_realpath( $filepath );
	if ( is_link( $filepath ) || false === $real_file_path || ! sse_is_path_within_directory( $real_file_path, $export_dir ) ) {
		sse_log( 'Security: File not within controlled export directory: ' . $filepath, 'security' );
		return new WP_Error( 'invalid_download_path', __( 'Access denied.', 'enginescript-site-exporter' ) );
	}

	return $real_file_path;
}

/**
 * Compares two exact native download identities.
 *
 * @since 2.1.1
 * @param array{filesize:int,device:int,inode:int} $expected Expected identity.
 * @param array{filesize:int,device:int,inode:int} $actual   Actual identity.
 * @return bool True when device, inode, and size match.
 */
function sse_download_file_identity_matches( array $expected, array $actual ): bool {
	return $expected['device'] === $actual['device']
		&& $expected['inode'] === $actual['inode']
		&& $expected['filesize'] === $actual['filesize'];
}

/**
 * Opens and revalidates the exact export object that will be streamed.
 *
 * @since 2.1.1
 * @param array{filename:string,filepath:string,filesize:int,device:int,inode:int} $file_data Validated file data.
 * @return array{handle:resource,filename:string,filesize:int}|WP_Error Opened download or error.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_open_validated_export_download( array $file_data ): array|WP_Error {
	$resolved_path = sse_validate_file_output_security( $file_data['filepath'] );
	if ( is_wp_error( $resolved_path ) ) {
		return $resolved_path;
	}

	$handle = @fopen( $resolved_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- An expected replacement race must fail without emitting output before headers.
	if ( false === $handle ) {
		return new WP_Error( 'download_open_failed', __( 'Unable to serve file download.', 'enginescript-site-exporter' ) );
	}

	$handle_identity = sse_normalize_native_file_identity( fstat( $handle ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fstat -- The streamed handle's native identity must match path validation.
	$current_path    = sse_validate_file_output_security( $file_data['filepath'] );
	if ( is_wp_error( $current_path ) || $current_path !== $resolved_path ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the exact local download handle.
		return new WP_Error( 'download_identity_changed', __( 'Export file changed before download and was not served.', 'enginescript-site-exporter' ) );
	}

	$current_identity  = sse_get_export_file_native_identity( $current_path );
	$expected_identity = [
		'filesize' => $file_data['filesize'],
		'device'   => $file_data['device'],
		'inode'    => $file_data['inode'],
	];
	if (
		null === $handle_identity
		|| is_wp_error( $current_identity )
		|| ! sse_download_file_identity_matches( $expected_identity, $handle_identity )
		|| ! sse_download_file_identity_matches( $expected_identity, $current_identity )
	) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the exact local download handle.
		return new WP_Error( 'download_identity_changed', __( 'Export file changed before download and was not served.', 'enginescript-site-exporter' ) );
	}

	return [
		'handle'   => $handle,
		'filename' => $file_data['filename'],
		'filesize' => $handle_identity['filesize'],
	];
}

/**
 * Clears every removable output buffer before any attachment header is sent.
 *
 * @since 2.1.1
 * @return true|WP_Error True when output is ready for direct streaming.
 */
function sse_prepare_download_output(): true|WP_Error {
	while ( ob_get_level() > 0 ) {
		$status = ob_get_status();
		if ( ! isset( $status['flags'] ) || ! is_int( $status['flags'] ) || 0 === ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
			return new WP_Error( 'download_buffer_failed', __( 'Unable to serve file download.', 'enginescript-site-exporter' ) );
		}

		$previous_level = ob_get_level();
		if ( ! ob_end_clean() || ob_get_level() >= $previous_level ) {
			return new WP_Error( 'download_buffer_failed', __( 'Unable to serve file download.', 'enginescript-site-exporter' ) );
		}
	}

	if ( headers_sent() ) {
		return new WP_Error( 'download_headers_sent', __( 'Unable to serve file download.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Streams the exact opened file content and terminates the request.
 *
 * @since 2.0.0
 * @param resource $handle        Validated open file handle.
 * @param string   $filename      Filename for logging.
 * @param int      $expected_size Expected streamed byte count.
 * @return never
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_output_file_content( $handle, string $filename, int $expected_size ): never {
	$streamed_bytes = @fpassthru( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru,WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.NoSilencedErrors.Discouraged -- Stream failure must not inject warning text into an attachment response.
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the exact local download handle.

	if ( $streamed_bytes !== $expected_size ) {
		sse_log( 'Secure file download stream ended before the expected byte count: ' . $filename, 'error' );
	} else {
		sse_log( 'Secure file download served from an identity-checked handle: ' . $filename, 'info' );
	}

	exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Required to terminate after streaming a download response.
}

/**
 * Serves a file download with exact opened-object validation.
 *
 * @since 2.0.0
 * @param array{filename:string,filepath:string,filesize:int,device:int,inode:int} $file_data Validated file information.
 * @return never
 */
function sse_serve_file_download( array $file_data ): never {
	$opened_file = sse_open_validated_export_download( $file_data );
	if ( is_wp_error( $opened_file ) ) {
		sse_wp_die( $opened_file->get_error_message(), 403 );
	}

	$output_ready = sse_prepare_download_output();
	if ( is_wp_error( $output_ready ) ) {
		fclose( $opened_file['handle'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close before a pre-header failure response.
		sse_wp_die( $output_ready->get_error_message() );
	}

	sse_set_download_headers( $opened_file['filename'], $opened_file['filesize'] );
	sse_output_file_content( $opened_file['handle'], $opened_file['filename'], $opened_file['filesize'] );
}
