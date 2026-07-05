<?php
/**
 * Plugin Name: PM DB Cleaner
 * Description: Automatically cleans the WordPress database: orphaned and duplicated metadata, oEmbed caches, trashed comments, expired transients, Action Scheduler, orphaned WooCommerce variations, unused custom fields, wp_options entries and autoload, and orphaned cron tasks. Scheduled daily/weekly/monthly cleanups with traceable logs and timeout prevention.
 * Version: 2.1
 * Author: Perspectives Marketing
 * Author URI: https://perspectives.marketing
 * Update URI: https://github.com/stefanp44/pm-db-cleaner
 * Text Domain: pm-db-cleaner
 * Domain Path: /languages
 *
 * @package PM_DB_Cleaner
 * @license GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'PM_DB_CLEANER_VERSION', '2.1' );
define( 'PM_DB_CLEANER_FILE', __FILE__ );

// ─── Plugin Update Checker ────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'update-checker-config.php';

// ─── Includes ─────────────────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/class-logger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cleanup-auto.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cleanup-manual.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-options.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cron-orphans.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-page.php';

// ─── Activation / deactivation hooks ─────────────────────────────────────────

function pm_db_cleaner_activate() {
	if ( ! wp_next_scheduled( 'pm_cleanup_action_scheduler_daily' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'pm_cleanup_action_scheduler_daily' );
	}
	if ( ! wp_next_scheduled( 'pm_cleanup_database_weekly' ) ) {
		wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'pm_cleanup_database_weekly' );
	}
	if ( ! wp_next_scheduled( 'pm_cleanup_monthly' ) ) {
		wp_schedule_event( time() + ( 30 * DAY_IN_SECONDS ), 'monthly', 'pm_cleanup_monthly' );
	}
}
register_activation_hook( __FILE__, 'pm_db_cleaner_activate' );

function pm_db_cleaner_deactivate() {
	wp_clear_scheduled_hook( 'pm_cleanup_action_scheduler_daily' );
	wp_clear_scheduled_hook( 'pm_cleanup_database_weekly' );
	wp_clear_scheduled_hook( 'pm_cleanup_monthly' );
}
register_deactivation_hook( __FILE__, 'pm_db_cleaner_deactivate' );

// ─── Init ─────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', function() {

	// Load translations
	load_plugin_textdomain( 'pm-db-cleaner', false, dirname( plugin_basename( PM_DB_CLEANER_FILE ) ) . '/languages' );

	// Monthly cron schedule
	add_filter( 'cron_schedules', function( $schedules ) {
		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month', 'pm-db-cleaner' ),
		);
		return $schedules;
	} );

	// Scheduled cron callbacks
	add_action( 'pm_cleanup_action_scheduler_daily', array( 'PM_DB_Cleaner_Auto', 'cleanup_action_scheduler' ) );
	add_action( 'pm_cleanup_database_weekly',        array( 'PM_DB_Cleaner_Auto', 'cleanup_database' ) );
	add_action( 'pm_cleanup_monthly',                array( 'PM_DB_Cleaner_Auto', 'cleanup_monthly' ) );

	// Admin interface
	add_action( 'admin_menu',            array( 'PM_DB_Cleaner_Admin', 'admin_menu' ) );
	add_action( 'admin_enqueue_scripts', array( 'PM_DB_Cleaner_Admin', 'admin_scripts' ) );

	// AJAX — automatic cleanups (also triggerable manually)
	add_action( 'wp_ajax_pm_cleanup_action_scheduler',          array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_action_scheduler' ) );
	add_action( 'wp_ajax_pm_cleanup_orphan_postmeta',           array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphan_postmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_duplicated_postmeta',       array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_duplicated_postmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_oembed_postmeta',           array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_oembed_postmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_orphan_commentmeta',        array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphan_commentmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_duplicated_commentmeta',    array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_duplicated_commentmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_orphan_termmeta',           array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphan_termmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_duplicated_termmeta',       array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_duplicated_termmeta' ) );
	add_action( 'wp_ajax_pm_cleanup_orphan_term_relationships', array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphan_term_relationships' ) );
	add_action( 'wp_ajax_pm_cleanup_orphan_usermeta',           array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphan_usermeta' ) );
	add_action( 'wp_ajax_pm_cleanup_duplicated_usermeta',       array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_duplicated_usermeta' ) );
	add_action( 'wp_ajax_pm_cleanup_orphaned_variations',       array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphaned_variations' ) );
	add_action( 'wp_ajax_pm_cleanup_trashed_comments',          array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_trashed_comments' ) );
	add_action( 'wp_ajax_pm_cleanup_expired_transients',        array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_expired_transients' ) );
	add_action( 'wp_ajax_pm_cleanup_orphan_transient_timeouts', array( 'PM_DB_Cleaner_Auto', 'ajax_cleanup_orphan_transient_timeouts' ) );
	add_action( 'wp_ajax_pm_cleanup_all',                       'pm_db_cleaner_ajax_cleanup_all' );

	// AJAX — manual cleanups
	add_action( 'wp_ajax_pm_get_meta_keys',         array( 'PM_DB_Cleaner_Manual', 'ajax_get_meta_keys' ) );
	add_action( 'wp_ajax_pm_delete_custom_fields',  array( 'PM_DB_Cleaner_Manual', 'ajax_delete_custom_fields' ) );
	add_action( 'wp_ajax_pm_cleanup_dirsize_cache', array( 'PM_DB_Cleaner_Manual', 'ajax_cleanup_dirsize_cache' ) );
	add_action( 'wp_ajax_pm_cleanup_db_overhead',   array( 'PM_DB_Cleaner_Manual', 'ajax_cleanup_db_overhead' ) );

	// AJAX — WP Options & Autoload
	add_action( 'wp_ajax_pm_get_option_keys',  array( 'PM_DB_Cleaner_Options', 'ajax_get_option_keys' ) );
	add_action( 'wp_ajax_pm_delete_options',   array( 'PM_DB_Cleaner_Options', 'ajax_delete_options' ) );
	add_action( 'wp_ajax_pm_analyze_autoload', array( 'PM_DB_Cleaner_Options', 'ajax_analyze_autoload' ) );
	add_action( 'wp_ajax_pm_disable_autoload', array( 'PM_DB_Cleaner_Options', 'ajax_disable_autoload' ) );

	// AJAX — Orphan cron tasks & manual uninstall
	add_action( 'wp_ajax_pm_delete_cron_orphans', array( 'PM_DB_Cleaner_Cron_Orphans', 'ajax_delete_cron_orphans' ) );
	add_action( 'wp_ajax_pm_uninstall_cron',      array( 'PM_DB_Cleaner_Cron_Orphans', 'ajax_uninstall_cron' ) );

} );

// ─── AJAX: Clean all ──────────────────────────────────────────────────────────

function pm_db_cleaner_ajax_cleanup_all() {
	check_ajax_referer( 'pm_db_cleanup', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
	$total = PM_DB_Cleaner_Auto::cleanup_action_scheduler()
		+ PM_DB_Cleaner_Auto::cleanup_database()
		+ PM_DB_Cleaner_Auto::cleanup_monthly();
	wp_send_json_success( array( 'message' => sprintf( __( 'Total: %d items cleaned', 'pm-db-cleaner' ), $total ) ) );
}
