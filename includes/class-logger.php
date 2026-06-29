<?php
/**
 * PM DB Cleaner — Logger
 * Handles cleanup log writing, rotation, reading and URL retrieval.
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Logger {

	/**
	 * Returns the path of the current log file.
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
	 * Writes a log entry.
	 */
	public static function log( $type, $count, $remaining = 0, $mode = 'AUTO' ) {
		$log_file = self::get_log_file();

		// Automatic rotation above 5 MB
		if ( file_exists( $log_file ) && filesize( $log_file ) > 5 * 1024 * 1024 ) {
			rename( $log_file, str_replace( '.txt', '-' . date( 'Y-m-d-His' ) . '.txt', $log_file ) );
			foreach ( (array) glob( self::get_log_glob() ) as $old ) {
				if ( filemtime( $old ) < time() - 90 * DAY_IN_SECONDS ) { unlink( $old ); }
			}
		}

		$ts      = current_time( 'Y-m-d H:i:s' );
		$label   = self::get_label( $type );
		$message = $remaining > 0
			? sprintf( '[%s] %s | %s | %d deleted | %d remaining', $ts, $mode, $label, $count, $remaining )
			: sprintf( '[%s] %s | %s | %d deleted', $ts, $mode, $label, $count );

		if ( is_multisite() ) {
			$message .= ' | site:' . get_blog_details( get_current_blog_id() )->blogname;
		}

		file_put_contents( $log_file, $message . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Writes a detail line (e.g. deleted keys).
	 */
	public static function log_detail( $details ) {
		$log_file = self::get_log_file();
		$line     = '[' . current_time( 'Y-m-d H:i:s' ) . '] DETAIL | ' . implode( ' | ', $details ) . "\n";
		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Returns the most recent log lines.
	 */
	public static function get_recent( $lines = 20 ) {
		$log_file = self::get_log_file();
		if ( ! file_exists( $log_file ) ) { return array(); }
		$content = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return $content ? array_slice( array_reverse( $content ), 0, $lines ) : array();
	}

	/**
	 * Returns the URL of the log file.
	 */
	public static function get_log_url() {
		$upload_dir = wp_upload_dir();
		$filename   = is_multisite()
			? 'pm-db-cleaner-site-' . get_current_blog_id() . '.txt'
			: 'pm-db-cleaner.txt';
		return $upload_dir['baseurl'] . '/' . $filename;
	}

	/**
	 * Returns a human-readable label for a given cleanup type.
	 */
	public static function get_label( $type ) {
		$labels = array(
			'orphan_postmeta'           => __( 'Posts - Orphaned metadata', 'pm-db-cleaner' ),
			'duplicated_postmeta'       => __( 'Posts - Duplicated metadata', 'pm-db-cleaner' ),
			'oembed_postmeta'           => __( 'Posts - oEmbed caches', 'pm-db-cleaner' ),
			'orphan_commentmeta'        => __( 'Comments - Orphaned metadata', 'pm-db-cleaner' ),
			'duplicated_commentmeta'    => __( 'Comments - Duplicated metadata', 'pm-db-cleaner' ),
			'trashed_comments'          => __( 'Comments - Trashed', 'pm-db-cleaner' ),
			'orphan_termmeta'           => __( 'Terms - Orphaned metadata', 'pm-db-cleaner' ),
			'duplicated_termmeta'       => __( 'Terms - Duplicated metadata', 'pm-db-cleaner' ),
			'orphan_term_relationships' => __( 'Terms - Orphaned relationships', 'pm-db-cleaner' ),
			'orphan_usermeta'           => __( 'Users - Orphaned metadata', 'pm-db-cleaner' ),
			'duplicated_usermeta'       => __( 'Users - Duplicated metadata', 'pm-db-cleaner' ),
			'action_scheduler_daily'    => __( 'Action Scheduler', 'pm-db-cleaner' ),
			'expired_transients'        => __( 'Expired transients', 'pm-db-cleaner' ),
			'orphan_transient_timeouts' => __( 'Transients - Orphaned timeouts', 'pm-db-cleaner' ),
			'orphaned_variations'       => __( 'WooCommerce - Orphaned variations', 'pm-db-cleaner' ),
			'database_weekly'           => __( 'Database (weekly)', 'pm-db-cleaner' ),
			'monthly'                   => __( 'Comments + Transients (monthly)', 'pm-db-cleaner' ),
			'custom_fields'             => __( 'Metadata - Manual deletion', 'pm-db-cleaner' ),
			'wp_options'                => __( 'WP Options - Manual deletion', 'pm-db-cleaner' ),
			'wp_options_autoload'       => __( 'WP Options - Autoload disabled', 'pm-db-cleaner' ),
			'db_overhead'               => __( 'DB Overhead - Tables optimized', 'pm-db-cleaner' ),
			'dirsize_cache'             => __( 'System - Dirsize cache cleared', 'pm-db-cleaner' ),
			'cron_orphans'              => __( 'Cron - Orphaned tasks deleted', 'pm-db-cleaner' ),
		);
		return $labels[ $type ] ?? $type;
	}

}
