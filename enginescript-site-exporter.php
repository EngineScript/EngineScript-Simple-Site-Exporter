<?php
/**
 * Plugin Name: EngineScript Site Exporter
 * Description: Exports the site files and the database as an EngineScript-compatible site archive.
 * Version: 2.1.0
 * Author: EngineScript
 * Requires at least: 6.8
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: enginescript-site-exporter
 * Domain Path: /languages
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct access. Note: Using return here instead of exit.
if ( ! defined( 'ABSPATH' ) ) {
	return; // Prevent direct access.
}

// Define plugin version.
if ( ! defined( 'ES_SITE_EXPORTER_VERSION' ) ) {
	define( 'ES_SITE_EXPORTER_VERSION', '2.1.0' );
}

// Define allowed file extensions for export operations.
if ( ! defined( 'SSE_ALLOWED_EXTENSIONS' ) ) {
	define( 'SSE_ALLOWED_EXTENSIONS', [ 'zip' ] );
}

// Define export directory name used across the plugin.
if ( ! defined( 'SSE_EXPORT_DIR_NAME' ) ) {
	define( 'SSE_EXPORT_DIR_NAME', 'enginescript-site-exporter-exports' );
}

// Define private export directory naming and filesystem modes.
if ( ! defined( 'SSE_EXPORT_PRIVATE_DIR_PREFIX' ) ) {
	define( 'SSE_EXPORT_PRIVATE_DIR_PREFIX', 'export-' );
}

if ( ! defined( 'SSE_PRIVATE_DIR_MODE' ) ) {
	define( 'SSE_PRIVATE_DIR_MODE', 0700 );
}

if ( ! defined( 'SSE_PRIVATE_FILE_MODE' ) ) {
	define( 'SSE_PRIVATE_FILE_MODE', 0600 );
}

// Define the filter name for maximum file size override.
if ( ! defined( 'SSE_FILTER_MAX_FILE_SIZE' ) ) {
	define( 'SSE_FILTER_MAX_FILE_SIZE', 'sse_max_file_size_for_export' );
}

// Define the EngineScript archive filename marker used for generated export ZIP files.
if ( ! defined( 'SSE_EXPORT_ARCHIVE_MARKER' ) ) {
	define( 'SSE_EXPORT_ARCHIVE_MARKER', 'enginescript_site_export' );
}

// Define conservative aggregate export resource limits.
if ( ! defined( 'SSE_DEFAULT_MAX_EXPORT_ENTRIES' ) ) {
	define( 'SSE_DEFAULT_MAX_EXPORT_ENTRIES', 250000 );
}

if ( ! defined( 'SSE_DEFAULT_MAX_EXPORT_SOURCE_BYTES' ) ) {
	define( 'SSE_DEFAULT_MAX_EXPORT_SOURCE_BYTES', 53687091200 );
}

if ( ! defined( 'SSE_DEFAULT_MAX_EXPORT_GENERATED_BYTES' ) ) {
	define( 'SSE_DEFAULT_MAX_EXPORT_GENERATED_BYTES', 107374182400 );
}

if ( ! defined( 'SSE_DEFAULT_MIN_FREE_DISK_BYTES' ) ) {
	define( 'SSE_DEFAULT_MIN_FREE_DISK_BYTES', 1073741824 );
}

if ( ! defined( 'SSE_DEFAULT_MAX_EXPORT_SECONDS' ) ) {
	define( 'SSE_DEFAULT_MAX_EXPORT_SECONDS', 1800 );
}

if ( ! defined( 'SSE_DEFAULT_EXPORT_RECOVERY_GRACE_SECONDS' ) ) {
	define( 'SSE_DEFAULT_EXPORT_RECOVERY_GRACE_SECONDS', 300 );
}

if ( ! defined( 'SSE_DEFAULT_PROCESS_TERMINATION_GRACE_MILLISECONDS' ) ) {
	define( 'SSE_DEFAULT_PROCESS_TERMINATION_GRACE_MILLISECONDS', 2000 );
}

if ( ! defined( 'SSE_DEFAULT_PROCESS_FORCE_GRACE_MILLISECONDS' ) ) {
	define( 'SSE_DEFAULT_PROCESS_FORCE_GRACE_MILLISECONDS', 2000 );
}

// Define plugin file constant for use in included files.
if ( ! defined( 'SSE_PLUGIN_FILE' ) ) {
	define( 'SSE_PLUGIN_FILE', __FILE__ );
}

/**
 * WordPress and PHP runtime dependencies.
 *
 * WordPress supplies WP_Error; PHP supplies the other classes below when the
 * required extensions are enabled. Archive requirements are checked before
 * export work begins. These global classes do not need namespace imports.
 *
 * Runtime classes used:
 *
 * @see WP_Error - WordPress error handling class
 * @see ZipArchive - PHP ZipArchive class
 * @see Phar - PHP Phar class
 * @see PharData - PHP PharData class
 * @see RecursiveIteratorIterator - PHP SPL iterator
 * @see RecursiveDirectoryIterator - PHP SPL directory iterator
 * @see SplFileInfo - PHP SPL file information class
 * @see RuntimeException - PHP runtime exception class
 * @see Exception - PHP base exception class
 */

// Load plugin components.
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/cleanup.php';
require_once __DIR__ . '/includes/download.php';

/**
 * Initialize the EngineScript Site Exporter plugin.
 *
 * This function is hooked to 'plugins_loaded' to ensure that all other plugins
 * and WordPress core functions are available before initializing this plugin.
 * This prevents load order issues and conflicts with other plugins.
 *
 * @since 1.8.5
 * @return void
 */
function sse_init_plugin(): void {
	// Hook single-site and multisite Network Admin menu creation.
	add_action( 'admin_menu', 'sse_admin_menu' );
	add_action( 'network_admin_menu', 'sse_network_admin_menu' );

	// Hook admin assets.
	add_action( 'admin_enqueue_scripts', 'sse_enqueue_admin_assets' );

	// Hook export handler.
	add_action( 'admin_post_sse_export_site', 'sse_handle_export' );

	// Hook scheduled deletion handler.
	add_action( 'sse_delete_export_file', 'sse_delete_export_file_handler' );

	// Hook bulk cleanup handler.
	add_action( 'sse_bulk_cleanup_exports', 'sse_bulk_cleanup_exports_handler' );

	// Hook independent stale-export recovery and recurring housekeeping.
	add_action( 'sse_recover_expired_export', 'sse_recover_expired_export_handler' );
	add_action( 'sse_export_housekeeping', 'sse_export_housekeeping_handler' );
	sse_schedule_export_housekeeping();

	// Hook secure download handler.
	add_action( 'admin_post_sse_secure_download', 'sse_handle_secure_download' );

	// Hook export deletion handler.
	add_action( 'admin_post_sse_delete_export', 'sse_handle_export_deletion' );
}

/**
 * Removes the recurring housekeeping event when the plugin is deactivated.
 *
 * Lease-specific recovery events are intentionally left in place so they are
 * not discarded if the plugin is reactivated before recovery becomes due.
 *
 * @since 2.1.1
 * @param bool $_network_wide Whether the plugin is being deactivated network-wide.
 * @return void
 */
function sse_deactivate_plugin( bool $_network_wide = false ): void {
	if ( $_network_wide ) {
		sse_log( 'Exporter housekeeping is being deactivated in the current network context.', 'info' );
	}

	wp_clear_scheduled_hook( 'sse_export_housekeeping' );
}

// Initialize the plugin when all plugins are loaded.
add_action( 'plugins_loaded', 'sse_init_plugin' );
register_deactivation_hook( SSE_PLUGIN_FILE, 'sse_deactivate_plugin' );
