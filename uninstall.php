<?php
/**
 * PM DB Cleaner — Désinstallation
 *
 * Déclenché par WordPress lors de la suppression du plugin via
 * Extensions > Supprimer (après désactivation). Supprime les 3 tâches
 * cron récurrentes du plugin si elles existent encore.
 *
 * Note : la désactivation (register_deactivation_hook) supprime déjà
 * ces tâches. Ce fichier sert de filet de sécurité pour le cas où le
 * plugin aurait été désactivé via wp-config.php ou autre mécanisme
 * contournant le hook de désactivation.
 *
 * @package PM_DB_Cleaner
 * @license GPL-2.0-or-later
 */

// Sécurité : ce fichier ne doit être appelé que par WordPress lui-même
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( '-1' );
}

// Supprime les 3 tâches cron récurrentes
$hooks = array(
	'pm_cleanup_action_scheduler_daily',
	'pm_cleanup_database_weekly',
	'pm_cleanup_monthly',
);

foreach ( $hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
