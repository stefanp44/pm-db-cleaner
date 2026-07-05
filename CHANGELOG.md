# Changelog — PM DB Cleaner

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [2.0] — 2026-06-29

### Changed

- **Internationalization**: plugin is now natively in English with full `__()` / `_e()` / `esc_html__()` / `esc_attr_e()` coverage throughout all PHP files. French translation (`fr_FR`) provided in `languages/` folder (`pm-db-cleaner-fr_FR.po` / `.mo`). POT template included for future translations.
- `Text Domain` and `Domain Path` headers added to the plugin file.
- `load_plugin_textdomain()` registered on `plugins_loaded`.
- Log mode changed from `MANUEL` to `MANUAL` for consistency with the English codebase.
- JS strings moved to `wp_localize_script` where applicable; remaining hardcoded strings translated to English.

## [1.4.1] — 2026-06-25

### Changed

- Full architecture refactor: plugin split from a single monolithic PHP file into a multi-file structure under `includes/`.
  - `class-logger.php` — log writing, rotation, reading and URL retrieval
  - `class-cleanup-auto.php` — scheduled cron cleanups (daily/weekly/monthly) and AJAX wrappers
  - `class-cleanup-manual.php` — manual cleanups (postmeta, Dirsize Cache, DB Overhead)
  - `class-options.php` — WP Options (listing, deletion, autoload analysis, autoload disabling)
  - `class-cron-orphans.php` — orphan cron task detection and deletion, manual uninstall
  - `class-admin-page.php` — admin page HTML rendering, statistics, asset enqueueing
- Main file `pm-db-cleaner.php` now acts as bootstrap only: constants, includes, activation/deactivation hooks and WordPress action registration.
- Added constants `PM_DB_CLEANER_VERSION` and `PM_DB_CLEANER_FILE` used by includes for path and version references.

### Features

No functional changes — this release is a pure internal restructuring.

## [1.3] — 2026-06-25

### Added

- **WP Options block**: full access to the `wp_options` table — list all option names, real-time server-side filter (500 results max with overflow notice), checkbox selection and permanent deletion with explicit backup confirmation. Distinct from the Autoload block.
- **Dirsize Cache cleanup**: dedicated row in the Manual cleanups panel. Deletes `wp_options` entries matching `%dirsize_cache%`. Safe to delete — rebuilt automatically on the next media library visit.
- **Orphaned transient timeouts**: new cleanup (also included in the monthly cron) — deletes `_transient_timeout_xxx` entries whose matching `_transient_xxx` no longer exists. Safest possible approach: only removes a timeout pointing to nothing; permanent transients (no timeout by design) are never touched.

### Changed

- CSS and JS extracted from the PHP file into `assets/admin.css` and `assets/admin.js`, loaded via `wp_enqueue_style` / `wp_enqueue_script`.
- Layout restructured: main grid split into 2/3 (cleanup table) + 1/3 (Autoload + Manual cleanups). Bottom grids: Metadata/WP Options and Cron orphans/SFTP each in a 1/2 + 1/2 layout.
- "Custom Fields" renamed to "Metadata (postmeta)" to accurately reflect the underlying table.
- Manual cleanup items (Dirsize Cache, DB Overhead, Post revisions) grouped into a single "Manual cleanups" panel in the 1/3 column, below Autoload.
- System section now contains only automatic cleanups (Action Scheduler, Expired transients, Orphaned transient timeouts).
- `max-width: 100%` on `.pm-db-cleaner-wrap` for full admin width usage.
- "Delete plugin via SFTP/SSH?" title styled in red (`.pm-pane-title--danger`) to distinguish it from cleanup operations.
- All inline styles removed; replaced by CSS classes in `admin.css`.
- `.pm-item-desc` font size increased from 12px to 14px.

### Fixed

- `cleanup_orphan_term_relationships()` and its `get_stats()` counterpart now use `$wpdb->prepare()` with `%s` placeholders for excluded taxonomies — previously injected directly via `implode()`.
- `ajax_get_option_keys()` now limited to 500 results with server-side filtering; previously returned the entire `wp_options` table in a single JSON response.
- Dirsize cache query corrected to use `LIKE '%dirsize_cache%'` (wildcards on both sides); previously used an exact match that always returned 0.

## [1.1] — 2026-06-22

### Changed

- **Converted from mu-plugin to standard plugin**: can now be managed via Plugins > Activate/Deactivate and receive automatic updates.
- Added `register_activation_hook`: schedules the 3 cron tasks on activation (replaces scheduling on `init` which fired on every request).
- Added `register_deactivation_hook`: removes the 3 cron tasks on deactivation via the WordPress interface.
- Added `uninstall.php`: safety net that removes cron tasks on deletion via Plugins > Delete.
- Removed `schedule_cleanups()` called on `init`: scheduling is now handled exclusively by the activation hook.
- Initialization moved from direct `PM_DB_Cleaner::get_instance()` call to `add_action( 'plugins_loaded', ... )`.
- Plugin header: added `Update URI` for Plugin Update Checker (GitHub) compatibility.
- "Before removing this plugin" section renamed to "Deleting the plugin via SFTP/SSH?" with updated copy clarifying that normal WordPress deactivation is sufficient, and the manual button only applies to direct SFTP/SSH file deletion.
- Plugin Update Checker configuration moved to a separate `update-checker-config.php` file (excluded from git via `.gitignore` while the repository was private).

## [2026-06-13] (3)

### Added

- "Before removing this plugin" section: manual uninstall button that removes the 3 PM DB Cleaner recurring cron tasks via `wp_clear_scheduled_hook()`. Required as the plugin was a mu-plugin with no WordPress uninstall hook.

## [2026-06-13] (2)

### Fixed

- Cron task times (scheduled cleanups and orphan tasks) corrected: replaced `date_i18n()` with `get_date_from_gmt()` for UTC → site local time conversion, correctly handling DST. A 2-hour offset was visible on BackWPup tasks compared to WP Crontrol.

## [2026-06-13]

### Added

- New "Orphan cron tasks" section: detects scheduled hooks with no registered callback (`has_action()` empty), typically leftovers from an uninstalled plugin. Detection runs at admin page load (not via `admin-ajax.php`) to match WP Crontrol's execution context and avoid false positives from plugins that register their callback only via `admin_menu` (observed with SureMail). Manual deletion only, with mandatory explicit confirmation, re-verified server-side. Limit of 50 tasks per operation.
- Core hook `wp_delete_temp_updater_backups` added to the list of hooks never considered orphaned.

### Changed

- "Scheduled automatic cleanup" block: each line now explicitly describes what it covers (Posts / Comments / Terms & Taxonomies / Users / WooCommerce for weekly, Comments + System for monthly), with a note on what is never automated (Custom Fields, Autoload, DB Overhead).
- Custom Fields and Autoload (wp_options) moved out of the main table into a dedicated two-column grid at the bottom of the page.

## [2026-06-09]

Reference version v6, deployment in progress on pilot sites.

## [2.1] — 2026-07-05

### Changed

- The four manual operation panels (Metadata/postmeta, WP Options, Orphan cron tasks, Deleting via SFTP/SSH) are now grouped inside a **Danger Zone** block, visually distinct from the safe cleanup sections above. The block features a red border, a warning header and an explicit description of the risks involved.
- The individual warning notice previously displayed inside the WP Options panel removed — replaced by the global Danger Zone header.
- Two new i18n strings added (`Danger Zone — manual operations` and its description). French translation updated accordingly.

## [2.2] — 2026-07-05

### Changed

- Danger Zone block converted to a collapsible `<details>` toggle, closed by default — same pattern as the "Scheduled automatic cleanup" block. Reduces visual noise on first load.
- Danger Zone background changed to a light red (`#fef0f0`) with a matching border (`#f5c6c6`), consistent with the green used for the safe cleanup section.
- `⚠️` emoji in the Danger Zone header replaced by `dashicons-warning` in red, consistent with the dashicon usage elsewhere in the interface.
- `⚠️` emoji removed from the WP Options overflow notice ("N shown out of X"). Remaining French text in that message also corrected to English.
