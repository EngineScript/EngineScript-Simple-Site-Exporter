<?php
/**
 * EngineScript archive operations: ZIP bundle creation, file iteration, exclusion logic.
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
 * Gets the site identifier used in EngineScript archive filenames.
 *
 * @since 2.0.0
 * @return string Sanitized site identifier.
 */
function sse_get_export_site_identifier(): string {
	$site_host = sse_normalize_string_value( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( '' === $site_host ) {
		$site_host = get_bloginfo( 'name' );
	}

	$site_identifier = sanitize_file_name( strtolower( $site_host ) );
	if ( '' === $site_identifier ) {
		return 'wordpress-site';
	}

	return $site_identifier;
}

/**
 * Gets the current EngineScript export timestamp.
 *
 * @since 2.0.0
 * @return string Timestamp formatted to match EngineScript shell exports.
 */
function sse_get_export_timestamp(): string {
	return gmdate( 'Ymd_His' );
}

/**
 * Checks whether a resolved path stays within the export source directory.
 *
 * @since 2.0.0
 * @param string $path      Path to check.
 * @param string $directory Directory that must contain the path.
 * @return bool True if the path resolves inside the directory.
 */
function sse_is_path_within_export_source( string $path, string $directory ): bool {
	return sse_is_path_within_directory( $path, $directory );
}

/**
 * Creates a site archive with database and files.
 *
 * @since 1.0.0
 * @param array  $export_paths     Export directory paths.
 * @param array  $database_file    Database file information.
 * @param string $site_identifier Sanitized site identifier.
 * @param string $timestamp       Export timestamp.
 * @psalm-param array{export_dir: string, export_dir_name: string} $export_paths
 * @psalm-param array{filename: string, filepath: string} $database_file
 * @return array{filename: string, filepath: string}|WP_Error Archive info on success, WP_Error on failure.
 */
function sse_create_site_archive( array $export_paths, array $database_file, string $site_identifier, string $timestamp ): array|WP_Error {
	$requirements_result = sse_validate_archive_requirements();
	if ( is_wp_error( $requirements_result ) ) {
		return $requirements_result;
	}

	$bundle_paths = sse_prepare_engine_script_bundle_paths( $export_paths, $site_identifier, $timestamp );
	$setup_result = sse_create_bundle_staging_directories( $bundle_paths );
	if ( is_wp_error( $setup_result ) ) {
		return $setup_result;
	}

	try {
		$archive_result = sse_build_engine_script_bundle( $export_paths, $database_file, $bundle_paths, $site_identifier );
		if ( is_wp_error( $archive_result ) ) {
			return $archive_result;
		}

		sse_log( 'Site archive created successfully: ' . $bundle_paths['combined_zip_path'], 'info' );
		return [
			'filename' => $bundle_paths['combined_zip_filename'],
			'filepath' => $bundle_paths['combined_zip_path'],
		];
	} finally {
		sse_delete_directory_tree( $bundle_paths['staging_dir'] );
	}
}

/**
 * Validates that the server supports all archive formats used by exports.
 *
 * @since 2.0.0
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_archive_requirements(): true|WP_Error {
	$integer_result = sse_validate_export_integer_size( PHP_INT_SIZE );
	if ( is_wp_error( $integer_result ) ) {
		return $integer_result;
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'zip_not_available', __( 'Your server does not provide ZipArchive. Enable the PHP ZIP extension to create an export.', 'enginescript-site-exporter' ) );
	}

	if ( ! class_exists( 'PharData' ) ) {
		return new WP_Error( 'phar_not_available', __( 'Your server does not provide PharData. Enable the PHP Phar extension to create a files archive.', 'enginescript-site-exporter' ) );
	}

	if ( ! function_exists( 'gzopen' ) ) {
		return new WP_Error( 'gzip_not_available', __( 'Your server does not provide gzip support. Enable the PHP zlib extension to compress the database dump.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Validates that an integer width can safely represent export byte limits.
 *
 * @since 2.1.1
 * @param int $integer_size Runtime integer width in bytes.
 * @return true|WP_Error True on success, WP_Error when unsupported.
 */
function sse_validate_export_integer_size( int $integer_size ): true|WP_Error {
	if ( $integer_size < 8 ) {
		return new WP_Error( 'unsupported_integer_size', __( 'Exports require a 64-bit PHP runtime.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Builds the staged EngineScript bundle payload.
 *
 * @since 2.0.0
 * @param array  $export_paths     Export directory paths.
 * @param array  $database_file    Database file information.
 * @param array  $bundle_paths     Bundle paths.
 * @param string $site_identifier Sanitized site identifier.
 * @psalm-param array{export_dir: string, export_dir_name: string} $export_paths
 * @psalm-param array{filename: string, filepath: string} $database_file
 * @psalm-param array{database_path: string, files_archive_path: string, manifest_path: string, database_gz_filename: string, files_archive_filename: string, combined_zip_path: string, combined_zip_filename: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_build_engine_script_bundle( array $export_paths, array $database_file, array $bundle_paths, string $site_identifier ): true|WP_Error {
	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$database_result = sse_create_compressed_database_file( $database_file['filepath'], $bundle_paths['database_path'] );
	if ( is_wp_error( $database_result ) ) {
		return $database_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$file_result = sse_create_wordpress_files_archive( $bundle_paths['files_archive_path'], $export_paths['export_dir'] );
	if ( is_wp_error( $file_result ) ) {
		return $file_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$manifest_result = sse_write_engine_script_manifest( $bundle_paths, $site_identifier );
	if ( is_wp_error( $manifest_result ) ) {
		return $manifest_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$zip_result = sse_create_combined_engine_script_zip( $bundle_paths );
	if ( is_wp_error( $zip_result ) ) {
		return $zip_result;
	}

	return true;
}

/**
 * Prepares canonical EngineScript bundle paths and filenames.
 *
 * @since 2.0.0
 * @param array  $export_paths     Export directory paths.
 * @param string $site_identifier Sanitized site identifier.
 * @param string $timestamp       Export timestamp.
 * @psalm-param array{export_dir: string, export_dir_name: string} $export_paths
 * @return array{staging_dir: string, bundle_root_dir: string, database_dir: string, files_dir: string, manifest_path: string, database_filename: string, database_gz_filename: string, database_path: string, files_archive_filename: string, files_archive_path: string, combined_zip_filename: string, combined_zip_path: string}
 */
function sse_prepare_engine_script_bundle_paths( array $export_paths, string $site_identifier, string $timestamp ): array {
	$staging_dir            = trailingslashit( $export_paths['export_dir'] ) . 'staging-' . $timestamp;
	$bundle_root_dir        = trailingslashit( $staging_dir ) . 'bundle';
	$database_dir           = trailingslashit( $bundle_root_dir ) . 'database';
	$files_dir              = trailingslashit( $bundle_root_dir ) . 'files';
	$database_filename      = "{$site_identifier}_db_{$timestamp}.sql";
	$database_gz_filename   = $database_filename . '.gz';
	$files_archive_filename = "{$site_identifier}_files_{$timestamp}.tar.gz";
	$combined_zip_filename  = sse_get_engine_script_archive_filename( $site_identifier, $timestamp );

	return [
		'staging_dir'            => $staging_dir,
		'bundle_root_dir'        => $bundle_root_dir,
		'database_dir'           => $database_dir,
		'files_dir'              => $files_dir,
		'manifest_path'          => trailingslashit( $bundle_root_dir ) . 'manifest.txt',
		'database_filename'      => $database_filename,
		'database_gz_filename'   => $database_gz_filename,
		'database_path'          => trailingslashit( $database_dir ) . $database_gz_filename,
		'files_archive_filename' => $files_archive_filename,
		'files_archive_path'     => trailingslashit( $files_dir ) . $files_archive_filename,
		'combined_zip_filename'  => $combined_zip_filename,
		'combined_zip_path'      => trailingslashit( $export_paths['export_dir'] ) . $combined_zip_filename,
	];
}

/**
 * Creates bundle staging directories.
 *
 * @since 2.0.0
 * @param array $bundle_paths Bundle paths.
 * @psalm-param array{database_dir: string, files_dir: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_create_bundle_staging_directories( array $bundle_paths ): true|WP_Error {
	if ( wp_mkdir_p( $bundle_paths['database_dir'] ) && wp_mkdir_p( $bundle_paths['files_dir'] ) ) {
		return true;
	}

	return new WP_Error( 'bundle_staging_failed', __( 'Could not create EngineScript export staging directories.', 'enginescript-site-exporter' ) );
}

/**
 * Gets an exact local generated-file size for preflight accounting.
 *
 * @since 2.1.1
 * @param string $file_path Generated file path.
 * @return int|WP_Error File size or verification error.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_get_generated_file_size( string $file_path ): int|WP_Error {
	clearstatcache( true, $file_path );
	$file_size = @filesize( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize,WordPress.PHP.NoSilencedErrors.Discouraged -- A disappearing generated file must return a bounded error without leaking its private path.
	if ( false === $file_size ) {
		return new WP_Error( 'export_generated_size_unknown', __( 'Could not verify generated export size.', 'enginescript-site-exporter' ) );
	}

	return $file_size;
}

/**
 * Projects a conservative gzip output bound for one completed input.
 *
 * @since 2.1.1
 * @param int $input_bytes Input size.
 * @return int|WP_Error Projected bytes or overflow error.
 */
function sse_get_projected_gzip_bytes( int $input_bytes ): int|WP_Error {
	$overhead = intdiv( max( 0, $input_bytes ), 1000 ) + 65536;
	if ( $input_bytes > PHP_INT_MAX - $overhead ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	return $input_bytes + $overhead;
}

/**
 * Projects the stored outer ZIP size from its completed payload files.
 *
 * @since 2.1.1
 * @param string[] $file_paths Payload paths.
 * @return int|WP_Error Projected bytes or size error.
 */
function sse_get_projected_zip_bytes( array $file_paths ): int|WP_Error {
	$projected_bytes = 1048576;
	foreach ( $file_paths as $file_path ) {
		$file_size = sse_get_generated_file_size( $file_path );
		if ( is_wp_error( $file_size ) ) {
			return $file_size;
		}

		if ( $file_size > PHP_INT_MAX - $projected_bytes ) {
			return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
		}

		$projected_bytes += $file_size;
	}

	return $projected_bytes;
}

/**
 * Projects a conservative TAR growth bound for one archive entry.
 *
 * The reserve covers the fixed header, block padding, and extended-name
 * metadata that PharData may emit for long archive paths.
 *
 * @since 2.1.1
 * @param int    $source_bytes Source file size, or zero for a directory.
 * @param string $archive_path Relative path stored in the TAR.
 * @return int|WP_Error Projected growth or an overflow error.
 */
function sse_get_projected_tar_entry_bytes( int $source_bytes, string $archive_path ): int|WP_Error {
	$source_bytes = max( 0, $source_bytes );
	$path_bytes   = strlen( $archive_path );
	$base_reserve = 8703;

	if ( $path_bytes > intdiv( PHP_INT_MAX - $base_reserve, 2 ) ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	$metadata_reserve = $base_reserve + ( 2 * $path_bytes );
	if ( $source_bytes > PHP_INT_MAX - $metadata_reserve ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	return $source_bytes + $metadata_reserve;
}

/**
 * Streams a database dump into gzip while checking live export limits.
 *
 * @since 2.1.1
 * @param resource $source_handle Open SQL input handle.
 * @param resource $target_handle Open gzip output handle.
 * @param string   $target_path   Generated gzip path.
 * @return true|WP_Error True when streaming completes, otherwise an error.
 */
function sse_stream_database_to_gzip( $source_handle, $target_handle, string $target_path ): true|WP_Error {
	while ( ! feof( $source_handle ) ) {
		$budget_check = sse_check_export_resource_budget( dirname( $target_path ) );
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		$chunk = fread( $source_handle, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming a large local SQL file.
		if ( false === $chunk || false === gzwrite( $target_handle, $chunk ) || ! fflush( $target_handle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Gzip streaming and flush are required for live output accounting.
			return new WP_Error( 'db_compress_write_failed', __( 'Failed while compressing database dump.', 'enginescript-site-exporter' ) );
		}

		$budget_check = sse_record_generated_export_file( $target_path );
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}
	}

	return true;
}

/**
 * Creates a gzip-compressed copy of the database dump.
 *
 * @since 2.0.0
 * @param string $source_path Source SQL dump path.
 * @param string $target_path Target SQL gzip path.
 * @return true|WP_Error True on success, WP_Error on failure.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_create_compressed_database_file( string $source_path, string $target_path ): true|WP_Error {
	$budget_check = sse_record_generated_export_file( $target_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$source_handle = @fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- A missing private dump returns a bounded error without leaking its path.
	if ( false === $source_handle ) {
		return new WP_Error( 'db_compress_source_failed', __( 'Could not open database dump for compression.', 'enginescript-site-exporter' ) );
	}

	$target_handle = @gzopen( $target_path, 'wb9' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Gzip creation failures return a bounded error without leaking the private path.
	if ( false === $target_handle ) {
		fclose( $source_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing local file handle opened above.
		sse_cleanup_files( [ $target_path ] );
		return new WP_Error( 'db_compress_target_failed', __( 'Could not create compressed database file.', 'enginescript-site-exporter' ) );
	}

	$stream_result = sse_stream_database_to_gzip( $source_handle, $target_handle, $target_path );
	fclose( $source_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing local file handle opened above.
	gzclose( $target_handle );
	if ( is_wp_error( $stream_result ) ) {
		sse_cleanup_files( [ $target_path ] );
		return $stream_result;
	}

	if ( ! sse_filesystem_file_has_content( $target_path ) ) {
		sse_cleanup_files( [ $target_path ] );
		return new WP_Error( 'db_compress_verify_failed', __( 'Compressed database file was not created successfully.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $target_path ) ) {
		sse_cleanup_files( [ $target_path ] );
		return new WP_Error( 'db_compress_permissions_failed', __( 'Could not secure compressed database file permissions.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $target_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $target_path ] );
		return $budget_check;
	}

	return true;
}

/**
 * Builds the temporary uncompressed WordPress TAR.
 *
 * @since 2.1.1
 * @param string $tar_path   Temporary TAR path.
 * @param string $export_dir Export directory to exclude.
 * @return true|WP_Error True on success, otherwise an archive error.
 */
function sse_build_wordpress_tar( string $tar_path, string $export_dir ): true|WP_Error {
	$tar_archive    = null;
	$previous_umask = umask( 0077 );
	try {
		$tar_archive = new PharData( $tar_path );
		$file_result = sse_add_wordpress_files_to_tar( $tar_archive, $export_dir, $tar_path );
		if ( is_wp_error( $file_result ) ) {
			return $file_result;
		}

		// PharData does not materialize a new TAR until its first entry is added.
		if ( ! sse_chmod_private_file( $tar_path ) ) {
			return new WP_Error( 'files_archive_permissions_failed', __( 'Could not secure files archive permissions.', 'enginescript-site-exporter' ) );
		}

		return true;
	} catch ( Exception $e ) {
		return new WP_Error(
			'files_archive_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'Failed to create WordPress files archive: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
	} finally {
		unset( $tar_archive );
		umask( $previous_umask );
	}
}

/**
 * Preflights and compresses one completed temporary TAR.
 *
 * @since 2.1.1
 * @param string $tar_path           Temporary TAR path.
 * @param string $files_archive_path Target tar.gz path.
 * @return true|WP_Error True on success, otherwise an archive error.
 */
function sse_compress_wordpress_tar( string $tar_path, string $files_archive_path ): true|WP_Error {
	$tar_size = sse_get_generated_file_size( $tar_path );
	if ( is_wp_error( $tar_size ) ) {
		return $tar_size;
	}

	$projected_gzip_bytes = sse_get_projected_gzip_bytes( $tar_size );
	if ( is_wp_error( $projected_gzip_bytes ) ) {
		return $projected_gzip_bytes;
	}

	$budget_check = sse_record_generated_export_file( $files_archive_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$budget_check = sse_check_generated_export_capacity( $projected_gzip_bytes, dirname( $files_archive_path ) );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	try {
		$tar_archive = new PharData( $tar_path );
		$tar_archive->compress( Phar::GZ );
		unset( $tar_archive );
	} catch ( Exception $e ) {
		return new WP_Error(
			'files_archive_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'Failed to create WordPress files archive: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
	}

	return sse_record_generated_export_file( $files_archive_path );
}

/**
 * Creates a tar.gz archive of the WordPress files.
 *
 * @since 2.0.0
 * @param string $files_archive_path Target tar.gz path.
 * @param string $export_dir         Export directory to exclude.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_create_wordpress_files_archive( string $files_archive_path, string $export_dir ): true|WP_Error {
	$tar_path = preg_replace( '/\.gz$/', '', $files_archive_path );
	if ( ! is_string( $tar_path ) || '' === $tar_path ) {
		return new WP_Error( 'files_archive_path_failed', __( 'Could not determine files archive path.', 'enginescript-site-exporter' ) );
	}

	sse_cleanup_files( [ $tar_path, $files_archive_path ] );
	$budget_check = sse_record_generated_export_file( $tar_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$tar_result = sse_build_wordpress_tar( $tar_path, $export_dir );
	if ( is_wp_error( $tar_result ) ) {
		sse_cleanup_files( [ $tar_path, $files_archive_path ] );
		return $tar_result;
	}

	$compression_result = sse_compress_wordpress_tar( $tar_path, $files_archive_path );
	if ( is_wp_error( $compression_result ) ) {
		sse_cleanup_files( [ $tar_path, $files_archive_path ] );
		return $compression_result;
	}
	sse_cleanup_files( [ $tar_path ] );

	if ( ! sse_filesystem_file_has_content( $files_archive_path ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return new WP_Error( 'files_archive_verify_failed', __( 'WordPress files archive was not created successfully.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $files_archive_path ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return new WP_Error( 'files_archive_permissions_failed', __( 'Could not secure files archive permissions.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $files_archive_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return $budget_check;
	}

	return true;
}

/**
 * Writes the EngineScript archive manifest.
 *
 * @since 2.0.0
 * @param array  $bundle_paths     Bundle paths.
 * @param string $site_identifier Sanitized site identifier.
 * @psalm-param array{manifest_path: string, database_gz_filename: string, files_archive_filename: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_write_engine_script_manifest( array $bundle_paths, string $site_identifier ): true|WP_Error {
	$manifest_content = implode(
		"\n",
		[
			'format=enginescript-site-archive',
			'version=1',
			'site=' . $site_identifier,
			'created_at_utc=' . gmdate( 'Y-m-d\TH:i:s\Z' ),
			'database_path=database/' . $bundle_paths['database_gz_filename'],
			'files_archive_path=files/' . $bundle_paths['files_archive_filename'],
		]
	) . "\n";

	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return $filesystem;
	}

	if ( ! $filesystem->put_contents( $bundle_paths['manifest_path'], $manifest_content, SSE_PRIVATE_FILE_MODE ) ) {
		return new WP_Error( 'manifest_write_failed', __( 'Could not write EngineScript export manifest.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $bundle_paths['manifest_path'] ) ) {
		return new WP_Error( 'manifest_permissions_failed', __( 'Could not secure EngineScript export manifest permissions.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $bundle_paths['manifest_path'] );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	return true;
}

/**
 * Registers and preflights the projected outer ZIP output.
 *
 * @since 2.1.1
 * @param array<string,string> $entries  ZIP entry paths keyed by archive name.
 * @param string               $zip_path Final ZIP path.
 * @return int|WP_Error Projected ZIP bytes or error.
 */
function sse_prepare_combined_zip_output( array $entries, string $zip_path ): int|WP_Error {
	$projected_zip_bytes = sse_get_projected_zip_bytes( array_values( $entries ) );
	if ( is_wp_error( $projected_zip_bytes ) ) {
		return $projected_zip_bytes;
	}

	sse_cleanup_files( [ $zip_path ] );
	$budget_check = sse_record_generated_export_file( $zip_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$budget_check = sse_check_generated_export_capacity( $projected_zip_bytes, dirname( $zip_path ) );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$lease_check = sse_renew_current_export_lease( true );
	return is_wp_error( $lease_check ) ? $lease_check : $projected_zip_bytes;
}

/**
 * Queues canonical EngineScript entries without finalizing the ZIP.
 *
 * @since 2.1.1
 * @param ZipArchive           $zip      Open ZIP archive.
 * @param array<string,string> $entries  ZIP entry paths keyed by archive name.
 * @param string               $zip_path Final ZIP path.
 * @return true|WP_Error True when every entry is queued.
 */
function sse_add_combined_zip_entries( ZipArchive $zip, array $entries, string $zip_path ): true|WP_Error {
	if ( ! $zip->addEmptyDir( 'database' ) ) {
		return new WP_Error( 'zip_directory_add_failed', __( 'Failed to add EngineScript directories to ZIP archive.', 'enginescript-site-exporter' ) );
	}
	if ( ! $zip->addEmptyDir( 'files' ) ) {
		return new WP_Error( 'zip_directory_add_failed', __( 'Failed to add EngineScript directories to ZIP archive.', 'enginescript-site-exporter' ) );
	}

	foreach ( $entries as $entry_name => $entry_path ) {
		$budget_check = sse_check_export_resource_budget( dirname( $zip_path ) );
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		if ( ! $zip->addFile( $entry_path, $entry_name ) ) {
			return new WP_Error( 'zip_payload_add_failed', __( 'Failed to add EngineScript payload file to ZIP archive.', 'enginescript-site-exporter' ) );
		}

		if ( ! $zip->setCompressionName( $entry_name, ZipArchive::CM_STORE ) ) {
			return new WP_Error( 'zip_store_mode_failed', __( 'Failed to store EngineScript ZIP payload without recompression.', 'enginescript-site-exporter' ) );
		}
	}

	return true;
}

/**
 * Renews ownership and reserves remaining ZIP growth before close().
 *
 * @since 2.1.1
 * @param string $zip_path            Open ZIP path.
 * @param int    $projected_zip_bytes Conservative final ZIP projection.
 * @return true|WP_Error True when close may proceed.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_preflight_combined_zip_close( string $zip_path, int $projected_zip_bytes ): true|WP_Error {
	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	clearstatcache( true, $zip_path );
	$current_zip_bytes = @filesize( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize,WordPress.PHP.NoSilencedErrors.Discouraged -- Some libzip builds defer creating the output until close; that expected state counts as zero current bytes.
	$current_zip_bytes = false === $current_zip_bytes ? 0 : $current_zip_bytes;
	$remaining_bytes   = max( 0, $projected_zip_bytes - $current_zip_bytes );
	return sse_check_generated_export_capacity( $remaining_bytes, dirname( $zip_path ) );
}

/**
 * Closes, verifies, secures, and records the final ZIP.
 *
 * @since 2.1.1
 * @param ZipArchive $zip      Open ZIP archive.
 * @param string     $zip_path Final ZIP path.
 * @return true|WP_Error True on success, otherwise a finalization error.
 */
function sse_finalize_combined_zip( ZipArchive $zip, string $zip_path ): true|WP_Error {
	$zip_close_status = $zip->close();
	$budget_check     = sse_record_generated_export_file( $zip_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return $budget_check;
	}

	if ( ! $zip_close_status || ! sse_filesystem_file_has_content( $zip_path ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return new WP_Error( 'zip_finalize_failed', __( 'Failed to finalize or save the ZIP archive after processing files.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $zip_path ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return new WP_Error( 'zip_permissions_failed', __( 'Could not secure ZIP archive permissions.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $zip_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return $budget_check;
	}

	return true;
}

/**
 * Creates the outer EngineScript ZIP archive.
 *
 * @since 2.0.0
 * @param array $bundle_paths Bundle paths.
 * @psalm-param array{combined_zip_path: string, manifest_path: string, database_path: string, database_gz_filename: string, files_archive_path: string, files_archive_filename: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_create_combined_engine_script_zip( array $bundle_paths ): true|WP_Error {
	$entries = [
		'manifest.txt'                                     => $bundle_paths['manifest_path'],
		'database/' . $bundle_paths['database_gz_filename'] => $bundle_paths['database_path'],
		'files/' . $bundle_paths['files_archive_filename'] => $bundle_paths['files_archive_path'],
	];

	$projected_zip_bytes = sse_prepare_combined_zip_output( $entries, $bundle_paths['combined_zip_path'] );
	if ( is_wp_error( $projected_zip_bytes ) ) {
		return $projected_zip_bytes;
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $bundle_paths['combined_zip_path'], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		sse_cleanup_files( [ $bundle_paths['combined_zip_path'] ] );
		return new WP_Error(
			'zip_create_failed',
			sprintf(
				/* translators: %s: ZIP file path. */
				__( 'Could not create the ZIP file at %s.', 'enginescript-site-exporter' ),
				wp_basename( $bundle_paths['combined_zip_path'] )
			)
		);
	}

	$entry_result = sse_add_combined_zip_entries( $zip, $entries, $bundle_paths['combined_zip_path'] );
	if ( is_wp_error( $entry_result ) ) {
		sse_discard_combined_zip( $zip, $bundle_paths['combined_zip_path'] );
		return $entry_result;
	}

	$close_preflight = sse_preflight_combined_zip_close( $bundle_paths['combined_zip_path'], $projected_zip_bytes );
	if ( is_wp_error( $close_preflight ) ) {
		sse_discard_combined_zip( $zip, $bundle_paths['combined_zip_path'] );
		return $close_preflight;
	}

	return sse_finalize_combined_zip( $zip, $bundle_paths['combined_zip_path'] );
}

/**
 * Cancels queued ZIP mutations before closing and removing partial output.
 *
 * @since 2.1.1
 * @param ZipArchive $zip      Open ZIP archive.
 * @param string     $zip_path Partial ZIP path.
 * @return void
 */
function sse_discard_combined_zip( ZipArchive $zip, string $zip_path ): void {
	$zip->unchangeAll();
	$zip->close();
	sse_cleanup_files( [ $zip_path ] );
}

/**
 * Deletes a directory tree created during export staging.
 *
 * @since 2.0.0
 * @param string $directory Directory to delete.
 * @return bool True if deleted or absent, false on failure.
 */
function sse_delete_directory_tree( string $directory ): bool {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return false;
	}

	$export_dir = sse_get_export_directory_path();
	if ( is_wp_error( $export_dir ) ) {
		return false;
	}

	if ( ! sse_is_path_within_directory( $directory, $export_dir ) ) {
		return false;
	}

	if ( ! $filesystem->exists( $directory ) ) {
		return true;
	}

	return $filesystem->delete( $directory, true, 'd' );
}

/**
 * Adds WordPress files to a TAR archive.
 *
 * @since 1.0.0
 * @param PharData $tar        The tar archive object.
 * @param string   $export_dir The export directory to exclude.
 * @param string   $tar_path   Temporary uncompressed TAR path.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_add_wordpress_files_to_tar( PharData $tar, string $export_dir, string $tar_path ): true|WP_Error {
	$source_path = realpath( ABSPATH );
	if ( false === $source_path ) {
		sse_log( 'Could not resolve real path for ABSPATH. Using ABSPATH directly.', 'warning' );
		$source_path = ABSPATH;
	}
	$source_path = untrailingslashit( wp_normalize_path( $source_path ) );

	try {
		$files               = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_path, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		$projected_tar_bytes = 0;
		$tar->startBuffering();

		/**
		 * Current filesystem entry.
		 *
		 * @var SplFileInfo $file_info
		 */
		foreach ( $files as $file_info ) {
			$file_result = sse_process_file_for_tar( $tar, $file_info, $source_path, $export_dir, $tar_path, $projected_tar_bytes );
			if ( is_wp_error( $file_result ) ) {
				$tar->stopBuffering();
				return $file_result;
			}
		}
		$tar->stopBuffering();
	} catch ( RuntimeException $e ) {
		if ( $tar->isBuffering() ) {
			$tar->stopBuffering();
		}
		return new WP_Error(
			'file_iteration_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'Error during file processing: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
	} catch ( Exception $e ) {
		if ( $tar->isBuffering() ) {
			$tar->stopBuffering();
		}
		return new WP_Error(
			'file_iteration_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'Error during file processing: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
	}

	return sse_record_generated_export_file( $tar_path );
}

/**
 * Processes a single file for addition to the TAR archive.
 *
 * @since 2.0.0
 * @param PharData    $tar         Tar archive object.
 * @param SplFileInfo $file_info   File information object.
 * @param string      $source_path Source directory path.
 * @param string      $export_dir  Export directory to exclude.
 * @param string      $tar_path    Temporary uncompressed TAR path.
 * @param int         $projected_tar_bytes Cumulative projected TAR bytes.
 * @return true|null|WP_Error True on success, null if skipped, WP_Error on failure.
 */
function sse_process_file_for_tar( PharData $tar, SplFileInfo $file_info, string $source_path, string $export_dir, string $tar_path, int &$projected_tar_bytes ): true|null|WP_Error {
	if ( ! $file_info->isReadable() ) {
		sse_log( 'Skipping unreadable file or directory: ' . $file_info->getPathname(), 'warning' );
		return null;
	}

	if ( $file_info->isLink() ) {
		sse_log( 'Skipping symbolic link during export: ' . $file_info->getPathname(), 'warning' );
		return null;
	}

	$file          = $file_info->getRealPath();
	$pathname      = wp_normalize_path( $file_info->getPathname() );
	$relative_path = ltrim( substr( $pathname, strlen( $source_path ) ), '/' );

	if ( false === $file || ! sse_is_path_within_export_source( $file, $source_path ) ) {
		sse_log( 'Skipping file outside export source: ' . $pathname, 'warning' );
		return null;
	}

	if ( empty( $relative_path ) ) {
		return null;
	}

	if ( sse_should_exclude_file( $pathname, $relative_path, $export_dir, $file_info ) ) {
		return null;
	}

	$source_bytes = $file_info->isFile() ? $file_info->getSize() : 0;
	$budget_check = sse_record_export_source_entry( $source_bytes, $source_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	return sse_add_file_to_tar( $tar, $file_info, $file, $pathname, $relative_path, $tar_path, $projected_tar_bytes );
}

/**
 * Reserves aggregate capacity for one buffered TAR entry.
 *
 * @since 2.1.1
 * @param int    $entry_bytes         Projected bytes for the next entry.
 * @param int    $projected_tar_bytes Cumulative projected TAR bytes.
 * @param string $tar_path            Temporary uncompressed TAR path.
 * @return true|WP_Error True when the cumulative projection fits.
 */
function sse_reserve_tar_entry_capacity( int $entry_bytes, int &$projected_tar_bytes, string $tar_path ): true|WP_Error {
	if ( $entry_bytes > PHP_INT_MAX - $projected_tar_bytes ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	$next_projection = $projected_tar_bytes + $entry_bytes;
	$budget_check    = sse_check_generated_export_capacity( $next_projection, dirname( $tar_path ) );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$projected_tar_bytes = $next_projection;
	return true;
}

/**
 * Adds a file or directory to the TAR archive.
 *
 * @since 1.0.0
 * @param PharData     $tar           The tar archive object.
 * @param SplFileInfo  $file_info     File information object.
 * @param string|false $file          Real file path or false if getRealPath() failed.
 * @param string       $pathname      Original pathname.
 * @param string       $relative_path Relative path in archive.
 * @param string       $tar_path      Temporary uncompressed TAR path.
 * @param int          $projected_tar_bytes Cumulative projected TAR bytes.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_add_file_to_tar( PharData $tar, SplFileInfo $file_info, string|false $file, string $pathname, string $relative_path, string $tar_path, int &$projected_tar_bytes ): true|WP_Error {
	try {
		if ( $file_info->isDir() ) {
			$projected_bytes = sse_get_projected_tar_entry_bytes( 0, $relative_path );
			if ( is_wp_error( $projected_bytes ) ) {
				return $projected_bytes;
			}

			$budget_check = sse_reserve_tar_entry_capacity( $projected_bytes, $projected_tar_bytes, $tar_path );
			if ( is_wp_error( $budget_check ) ) {
				return $budget_check;
			}

			$tar->addEmptyDir( $relative_path );
			return true;
		}

		if ( $file_info->isFile() ) {
			// Use real path (getRealPath() must succeed for security).
			if ( false === $file ) {
				sse_log( 'Skipping file with unresolvable real path: ' . $pathname, 'warning' );
				return true; // Skip this file but continue processing.
			}

			$projected_bytes = sse_get_projected_tar_entry_bytes( $file_info->getSize(), $relative_path );
			if ( is_wp_error( $projected_bytes ) ) {
				return $projected_bytes;
			}

			$budget_check = sse_reserve_tar_entry_capacity( $projected_bytes, $projected_tar_bytes, $tar_path );
			if ( is_wp_error( $budget_check ) ) {
				return $budget_check;
			}

			$tar->addFile( wp_normalize_path( $file ), $relative_path );
			return true;
		}
	} catch ( Exception $e ) {
		sse_log( 'Failed to add file to TAR archive: ' . $relative_path . ' (source: ' . $pathname . '): ' . $e->getMessage(), 'error' );
		return new WP_Error(
			'file_add_failed',
			sprintf(
				/* translators: %s: file path */
				__( 'Failed to add file to archive: %s', 'enginescript-site-exporter' ),
				$relative_path
			)
		);
	}

	return true;
}

/**
 * Determines if a file should be excluded from the export.
 *
 * @since 1.0.0
 * @param string      $pathname      The full pathname.
 * @param string      $relative_path The relative path.
 * @param string      $export_dir    The export directory to exclude.
 * @param SplFileInfo $file_info     File information object.
 * @return bool True if file should be excluded.
 */
function sse_should_exclude_file( string $pathname, string $relative_path, string $export_dir, SplFileInfo $file_info ): bool {
	// Exclude export directory.
	if ( str_starts_with( $pathname, $export_dir ) ) {
		return true;
	}

	// Exclude cache and temporary directories.
	if ( preg_match( '#^wp-content/(cache|upgrade|temp)/#', $relative_path ) ) {
		return true;
	}

	// Exclude version control and system files.
	if ( preg_match( '#(^|/)\.(git|svn|hg|DS_Store|htaccess|user\.ini)$#i', $relative_path ) ) {
		return true;
	}

	// Exclude files based on size.
	if ( $file_info->isFile() ) {
		// Cache the max file size to avoid repeated transient/filter lookups per file.
		/**
		 * Maximum file size for this request.
		 *
		 * @var int|null $cached_max_file_size
		 */
		static $cached_max_file_size = null;

		/**
		 * Filters the maximum allowed file size for inclusion in the export.
		 *
		 * @since 1.8.5
		 *
		 * @param int $max_file_size Maximum file size in bytes. Default is user's selection or 0 (no limit).
		 */
		if ( null === $cached_max_file_size ) {
			$selected_max_file_size = sse_normalize_nonnegative_integer( get_transient( 'sse_export_max_file_size_' . get_current_user_id() ) );
			$selected_max_file_size = false === $selected_max_file_size ? 0 : $selected_max_file_size;
			$filtered_max_file_size = sse_normalize_nonnegative_integer( apply_filters( SSE_FILTER_MAX_FILE_SIZE, $selected_max_file_size ) );
			$cached_max_file_size   = false === $filtered_max_file_size ? $selected_max_file_size : $filtered_max_file_size;
		}

		if ( $cached_max_file_size > 0 && $file_info->getSize() > $cached_max_file_size ) {
			$file_size_label  = sse_normalize_string_value( size_format( $file_info->getSize() ), (string) $file_info->getSize() . ' B' );
			$limit_size_label = sse_normalize_string_value( size_format( $cached_max_file_size ), (string) $cached_max_file_size . ' B' );

			sse_log( 'Excluding large file: ' . $pathname . ' (Size: ' . $file_size_label . ', Limit: ' . $limit_size_label . ')', 'info' );
			return true;
		}
	}

	return false;
}
