=== EngineScript Site Exporter ===
Contributors: enginescript
Tags: backup, export, migration, site export, database export
Requires at least: 6.8
Tested up to: 7.0
Stable tag: 2.1.0
Requires PHP: 8.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Export your entire WordPress site as a secure downloadable EngineScript-compatible ZIP archive.

== Description ==

EngineScript Site Exporter lets authorized WordPress administrators export the database and eligible files under the WordPress installation directory for migration or backup. File exclusions and resource limits apply; verify the archive before relying on it as a backup.

Key features:
* One-Click Export: Start a site export from the WordPress admin page
* EngineScript Archive Format: Creates the combined ZIP format accepted by EngineScript's current vhost-import.sh
* Database Export: Includes a gzip-compressed database dump in your export
* Automatic Cleanup: Schedules export deletion for 5 minutes after creation
* Secure Downloads: All exports use WordPress security tokens for protected access
* WP-CLI Integration: Requires WP-CLI for efficient database exports
* Export Management: Download or manually delete export files as needed
* EngineScript Import Compatibility: Produces the archive layout used by EngineScript's import tools

This plugin does not detect or require an EngineScript server. It can run on standard WordPress installations, while the generated archive format is designed for the EngineScript LEMP server import workflow:

* Compatible Exports: Exports use the ZIP container expected by EngineScript's site import tools
* Streamlined Migrations: Export from a WordPress site that meets the requirements below for import into an EngineScript-powered server
* Format Optimization: The bundle layout matches EngineScript's manifest.txt plus compressed database/files archive format

The export format matches EngineScript's canonical combined site archive:

`manifest.txt`
`database/<site>_db_<timestamp>.sql.gz`
`files/<site>_files_<timestamp>.tar.gz`

The downloaded ZIP is named `<site>_enginescript_site_export_<timestamp>.zip`.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/enginescript-site-exporter` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. On a single-site installation, navigate to Tools → Site Exporter. On multisite, use Settings → Site Exporter in Network Admin.
4. Click the "Export Site" button to create a site archive.

Exports require 64-bit PHP 8.2 or higher with ZipArchive, PharData, and gzip support, plus direct local filesystem access through the WordPress Filesystem API and a private writable temporary directory outside the WordPress web root.

Database exports require a POSIX host with PHP's POSIX functions, a trusted `/usr/bin/setsid` or `/bin/setsid` executable, and WP-CLI at a trusted configured path. These prerequisites let the exporter stop the complete WP-CLI/database-client process group on failure or timeout; unsupported hosts fail closed before process launch.

== Frequently Asked Questions ==

= How large of a site can I export? =

Default limits are 250,000 source entries, 50 GiB of source data, 100 GiB of generated files, 30 minutes of processing time, and a 1 GiB free-disk reserve. Server administrators can tune the export filters; hosting limits may stop work earlier. The per-file size selector does not disable these overall limits.

= Where are the export files stored? =

Exports are staged in WordPress' temporary directory under:
`<temp-dir>/enginescript-site-exporter-exports/`

Each export is written inside a random private child directory with private filesystem permissions. For security, the plugin refuses to export if the export directory resolves inside the WordPress web root. Configure `WP_TEMP_DIR` to a private writable path if your host's default temporary directory is public.

= When are export files deleted? =

The plugin schedules deletion for 5 minutes after creation to reduce the time sensitive data remains on the server. Actual deletion depends on WordPress cron running successfully, so it may occur later. Download the archive promptly and delete it manually when finished. If `DISABLE_WP_CRON` is enabled, configure an external cron runner.

= Can I create multiple exports? =

Yes, sequentially. Only one export can run at a time within a single-site installation or the current multisite network. Each export uses a separate random private directory.

= Does this include my themes and plugins? =

The export includes eligible themes, plugins, uploads, and other files under the WordPress installation directory, plus the database dump. It skips symbolic links, unreadable entries, selected cache and temporary paths, certain hidden files, and files excluded by the chosen per-file size limit. Files outside that directory, such as a parent-directory `wp-config.php`, are not included. On multisite, the export covers the network database and shared installation, not just one blog.

= What information is logged? =

When both `WP_DEBUG` and `WP_DEBUG_LOG` are enabled, the plugin writes diagnostic messages to the WordPress debug log. It also stores up to 20 error or security records per site in the database, including the time, severity, message, user ID, and client IP address when available. Stored messages redact local absolute paths and are limited to 1,000 bytes.

Database records older than seven days are removed when housekeeping, recovery, or a later log write runs; cron delays can extend that period. The WordPress debug log is separate: messages there can include local paths, and its retention and access controls are managed by the host. Review and redact logs before sharing them.

= Can I use this plugin with non-EngineScript servers? =

Absolutely. The plugin does not require an EngineScript server; it creates an archive format designed for EngineScript's importer.

= Will this work on shared hosting environments? =

It can, provided the host meets the PHP, POSIX process-supervision, WP-CLI, and private temporary-directory requirements above. Hosts that disable the required process functions or do not provide a trusted `setsid` executable are not supported for database exports. Large sites may also encounter host-specific memory or storage limits.

== License ==

This plugin is licensed under the GPL v3 or later.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.

== Changelog ==

Released entries describe their historical versions, including earlier tool results and security claims. They are not guarantees about the current build; see the current installation instructions and FAQs for supported behavior.

= 2.1.1 - Unreleased =
* **Security**: Refresh executable metadata before identity checks, verify root-owned executables by native numeric UID, retain PHP 8.2 child exit status across group-liveness polls, cap each diagnostic read at 32 KiB, reject non-removable download output buffers without emitting cleanup warnings, and stop the owned WP-CLI/database-client POSIX process group on failure or timeout
* **Security**: On multisite, exporter page access, export creation, secure download, and manual delete now require a super admin or `manage_network_options`; single-site installs continue to require `manage_options`
* **Security**: Exports are staged in random private child directories with symlink/pre-existing directory rejection and enforced `0700` directory permissions
* **Security**: Export artifacts now use private umask handling plus WordPress Filesystem-backed `0600` chmod verification for database dumps, compressed payloads, file archives, manifests, final ZIPs, and protection files
* **Security**: WP-CLI discovery now prefers `/usr/local/bin/wp` and `/usr/bin/wp`; alternate executables must be explicitly configured and pass ownership/writable-mode checks
* **Security**: Generated download and delete actions include the private export directory identifier in request data and nonce actions
* **Security**: WP-CLI database exports now use shell-free argument execution with executable identity checks, live output monitoring, remaining-budget-derived timeouts, and bounded graceful/forced termination
* **Security**: Download, deletion, and cleanup routes now require generated private directories; downloads stream an identity-checked open handle only after all output buffers are cleared
* **Security**: Replaced the transient export lock with a renewable owner-bound current-site or current-network lease plus byte-exact release and scheduled stale recovery
* **Security**: Added filterable aggregate entry, source-byte, generated-byte, elapsed-time, and free-disk limits with live SQL, gzip, TAR, and ZIP accounting
* **Privacy**: Stored security/error logs now validate record shape, redact local paths, expire after seven days through recurring housekeeping, and remain capped at 20 entries
* **Cleanup**: Bulk cleanup excludes the active lease directory, immediate failure cleanup removes newly created staging directories, and owner-bound recovery removes interrupted private staging directories through containment-checked deletion
* **Architecture**: Replaced direct file metadata checks, generated artifact verification, export cleanup directory scans, and filename basename extraction with WordPress Filesystem API methods and native WordPress helpers where available
* **Architecture**: Export operations now require WordPress' direct local filesystem transport and cleanup cron scheduling records native `WP_Error` diagnostics
* **Architecture**: Filesystem and database callers now use typed WordPress boundary accessors, including the native `%i` database-table identifier placeholder, instead of rereading mixed globals
* **Architecture**: The exporter remains beneath Tools on single-site installations and appears only beneath Settings in Network Admin on multisite; redirects and page-scoped assets use the matching canonical WordPress admin contract
* **Architecture**: TAR creation now uses a private umask, buffers entry mutations to avoid per-file archive rewrites, enforces cumulative projected capacity, and verifies permissions after PharData materializes the archive
* **Architecture**: PHP's request timer now aligns with the filterable export-time policy, capped at 30 minutes, instead of allowing a default 30-second limit to abort valid archive work
* **PHP**: Added native PHP 8.2 union types and modern syntax, and moved 64-bit/archive prerequisite checks before directory, WP-CLI, resource-limit, or database work
* **Accessibility**: Updated warning text for WCAG AA normal-text contrast and forced-colors support while preserving keyboard focus and responsive action wrapping
* **Tooling**: Removed broad Psalm suppressions in favor of narrow source-boundary annotations; strict Psalm level 1 and PHPStan max now report no errors without new suppressions or weaker policy
* **Tooling**: Added VIP Coding Standards as a Composer-managed dev dependency so PHPCS can run the VIP ruleset reproducibly
* **Tooling**: Added a Composer-managed semantic versioning library for future version-related tests
* **Documentation**: Updated WP-CLI, multisite authorization, canonical admin navigation, and private export storage guidance
* **Text and Localization**: Clarified scheduled cleanup, file exclusions, size limits, runtime requirements, and debug-log privacy; corrected user messages and developer comments; regenerated the translation catalog

= 2.1.0 =
* **Security**: Added `.htaccess` file to export directory with `Deny from all` rules to prevent direct HTTP access to export files
* **Security**: Removed usage of `_get_cron_array()` private WordPress API from cron failure diagnostics
* **Security**: Replaced `glob()` with `scandir()` in bulk cleanup handler for cross-platform compatibility
* **Security**: File download functions now use `realpath()`-resolved paths for all filesystem operations to prevent SSRF
* **Security**: Replaced inline `onclick` JS with external script file for Content Security Policy compliance
* **Security**: Moved generated export ZIPs to WordPress' private temporary directory and fail safely if that directory resolves inside the WordPress web root
* **Security**: Switched export, download, and delete handlers to authenticated `admin-post.php` actions with native nonce checks
* **Security**: Hardened scheduled cleanup, symlink handling, WP-CLI lookup, download path containment, manual deletion method, and export file extension validation
* **EngineScript Compatibility**: Updated exports to the canonical combined site archive format with `manifest.txt`, `database/<site>_db_<timestamp>.sql.gz`, and `files/<site>_files_<timestamp>.tar.gz`
* **Bug Fix**: Corrected README.md cleanup timer from "1 hour" to "5 minutes"
* **Bug Fix**: Removed unused `$export_dir_name` variable in admin page
* **Bug Fix**: Removed unnecessary phpcs suppression comment on properly escaped output
* **Bug Fix**: Updated GEMINI.md WP-CLI section to reflect required dependency status
* **Bug Fix**: Corrected WP-CLI description from "when available" to "requires" in README.md and readme.txt
* **Bug Fix**: Renamed the generated WordPress compatibility test file/class pair so PHPUnit can discover it reliably
* **Bug Fix**: Pinned the generated WordPress compatibility test job to PHPUnit 9.6 with Yoast PHPUnit Polyfills 4.x because the WordPress test library still calls PHPUnit APIs removed in PHPUnit 10+
* **CI Coverage**: Added PHP syntax linting, PHPUnit dependency verification, hook registration checks, constant checks, security helper tests, and a PHP 8.2/latest WordPress lowest-dependency matrix run
* **Architecture**: Extracted duplicated WP_Filesystem initialization into `sse_init_filesystem()` helper
* **Architecture**: Inlined 3 pass-through wrapper functions for simpler call graph
* **Architecture**: Removed 2 redundant intermediate download validation passes
* **Architecture**: Consolidated 7-deep path resolution chain into single `sse_resolve_file_path()` function
* **Architecture**: Removed no-op `sse_prepare_execution_environment()` function
* **Architecture**: Removed `sse_test_cron_scheduling()` debug function from export flow
* **Architecture**: Reduced cron scheduling logging from 5+ entries to 2 per operation
* **Architecture**: Extracted 7 inline styles into `css/admin.css` with semantic CSS classes
* **Architecture**: Extracted inline JS confirmation dialog into `js/admin.js`
* **Architecture**: Added `sse_enqueue_admin_assets()` for proper CSS/JS enqueueing on plugin page only
* **Architecture**: Rewrote copilot-instructions.md for clarity, removed irrelevant references
* **Architecture**: Split monolithic plugin file (~1,400 lines) into 112-line bootstrap + 7 include files under `includes/`
* **Architecture**: Added `SSE_PLUGIN_FILE` constant for correct `plugin_dir_url()` resolution in include files
* **Architecture**: Added `SSE_FILTER_MAX_FILE_SIZE` constant replacing hardcoded filter name string
* **Architecture**: Added `sanitize_text_field()` to WP-CLI error output for defense-in-depth
* **Architecture**: Added explicit `return null;` to `sse_process_file_for_tar()` matching documented return type
* **Architecture**: Changed `sse_add_wordpress_files_to_tar()` to catch `RuntimeException` specifically
* **Architecture**: Replaced `scandir()` with `DirectoryIterator` in bulk cleanup handler
* **Architecture**: Increased PHPStan analysis level from 5 to 6 with `includes/` scan path
* **PHP 8.2 Baseline**: Raised the minimum supported PHP version to 8.2 across plugin metadata, Composer, PHPCS, documentation, and GitHub guidance
* **CI Compatibility**: Updated the PHP compatibility matrix to test PHP 8.2, 8.3, 8.4, and 8.5
* **PHPUnit Tooling**: Updated test tooling constraints for the PHP 8.2+ baseline
* **QA Tooling**: Kept PHPCS on the stable WPCS/PHPCompatibility-compatible 3.13 line, added PHPMD as a Composer-managed dev dependency, and aligned workflow-installed coding standards with current stable package constraints
* **PHP Syntax**: Added type declarations (parameter and return types) to all functions
* **PHP Syntax**: Standardized all `array()` to short `[]` syntax
* **PHP Syntax**: Applied `??=` null coalescing assignment and `?:` Elvis operator
* **PHPStan**: Added PHPStan `array{}` shape annotations to all functions with untyped array params/returns
* **Code Quality**: Removed trailing whitespace across 5 include files
* **Code Quality**: Converted `admin.js` file header to plain block comment (avoids TSDoc linter false positives)
* **Code Quality**: Extracted `sse_cleanup_expired_export_file()` from bulk cleanup handler to reduce cyclomatic/NPath complexity
* **Code Quality**: Added `array{filepath, filename}` shape annotation to `sse_validate_export_file_path()` return type
* **Code Quality**: Removed obsolete PHPStan ignore patterns for WordPress globals now covered by stubs

= 2.0.0 =
* **Critical Fix**: Fixed bug where automatic export file cleanup via WordPress cron was completely broken due to referer validation blocking cron-triggered deletions
* **Critical Fix**: Fixed deletion success/failure notices being lost after redirect
* **Security**: Fixed 9 instances of double-escaped WP_Error messages that could display garbled text to users
* **Security**: Removed redundant double escaping in admin menu titles and submit button
* **Security**: Removed overly strict realpath equality check that blocked downloads on servers with symlinked uploads
* **Performance**: Cached file size filter result to avoid redundant lookups per file during export
* **Performance**: Prevented debug error logs from autoloading on every WordPress page request
* **Code Quality**: Removed unused `sse_get_scheduled_deletions()` dead code
* **Code Quality**: Added `shell_exec` availability check in WP-CLI PATH lookup
* **i18n**: Cleaned up stale .pot entries and added missing translatable strings

= 1.9.1 =
* **Scheduled Deletion System Enhancements**: Implemented comprehensive dual cleanup system with both individual file cleanup (5 minutes) and bulk directory cleanup (10 minutes) as safety net
* **Enhanced Debugging**: Added comprehensive debugging system with error_log() output for WordPress cron troubleshooting when standard debug logging is disabled
* **Bulk Cleanup Handler**: Added sse_bulk_cleanup_exports_handler() to scan and clean all export files older than 5 minutes from the entire export directory
* **Improved Scheduling**: Enhanced sse_schedule_export_cleanup() with detailed logging, DISABLE_WP_CRON detection, and WordPress cron array status monitoring
* **Test Framework**: Added sse_test_cron_scheduling() function to verify WordPress cron functionality before attempting real scheduling
* **Cron Diagnostics**: Implemented sse_get_scheduled_deletions() for debugging scheduled events and cron system status
* **Verification System**: Added post-scheduling verification to confirm events are properly added to WordPress cron schedule
* **WordPress VIP Compliance**: Replaced direct PHP filesystem function is_writable() with WordPress Filesystem API (WP_Filesystem) for VIP coding standards compliance
* **Filesystem API Integration**: Added proper WordPress filesystem initialization with error handling in export preparation function
* **WordPress Coding Standards**: Fixed all inline comments punctuation, corrected Yoda conditions, aligned array formatting, standardized variable assignments, and removed debug code
* **Bug Fixes**: Resolved issue where export files were not being automatically deleted due to WordPress cron scheduling failures
* **Export Directory Consistency**: Centralized export directory naming with a shared constant so every cleanup routine targets the correct path
* **Filesystem Validation**: Added explicit directory creation and writability checks that surface actionable errors when the exports folder cannot be prepared
* **Code Quality**: Enhanced overall code readability and maintainability through standardized formatting and compliance improvements, including variable alignment fixes
* **CI Database Service**: Updated WordPress compatibility workflow database container from MariaDB 10.6 to MySQL 8.4 for production-accurate testing environment

= 1.8.5 =
* **Performance**: Added an export lock using transients to prevent concurrent export processes.
* **User Experience**: Added user-friendly file size limit selection in export form (100MB, 500MB, 1GB, or no limit).
* **Code Quality**: Centralized file extension validation and eliminated code duplication with `SSE_ALLOWED_EXTENSIONS` constant.

= 1.8.4 =
* **WordPress Coding Standards**: Comprehensive PHPCS compliance fixes across all functions
* **Code Quality**: Fixed function documentation block spacing and alignment
* **Parameter Formatting**: Standardized parameter formatting with proper spacing (e.g., `function( $param )`)
* **Yoda Conditions**: Corrected Yoda conditions for all boolean comparisons (e.g., `false === $variable`)
* **Array Formatting**: Aligned array formatting with consistent spacing (e.g., `'key' => 'value'`)
* **Multi-line Functions**: Fixed multi-line function call formatting and indentation
* **Code Consistency**: Enhanced code readability and maintainability through standardized formatting
* **Documentation Workflow**: Removed changelog.txt file to streamline documentation process
* **Version Control**: Maintaining only readme.txt (WordPress.org) and CHANGELOG.md (developers) for changelog management
* **Code Standards**: Fixed tab indentation violations to use spaces as required by WordPress coding standards
* **Security Hardening**: Added WP-CLI executable verification, sanitized WP-CLI error output (path masking), conditional --allow-root usage, stricter download data validation, and graceful scheduled deletion handling

= 1.8.3 =
* **WordPress Plugin Directory Compliance**: Updated text domain from 'EngineScript-Site-Exporter' to 'enginescript-site-exporter' (lowercase) to comply with WordPress.org plugin directory requirements
* **Load Textdomain Removal**: Removed discouraged `load_plugin_textdomain()` function call as WordPress automatically handles translations for plugins hosted on WordPress.org since version 4.6
* **Plugin Header Update**: Fixed "Text Domain" header to use only lowercase letters, numbers, and hyphens as required by WordPress standards
* **Critical Security Fix**: Resolved a fatal error caused by a missing `sse_get_safe_wp_cli_path()` function. This function is essential for securely locating the WP-CLI executable, and its absence prevented the database export process from running. The new function ensures that the plugin can reliably find WP-CLI in common locations, allowing the export to proceed as intended.

= 1.7.0 =
* **SECURITY FIX**: Resolved Server-Side Request Forgery (SSRF) vulnerability in path validation
* **Filesystem Security**: Removed filesystem probing functions (is_dir, is_readable) from user input validation
* **Attack Prevention**: Eliminated potential filesystem structure information disclosure
* **Path Validation**: Maintained robust security through safe string-based path validation
* **Codacy Compliance**: Addressed security detection for file operations on user input

= 1.6.9 =
* **Security Enhancement**: Enhanced SSRF (Server-Side Request Forgery) protection in file path validation
* **Path Validation**: Improved security by validating logical path structure before filesystem operations
* **Attack Surface Reduction**: Minimized potential attack vectors by pre-validating user input before realpath() calls
* **Security Logging**: Enhanced security event logging for better monitoring of potential attacks

= 1.6.8 =
* **Fallback Removal**: Simplified codebase by removing all fallback mechanisms for better security and performance
* **Enhanced SSRF Protection**: Strengthened Server-Side Request Forgery prevention with pre-validation of all file paths
* **Security Hardening**: Comprehensive security audit ensuring OWASP and WordPress best practices compliance
* **Code Simplification**: Reduced overall complexity by 15% through fallback removal and streamlined execution paths
* **Text Domain Fixes**: Corrected remaining lowercase text domain instances for full WordPress standards compliance
* **Performance Improvement**: Single-path execution without fallback overhead for faster operations

= 1.6.7 =
* PHPMD compliance improvements with enhanced code quality
* Fixed all CamelCase variable naming violations for better code standards
* Broke down complex functions to reduce cyclomatic complexity below threshold
* Split large functions into smaller, focused functions for better maintainability
* Eliminated unnecessary else expressions throughout codebase
* Reduced NPath complexity and improved performance
* Enhanced code structure with clear separation of concerns

= 1.6.6 =
* CRITICAL: Added missing secure download and delete handlers for export files
* Fixed all text domain inconsistencies to use 'enginescript-site-exporter'
* Enhanced shell security with improved WP-CLI path validation and security checks
* Improved path traversal protection with better edge case handling
* Enhanced global variable handling for WordPress filesystem API
* Added download rate limiting (1 download per minute per user)
* Improved scheduled deletion security with proper file validation
* Sanitized error messages to prevent server information disclosure
* Removed duplicate function definitions and improved error handling
* Added comprehensive security features including user capability verification

= 1.6.5 =
* Code quality improvements and PHPMD compliance
* Refactored entire codebase to address PHP Mess Detector warnings
* Broke down large functions into smaller, single-responsibility functions
* Converted variable names to camelCase format for better code standards
* Removed unnecessary error control operators and improved error handling
* Eliminated unnecessary else expressions and duplicate code
* Fixed naming conventions for WordPress global variables
* Split complex boolean-flag functions into separate, dedicated functions

= 1.6.4 =
* Fixed text domain mismatch to use 'enginescript-site-exporter' for WordPress plugin compliance
* Updated plugin header text domain to match expected slug format for WordPress.org directory standards

= 1.6.3 =
* Version consistency update across all plugin files and documentation

= 1.6.2 =
* Plugin renamed from "EngineScript: Simple Site Exporter" to "EngineScript Site Exporter"
* Updated text domain to 'enginescript-site-exporter' for consistency
* Updated composer package name to 'enginescript/enginescript-site-exporter'
* Updated export directory naming to 'enginescript-site-exporter-exports'
* Updated all GitHub workflows and documentation to reflect new plugin name
* Enhanced plugin branding and consistency

= 1.6.1 =
* WordPress Plugin Check compliance fixes
* Fixed timezone issues by replacing date() with gmdate() for UTC consistency
* Improved debug logging with WordPress wp_debug_log() support and proper fallback
* Fixed admin page title display issue with get_admin_page_title() usage
* Enhanced documentation with proper PHPDoc comments and phpcs annotations
* Addressed all WordPress Plugin Check warnings and errors

= 1.6.0 =
* Major security and code quality improvements
* Enhanced logging system with WP_DEBUG integration and database storage for critical errors
* Improved file operations using WordPress Filesystem API instead of direct file functions
* Added execution time safety with reasonable limits and proper logging
* Implemented comprehensive path validation to prevent directory traversal attacks
* Standardized text domain across all translatable strings
* Pinned GitHub Actions to specific commit hashes for improved security
* Updated all repository references and workflow configurations
* Created WordPress-compatible readme.txt file
* Updated composer.json with correct package information and GPL-3.0-or-later license
* Fixed code structure issues and improved WordPress coding standards compliance

= 1.5.9 =
* Reduced export file auto-deletion time from 1 hour to 5 minutes for improved security
* Removed dependency on external systems for file security management
* Simplified user interface by removing environment-specific messaging
* Enhanced self-containment of the plugin's security features

= 1.5.8 =
* Refactored validation functions to eliminate code duplication
* Created shared validation function for both download and deletion operations
* Improved code maintainability while preserving security controls
* Updated license to GPL v3
* Enhanced file path validation
* Strengthened regex pattern for export file validation
* Added proper documentation for security-related functions

= 1.5.7 =
* Implemented comprehensive file path validation function to prevent directory traversal attacks
* Added referrer checks for download and delete operations
* Enhanced file pattern validation with stronger regex patterns
* Improved path display in admin interface
* Added security headers to file download operations
* Implemented strict comparison operators throughout the plugin
* Consistently applied sanitization to nonce values before verification

= 1.5.6 =
* Added more detailed logging for export operations
* Improved error handling during file operations
* Fixed potential memory issues during export of large sites
* Resolved a race condition in the scheduled deletion process

= 1.5.5 =
* Added automatic deletion of export files after 1 hour
* Implemented secure download mechanism through WordPress admin
* Added ability to manually delete export files
* Enhanced file export process with better error handling
* Improved progress feedback during export operations

= 1.5.4 =
* Added deletion request validation and confirmation
* Implemented redirect after deletion with status notification
* Fixed database export issues on some hosting environments

= 1.5.3 =
* Added manual export file deletion
* Enhanced security for file operations
* Better error handling for WP-CLI operations
* Improved user interface with clearer notifications

= 1.5.2 =
* Added WP-CLI integration for database exports
* Implemented fallback methods for database exports
* Fixed ZIP creation issues on certain hosting environments

= 1.5.1 =
* Enhanced ZIP file creation process
* Improved handling of large files
* Added exclusion for cache and temporary directories

= 1.5.0 =
* Initial Release
* Basic site export functionality
* Database and file export
* Simple admin interface

== Upgrade Notice ==

= 1.6.8 =
Major security hardening and code simplification update: Removed all fallback mechanisms, enhanced SSRF protection, comprehensive security audit following OWASP and WordPress best practices. Highly recommended security update for all users.

= 1.6.7 =
Critical compliance and security update: PHPMD/PHPStan Level 8 compliance, WordPress Plugin Check fixes, comprehensive input sanitization and output escaping. Required update for WordPress.org compatibility.

= 1.6.1 =
WordPress Plugin Check compliance update: Fixed timezone issues, improved debug logging, and addressed all plugin check warnings. Recommended update for WordPress.org submission.

= 1.6.0 =
Major security and code quality update: Enhanced logging system, improved file operations with WordPress Filesystem API, execution time safety improvements, comprehensive path validation, standardized text domains, and GitHub Actions security updates. Recommended upgrade for all users.

= 1.5.9 =
This update improves security by reducing export file auto-deletion time from 1 hour to 5 minutes and enhances overall plugin security with simplified, self-contained security features.

= 1.5.8 =
This update includes improved code quality, better validation functions, and enhanced security with file path validation and stronger regex patterns. Includes update to GPL v3 license.

= 1.5.7 =
Important security update: Includes comprehensive file path validation, referrer checks for download operations, enhanced validation patterns, and improved security headers.
