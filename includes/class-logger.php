<?php
/**
 * PM DB Cleaner — Logger
 * Gestion des logs de nettoyage (écriture, rotation, lecture).
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Logger {

	/**
	 * Retourne le chemin du fichier de log courant.
	 */
	private static function get_log_file() {
		$upload_dir = wp_upload_dir();
		return is_multisite()
			? $upload_dir['basedir'] . '/pm-db-cleaner-site-' . get_current_blog_id() . '.txt'
			: $upload_dir['basedir'] . '/pm-db-cleaner.txt';
	}

	private static function get_log_glob() {
		$upload_dir = wp_upload_dir();
		return is_multisite()
			? $upload_dir['basedir'] . '/pm-db-cleaner-site-' . get_current_blog_id() . '-*.txt'
			: $upload_dir['basedir'] . '/pm-db-cleaner-*.txt';
	}

	/**
	 * Écrit une entrée de log.
	 */
	public static function log( $type, $count, $remaining = 0, $mode = 'AUTO' ) {
		$log_file = self::get_log_file();

		// Rotation automatique > 5 MB
		if ( file_exists( $log_file ) && filesize( $log_file ) > 5 * 1024 * 1024 ) {
			rename( $log_file, str_replace( '.txt', '-' . date( 'Y-m-d-His' ) . '.txt', $log_file ) );
			foreach ( (array) glob( self::get_log_glob() ) as $old ) {
				if ( filemtime( $old ) < time() - 90 * DAY_IN_SECONDS ) { unlink( $old ); }
			}
		}

		$ts      = current_time( 'Y-m-d H:i:s' );
		$label   = self::get_label( $type );
		$message = $remaining > 0
			? sprintf( '[%s] %s | %s | %d supprimés | %d restants', $ts, $mode, $label, $count, $remaining )
			: sprintf( '[%s] %s | %s | %d supprimés', $ts, $mode, $label, $count );

		if ( is_multisite() ) {
			$message .= ' | site:' . get_blog_details( get_current_blog_id() )->blogname;
		}

		file_put_contents( $log_file, $message . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Écrit une ligne de détail (ex: clés supprimées).
	 */
	public static function log_detail( $details ) {
		$log_file = self::get_log_file();
		$line     = '[' . current_time( 'Y-m-d H:i:s' ) . '] DETAIL | ' . implode( ' | ', $details ) . "\n";
		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Récupère les dernières lignes du log.
	 */
	public static function get_recent( $lines = 20 ) {
		$log_file = self::get_log_file();
		if ( ! file_exists( $log_file ) ) { return array(); }
		$content = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return $content ? array_slice( array_reverse( $content ), 0, $lines ) : array();
	}

	/**
	 * Retourne l'URL du fichier de log.
	 */
	public static function get_log_url() {
		$upload_dir = wp_upload_dir();
		$filename   = is_multisite()
			? 'pm-db-cleaner-site-' . get_current_blog_id() . '.txt'
			: 'pm-db-cleaner.txt';
		return $upload_dir['baseurl'] . '/' . $filename;
	}

	/**
	 * Labels lisibles par type de nettoyage.
	 */
	public static function get_label( $type ) {
		$labels = array(
			'orphan_postmeta'           => 'Posts - Métadonnées orphelines',
			'duplicated_postmeta'       => 'Posts - Métadonnées dupliquées',
			'oembed_postmeta'           => 'Posts - Caches oEmbed',
			'orphan_commentmeta'        => 'Commentaires - Métadonnées orphelines',
			'duplicated_commentmeta'    => 'Commentaires - Métadonnées dupliquées',
			'trashed_comments'          => 'Commentaires - En corbeille',
			'orphan_termmeta'           => 'Termes - Métadonnées orphelines',
			'duplicated_termmeta'       => 'Termes - Métadonnées dupliquées',
			'orphan_term_relationships' => 'Termes - Relations orphelines',
			'orphan_usermeta'           => 'Utilisateurs - Métadonnées orphelines',
			'duplicated_usermeta'       => 'Utilisateurs - Métadonnées dupliquées',
			'action_scheduler_daily'    => 'Action Scheduler',
			'expired_transients'        => 'Transients expirés',
			'orphan_transient_timeouts' => 'Transients - Timeouts orphelins',
			'orphaned_variations'       => 'WooCommerce - Variations orphelines',
			'database_weekly'           => 'Base de données (hebdo)',
			'monthly'                   => 'Commentaires + Transients (mensuel)',
			'custom_fields'             => 'Métadonnées - Suppression manuelle',
			'wp_options'                => 'WP Options - Suppression manuelle',
			'wp_options_autoload'       => 'WP Options - Autoload désactivé',
			'db_overhead'               => 'Overhead BDD - Tables optimisées',
			'dirsize_cache'             => 'Système - Dirsize Cache supprimé',
			'cron_orphans'              => 'Cron - Tâches orphelines supprimées',
		);
		return $labels[ $type ] ?? $type;
	}

}
