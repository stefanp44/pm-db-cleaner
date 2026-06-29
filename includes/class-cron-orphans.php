<?php
/**
 * PM DB Cleaner — Tâches Cron orphelines
 * Détection des hooks planifiés sans callback + désinstallation manuelle.
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Cron_Orphans {

	/**
	 * Hooks WordPress Core — jamais considérés comme orphelins.
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
	 * Détecte les hooks planifiés sans callback enregistré.
	 * DOIT être appelé depuis admin_page() uniquement (pas depuis admin-ajax)
	 * pour que admin_menu ait bien été déclenché — évite les faux positifs.
	 */
	public static function get_orphans() {
		$cron = _get_cron_array();
		if ( empty( $cron ) ) { return array(); }

		$orphans = array();
		foreach ( $cron as $ts => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( in_array( $hook, self::$core_hooks, true ) || has_action( $hook ) ) { continue; }
				foreach ( $events as $key => $event ) {
					$orphans[] = array(
						'hook'       => $hook,
						'key'        => $key,
						'timestamp'  => $ts,
						'next_run'   => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'd/m/Y à H:i' ),
						'recurrence' => $event['schedule']
							? ( wp_get_schedules()[ $event['schedule'] ]['display'] ?? $event['schedule'] )
							: 'Ponctuel',
						'args'       => $event['args'],
					);
				}
			}
		}
		return $orphans;
	}

	// ─── AJAX : suppression des orphelins ─────────────────────────────────────

	public static function ajax_delete_cron_orphans() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		if ( empty( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) {
			wp_send_json_error( array( 'message' => 'Confirmation requise.' ) );
		}
		$events = isset( $_POST['events'] ) ? (array) $_POST['events'] : array();
		if ( empty( $events ) ) { wp_send_json_error( array( 'message' => 'Aucune tâche sélectionnée.' ) ); }
		if ( count( $events ) > 50 ) { wp_send_json_error( array( 'message' => 'Maximum 50 tâches par opération.' ) ); }

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
		if ( $deleted > 0 ) { PM_DB_Cleaner_Logger::log( 'cron_orphans', $deleted, 0, 'MANUEL' ); }
		wp_send_json_success( array( 'message' => sprintf( '%d tâche(s) cron orpheline(s) supprimée(s).', $deleted ) ) );
	}

	// ─── AJAX : désinstallation manuelle (suppression fichier via SFTP/SSH) ───

	public static function ajax_uninstall_cron() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		if ( empty( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) {
			wp_send_json_error( array( 'message' => 'Confirmation requise.' ) );
		}
		foreach ( array( 'pm_cleanup_action_scheduler_daily', 'pm_cleanup_database_weekly', 'pm_cleanup_monthly' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_send_json_success( array(
			'message' => 'Tâches cron de PM DB Cleaner supprimées. Vous pouvez maintenant supprimer le fichier en toute sécurité.',
		) );
	}

}
