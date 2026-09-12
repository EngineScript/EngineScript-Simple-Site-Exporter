<?php
/**
 * File cleanup: scheduled deletion, bulk cleanup, temporary file removal.
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
 * Cleans up temporary files.
 *
 * @since 1.0.0
 * @param string[] $files Array of file paths to delete.
 * @return void
 */
function sse_cleanup_files( array $files ): void {
	foreach ( $files as $file ) {
		if ( sse_safely_delete_file( $file ) ) {
			sse_log( 'Cleaned up temporary file: ' . $file, 'info' );
		}
	}
}

/**
 * Schedules cleanup of export files.
 *
 * @since 1.0.0
 * @param string $zip_filepath The ZIP file path to schedule for deletion.
 * @return void
 */
function sse_schedule_export_cleanup( string $zip_filepath ): void {
	// Check if already scheduled.
	if ( false !== wp_next_scheduled( 'sse_delete_export_file', [ $zip_filepath ] ) ) {
		return;
	}

	$scheduled_time = time() + ( 5 * 60 );
	$result         = wp_schedule_single_event( $scheduled_time, 'sse_delete_export_file', [ $zip_filepath ], true );

	if ( is_wp_error( $result ) ) {
		sse_log( 'Failed to schedule export file deletion (' . $result->get_error_code() . '): ' . $result->get_error_message(), 'error' );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( $cron_disabled ) {
			sse_log( 'DISABLE_WP_CRON is enabled; scheduled cleanup requires an external cron runner.', 'warning' );
		}
	} else {
		sse_log( 'Export file deletion scheduled for ' . gmdate( 'Y-m-d H:i:s', $scheduled_time ) . ' GMT: ' . $zip_filepath, 'info' );
	}
}

/**
 * Schedules a bulk cleanup of all export files in the private export directory.
 * This runs as a safety net to catch any files that individual cleanup missed.
 *
 * @since 2.0.0
 * @return void
 */
function sse_schedule_bulk_cleanup(): void {
	if ( false !== wp_next_scheduled( 'sse_bulk_cleanup_exports' ) ) {
		return;
	}

	$scheduled_time = time() + ( 10 * 60 );
	$result         = wp_schedule_single_event( $scheduled_time, 'sse_bulk_cleanup_exports', [], true );

	if ( is_wp_error( $result ) ) {
		sse_log( 'Failed to schedule bulk export cleanup (' . $result->get_error_code() . '): ' . $result->get_error_message(), 'error' );
	}
}

/**
 * Schedules owner-bound recovery before an export begins risky work.
 *
 * @since 2.1.1
 * @param array $lease Canonical owned lease.
 * @psalm-param array{owner:string,expires_at:int,...} $lease
 * @return true|WP_Error True when recovery is scheduled or already present.
 */
function sse_schedule_export_recovery( array $lease ): true|WP_Error {
	$args = [ $lease['owner'] ];
	if ( false !== wp_next_scheduled( 'sse_recover_expired_export', $args ) ) {
		return true;
	}

	$result = wp_schedule_single_event( $lease['expires_at'] + 1, 'sse_recover_expired_export', $args, true );
	if ( is_wp_error( $result ) ) {
		sse_log( 'Failed to schedule interrupted-export recovery (' . $result->get_error_code() . '): ' . $result->get_error_message(), 'error' );
		return new WP_Error( 'export_recovery_schedule_failed', __( 'Required export recovery could not be scheduled, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Ensures recurring stale-artifact and stored-log housekeeping is scheduled.
 *
 * @since 2.1.1
 * @return void
 */
function sse_schedule_export_housekeeping(): void {
	if ( false !== wp_next_scheduled( 'sse_export_housekeeping' ) ) {
		return;
	}

	$result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sse_export_housekeeping', [], true );
	if ( is_wp_error( $result ) ) {
		sse_log( 'Failed to schedule export housekeeping (' . $result->get_error_code() . '): ' . $result->get_error_message(), 'error' );
	}
}

/**
 * Runs recurring stale-artifact and stored-log housekeeping.
 *
 * @since 2.1.1
 * @return void
 */
function sse_export_housekeeping_handler(): void {
	sse_prune_expired_stored_logs();
	sse_cleanup_stale_export_directories();
}

/**
 * Removes the exact canonical directory recorded by an expired owned lease.
 *
 * @since 2.1.1
 * @param array $lease Expired canonical lease.
 * @psalm-param array{export_dir_name:string,...} $lease
 * @return bool True when absent or safely removed.
 */
function sse_cleanup_expired_lease_directory( array $lease ): bool {
	if ( '' === $lease['export_dir_name'] || ! sse_is_export_private_directory_name( $lease['export_dir_name'] ) ) {
		return true;
	}

	$export_dir = sse_get_export_directory_path();
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $export_dir ) || is_wp_error( $filesystem ) ) {
		return false;
	}

	$directory = trailingslashit( $export_dir ) . $lease['export_dir_name'];
	if ( ! $filesystem->exists( $directory ) ) {
		return true;
	}

	if ( ! $filesystem->is_dir( $directory ) || is_link( $directory ) || ! sse_is_path_within_directory( $directory, $export_dir ) ) {
		sse_log( 'Skipped unsafe expired export directory.', 'security' );
		return false;
	}

	return sse_delete_directory_tree( $directory );
}

/**
 * Recovers one expired owner without touching a replacement lease.
 *
 * @since 2.1.1
 * @param string $expected_owner UUID bound to the scheduled event.
 * @return void
 */
function sse_recover_expired_export_handler( string $expected_owner ): void {
	sse_prune_expired_stored_logs();
	if ( ! wp_is_uuid( $expected_owner, 4 ) ) {
		sse_log( 'Skipped export recovery with an invalid owner identifier.', 'security' );
		return;
	}

	$lease = sse_get_stored_export_lease();
	if ( is_wp_error( $lease ) ) {
		sse_log( 'Could not verify export state during scheduled recovery.', 'error' );
		return;
	}

	if ( null === $lease ) {
		sse_cleanup_stale_export_directories();
		return;
	}

	if ( $lease['owner'] !== $expected_owner ) {
		sse_cleanup_stale_export_directories();
		return;
	}

	if ( $lease['expires_at'] > time() ) {
		$schedule_result = sse_schedule_export_recovery( $lease );
		if ( is_wp_error( $schedule_result ) ) {
			sse_log( 'Could not reschedule recovery for a renewed export lease.', 'error' );
		}
		return;
	}

	if ( ! sse_compare_and_delete_export_lease( $lease ) ) {
		sse_log( 'Expired export lease changed before scheduled recovery.', 'warning' );
		return;
	}

	if ( ! sse_cleanup_expired_lease_directory( $lease ) ) {
		sse_log( 'Failed to remove an expired export directory during scheduled recovery.', 'error' );
	}

	sse_cleanup_stale_export_directories();
}

/**
 * Removes abandoned generated export directories after their lease lifetime.
 *
 * Only canonical, non-symlinked child directories are eligible. An unexpired
 * lease's recorded directory is always excluded.
 *
 * @since 2.1.1
 * @return int Number of stale directories removed.
 */
function sse_cleanup_stale_export_directories(): int {
	$export_dir = sse_get_export_directory_path();
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $export_dir ) || is_wp_error( $filesystem ) || ! $filesystem->is_dir( $export_dir ) ) {
		return 0;
	}

	$active_directory = sse_get_active_export_lease_directory();
	if ( is_wp_error( $active_directory ) ) {
		sse_log( 'Could not verify active export state during stale cleanup.', 'error' );
		return 0;
	}

	$entries = $filesystem->dirlist( $export_dir, true, false );
	if ( ! is_array( $entries ) ) {
		return 0;
	}

	$cutoff  = time() - sse_get_export_lease_lifetime();
	$removed = 0;
	foreach ( $entries as $entry_name => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$directory_name = isset( $entry['name'] ) && is_string( $entry['name'] ) ? $entry['name'] : (string) $entry_name;
		$directory_path = trailingslashit( $export_dir ) . $directory_name;
		if ( ! sse_is_stale_export_directory_entry( $entry, $directory_name, $directory_path, $active_directory, $cutoff, $filesystem ) ) {
			continue;
		}

		if ( sse_delete_directory_tree( $directory_path ) ) {
			++$removed;
		}
	}

	if ( $removed > 0 ) {
		sse_log( "Removed {$removed} abandoned export directories.", 'info' );
	}

	return $removed;
}

/**
 * Gets the generated directory protected by an unexpired export lease.
 *
 * @since 2.1.1
 * @return string|WP_Error Active directory basename, empty string, or storage error.
 */
function sse_get_active_export_lease_directory(): string|WP_Error {
	$lease = sse_get_stored_export_lease();
	if ( is_wp_error( $lease ) ) {
		return $lease;
	}

	if ( null === $lease || $lease['expires_at'] <= time() ) {
		return '';
	}

	return $lease['export_dir_name'];
}

/**
 * Checks whether an export directory entry is eligible for stale recovery.
 *
 * @since 2.1.1
 * @param array<array-key,mixed> $entry           Directory entry metadata.
 * @param string                 $directory_name    Directory basename.
 * @param string                 $directory_path    Full directory path.
 * @param string                 $active_directory Protected active directory basename.
 * @param int                    $cutoff            Oldest allowed modification time.
 * @param WP_Filesystem_Direct   $filesystem        Direct filesystem instance.
 * @return bool True when the directory is stale and safe to remove.
 */
function sse_is_stale_export_directory_entry( array $entry, string $directory_name, string $directory_path, string $active_directory, int $cutoff, WP_Filesystem_Direct $filesystem ): bool {
	$type = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';
	if ( 'd' !== $type || $directory_name === $active_directory || ! sse_is_export_private_directory_name( $directory_name ) || is_link( $directory_path ) ) {
		return false;
	}

	$modified_time = $filesystem->mtime( $directory_path );
	return false !== $modified_time && $modified_time < $cutoff;
}

/**
 * Handles bulk cleanup of all export files older than 5 minutes.
 * This is a safety net to catch any files missed by individual cleanup.
 *
 * @since 2.0.0
 * @return void
 */
function sse_bulk_cleanup_exports_handler(): void {
	sse_log( 'Bulk export cleanup handler triggered.', 'info' );
	sse_cleanup_stale_export_directories();

	$export_dir = sse_get_export_directory_path();
	if ( is_wp_error( $export_dir ) ) {
		sse_log( 'Could not determine export directory for cleanup: ' . $export_dir->get_error_message(), 'error' );
		return;
	}

	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		sse_log( 'Could not initialize filesystem for cleanup: ' . $filesystem->get_error_message(), 'error' );
		return;
	}

	if ( ! $filesystem->is_dir( $export_dir ) ) {
		sse_log( 'The export directory does not exist; there is nothing to clean up.', 'info' );
		return;
	}

	$files = sse_get_export_files_for_bulk_cleanup( $export_dir );

	if ( empty( $files ) ) {
		sse_log( 'No export files found during bulk cleanup.', 'info' );
		return;
	}

	$cleaned_count = 0;
	$cutoff_time   = time() - ( 5 * 60 ); // Files older than 5 minutes.

	foreach ( $files as $file_path ) {
		if ( sse_cleanup_expired_export_file( $file_path, $cutoff_time ) ) {
			++$cleaned_count;
		}
	}

	sse_log( "Bulk cleanup completed. Deleted {$cleaned_count} export files.", 'info' );
}

/**
 * Gets export ZIP files eligible for bulk cleanup lookup.
 *
 * @since 2.1.1
 * @param string $export_dir Export base directory.
 * @return string[] Export ZIP file paths.
 */
function sse_get_export_files_for_bulk_cleanup( string $export_dir ): array {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		sse_log( 'Failed to initialize filesystem for export cleanup: ' . $filesystem->get_error_message(), 'error' );
		return [];
	}

	$active_directory = sse_get_active_export_lease_directory();
	if ( is_wp_error( $active_directory ) ) {
		sse_log( 'Could not verify active export state during bulk cleanup.', 'error' );
		return [];
	}

	$dir_entries = $filesystem->dirlist( $export_dir, true, false );
	if ( ! is_array( $dir_entries ) ) {
		sse_log( 'Failed to read export directory for cleanup.', 'error' );
		return [];
	}

	$files = [];
	foreach ( $dir_entries as $entry_name => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$files = array_merge( $files, sse_get_export_files_from_directory_entry( (string) $entry_name, $entry, $export_dir, $active_directory ) );
	}

	return $files;
}

/**
 * Gets export ZIP paths represented by one export base directory entry.
 *
 * @since 2.1.1
 * @param string                 $entry_name Directory entry name.
 * @param array<array-key,mixed> $entry Directory entry data from WP_Filesystem::dirlist().
 * @param string                 $export_dir       Export base directory.
 * @param string                 $active_directory Protected active directory basename.
 * @return string[] Export ZIP file paths.
 */
function sse_get_export_files_from_directory_entry( string $entry_name, array $entry, string $export_dir, string $active_directory ): array {
	$filename = isset( $entry['name'] ) && is_string( $entry['name'] ) ? $entry['name'] : $entry_name;
	$type     = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';

	if ( '.' === $filename || '..' === $filename || 'l' === $type ) {
		return [];
	}

	if ( 'd' !== $type || $filename === $active_directory || ! sse_is_export_private_directory_name( $filename ) ) {
		return [];
	}

	$entry_path = trailingslashit( $export_dir ) . $filename;
	return sse_get_export_files_from_private_directory( $entry_path );
}

/**
 * Gets export ZIP paths from a private per-export directory.
 *
 * @since 2.1.1
 * @param string $directory Private export directory.
 * @return string[] Export ZIP file paths.
 */
function sse_get_export_files_from_private_directory( string $directory ): array {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		sse_log( 'Failed to initialize filesystem for private export cleanup: ' . $filesystem->get_error_message(), 'error' );
		return [];
	}

	$private_entries = $filesystem->dirlist( $directory, true, false );
	if ( ! is_array( $private_entries ) ) {
		sse_log( 'Failed to read private export directory for cleanup.', 'error' );
		return [];
	}

	$files = [];
	foreach ( $private_entries as $entry_name => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$filename = isset( $entry['name'] ) && is_string( $entry['name'] ) ? $entry['name'] : (string) $entry_name;
		$type     = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';
		if ( sse_is_export_zip_entry( $filename, $type ) ) {
			$files[] = trailingslashit( $directory ) . $filename;
		}
	}

	return $files;
}

/**
 * Checks whether a directory entry is an export ZIP file.
 *
 * @since 2.1.1
 * @param string $filename Directory entry filename.
 * @param string $type     Directory entry type.
 * @return bool True when the entry is a ZIP file.
 */
function sse_is_export_zip_entry( string $filename, string $type ): bool {
	return 'f' === $type && '.zip' === substr( $filename, -4 );
}

/**
 * Attempts to clean up a single expired export file.
 *
 * @since 2.0.0
 * @param string $file_path   The file path to check and potentially delete.
 * @param int    $cutoff_time Unix timestamp; files modified before this are eligible.
 * @return bool True if the file was deleted, false otherwise.
 */
function sse_cleanup_expired_export_file( string $file_path, int $cutoff_time ): bool {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return false;
	}

	$file_time = sse_normalize_nonnegative_integer( $filesystem->mtime( $file_path ) );

	if ( false === $file_time || $file_time >= $cutoff_time ) {
		return false;
	}

	$filename        = wp_basename( $file_path );
	$export_dir_name = wp_basename( dirname( $file_path ) );
	if ( ! sse_is_export_private_directory_name( $export_dir_name ) ) {
		return false;
	}

	$validation = sse_validate_basic_export_file( $filename, $export_dir_name );

	if ( is_wp_error( $validation ) ) {
		sse_log( 'Bulk cleanup skipped invalid file: ' . $file_path . ' - ' . $validation->get_error_message(), 'warning' );
		return false;
	}

	if ( sse_safely_delete_file( $validation['filepath'] ) ) {
		sse_log( 'Bulk cleanup deleted export file: ' . $validation['filepath'], 'info' );
		return true;
	}

	sse_log( 'Bulk cleanup failed to delete: ' . $file_path, 'error' );
	return false;
}

/**
 * Handles scheduled deletion of export files.
 *
 * @since 1.0.0
 * @param string $file File path to delete.
 * @return void
 */
function sse_delete_export_file_handler( string $file ): void {
	sse_log( 'Scheduled deletion handler triggered for file: ' . $file, 'info' );

	// Validate that this is actually an export file before deletion.
	$filename = wp_basename( $file );

	$format_validation = sse_validate_filename_format( $filename );
	if ( is_wp_error( $format_validation ) ) {
		sse_log( 'Scheduled deletion blocked - invalid file: ' . $file . ' - ' . $format_validation->get_error_message(), 'warning' );
		return;
	}

	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		sse_log( 'Scheduled deletion blocked - filesystem unavailable: ' . $filesystem->get_error_message(), 'error' );
		return;
	}

	if ( ! $filesystem->exists( $file ) ) {
		sse_log( 'Scheduled deletion skipped - file already removed: ' . $filename, 'info' );
		return;
	}

	$export_dir_name = wp_basename( dirname( $file ) );
	if ( ! sse_is_export_private_directory_name( $export_dir_name ) ) {
		sse_log( 'Scheduled deletion blocked - file is not inside a generated private export directory: ' . $file, 'warning' );
		return;
	}

	$validation = sse_validate_export_file_path( $filename, $export_dir_name );
	if ( is_wp_error( $validation ) ) {
		sse_log( 'Scheduled deletion blocked - invalid file: ' . $file . ' - ' . $validation->get_error_message(), 'warning' );
		return;
	}

	$validated_file = $validation['filepath'];
	if ( $filesystem->exists( $validated_file ) ) {
		if ( sse_safely_delete_file( $validated_file ) ) {
			sse_log( 'Scheduled deletion successful: ' . $validated_file, 'info' );
			return;
		}
		sse_log( 'Scheduled deletion failed: ' . $validated_file, 'error' );
	} else {
		// Graceful handling: file already gone (likely manually deleted) - not an error.
		sse_log( 'Scheduled deletion skipped - file already removed: ' . $validated_file, 'info' );
	}
}

/**
 * Safely deletes a file using WordPress' directory containment helper.
 *
 * @since 1.0.0
 * @param string $filepath Path to the file to delete.
 * @return bool Whether the file was deleted successfully.
 */
function sse_safely_delete_file( string $filepath ): bool {
	/**
	 * WordPress core is available at runtime.
	 *
	 * @psalm-suppress MissingFile
	 */
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$export_dir = sse_get_export_directory_path();
	if ( is_wp_error( $export_dir ) ) {
		return false;
	}

	$deleted = wp_delete_file_from_directory( $filepath, $export_dir );
	if ( $deleted ) {
		sse_delete_empty_private_export_directory( dirname( $filepath ), $export_dir );
	}

	return $deleted;
}

/**
 * Deletes an empty generated private export directory.
 *
 * @since 2.1.1
 * @param string $directory  Candidate private export directory path.
 * @param string $export_dir Export base directory path.
 * @return void
 */
function sse_delete_empty_private_export_directory( string $directory, string $export_dir ): void {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return;
	}

	if ( ! sse_is_export_private_directory_name( wp_basename( $directory ) ) ) {
		return;
	}

	if ( ! $filesystem->is_dir( $directory ) || is_link( $directory ) || ! sse_is_path_within_directory( $directory, $export_dir ) ) {
		return;
	}

	$dir_entries = $filesystem->dirlist( $directory, true, false );
	if ( ! is_array( $dir_entries ) || [] !== $dir_entries ) {
		return;
	}

	$filesystem->delete( $directory, false, 'd' );
}
