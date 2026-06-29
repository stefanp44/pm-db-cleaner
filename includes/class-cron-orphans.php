<?php
/**
 * PM DB Cleaner — Orphan cron tasks
 * Detection and deletion of scheduled hooks with no registered callback,
 * plus manual uninstall (file deleted via SFTP/SSH without WP deactivation).
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Cron_Orphans {

	/**
	 * WordPress Core hooks — never considered orphaned.
	 */
	private static $core_hooks = array(
		'wp_version_check', 'wp_update_plugins', 'wp_update_themes',
		'wp_scheduled_delete', 'wp_scheduled_auto_draft_delete',
		'delete_expired_transients', 'wp_privacy_delete_old_export_files',
		'recovery_mode_clean_expired_keys', 'wp_site_health_scheduled_check',
		'do_pings', 'wp_update_user_counts', 'wp_https_detection',
		'jetpack_clean_nonces', 'wp_delete_temp_updater_backups',
	);

	/**
	 * Detects scheduled hooks with no registered callback.
	 *
	 * MUST be called from admin_page() only (not from admin-ajax) so that
	 * admin_menu has already fired — avoids false positives from plugins
	 * that register their callback only inside their admin_menu callback
	 * (observed with SureMail).
	 */
	public static function get_orphans() {
		$cron = _get_cron_array();
		if ( empty( $cron ) ) { return array(); }

		$orphans = array();
		foreach ( $cron as $ts => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( in_array( $hook, self::$core_hooks, true ) || has_action( $hook ) ) { continue; }
				foreach ( $events as $key => $event ) {
					$schedules  = wp_get_schedules();
					$orphans[] = array(
						'hook'       => $hook,
						'key'        => $key,
						'timestamp'  => $ts,
						'next_run'   => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'd/m/Y \a\t H:i' ),
						'recurrence' => $event['schedule']
							? ( $schedules[ $event['schedule'] ]['display'] ?? $event['schedule'] )
							: __( 'One-time', 'pm-db-cleaner' ),
						'args'       => $event['args'],
					);
				}
			}
		}
		return $orphans;
	}

	// ─── AJAX: delete orphaned tasks ──────────────────────────────────────────

	public static function ajax_delete_cron_orphans() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		if ( empty( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) {
			wp_send_json_error( array( 'message' => __( 'Confirmation required.', 'pm-db-cleaner' ) ) );
		}
		$events = isset( $_POST['events'] ) ? (array) $_POST['events'] : array();
		if ( empty( $events ) ) { wp_send_json_error( array( 'message' => __( 'No task selected.', 'pm-db-cleaner' ) ) ); }
		if ( count( $events ) > 50 ) { wp_send_json_error( array( 'message' => __( 'Maximum 50 tasks per operation.', 'pm-db-cleaner' ) ) ); }

		$deleted = 0;
		foreach ( $events as $event ) {
			$hook = sanitize_text_field( $event['hook'] ?? '' );
			$ts   = (int) ( $event['timestamp'] ?? 0 );
			if ( ! $hook || ! $ts ) { continue; }
			$cron = _get_cron_array();
			$args = $cron[ $ts ][ $hook ] ?? null;
			if ( $args === null ) { continue; }
			foreach ( $args as $ed ) { wp_unschedule_event( $ts, $hook, $ed['args'] ); $deleted++; }
		}
		if ( $deleted > 0 ) { PM_DB_Cleaner_Logger::log( 'cron_orphans', $deleted, 0, 'MANUAL' ); }
		wp_send_json_success( array( 'message' => sprintf( __( '%d orphaned cron task(s) deleted.', 'pm-db-cleaner' ), $deleted ) ) );
	}

	// ─── AJAX: manual uninstall (file deleted via SFTP/SSH) ──────────────────

	public static function ajax_uninstall_cron() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		if ( empty( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) {
			wp_send_json_error( array( 'message' => __( 'Confirmation required.', 'pm-db-cleaner' ) ) );
		}
		foreach ( array( 'pm_cleanup_action_scheduler_daily', 'pm_cleanup_database_weekly', 'pm_cleanup_monthly' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_send_json_success( array(
			'message' => __( 'PM DB Cleaner cron tasks removed. You can now safely delete the plugin file.', 'pm-db-cleaner' ),
		) );
	}

}
