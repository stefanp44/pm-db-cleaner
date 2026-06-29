<?php
/**
 * PM DB Cleaner — Manual cleanups
 * Metadata (postmeta/termmeta/usermeta), Dirsize Cache, DB Overhead.
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Manual {

	private static function ajax_check() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
	}

	// ─── Metadata: retrieve keys ──────────────────────────────────────────────

	public static function ajax_get_meta_keys() {
		self::ajax_check();
		global $wpdb;
		$type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'post';
		$keys = array();
		if ( in_array( $type, array( 'post', 'all' ), true ) ) {
			$keys = array_merge( $keys, $wpdb->get_col( "SELECT DISTINCT meta_key FROM $wpdb->postmeta ORDER BY meta_key" ) );
		}
		if ( in_array( $type, array( 'term', 'all' ), true ) ) {
			$keys = array_merge( $keys, $wpdb->get_col( "SELECT DISTINCT meta_key FROM $wpdb->termmeta ORDER BY meta_key" ) );
		}
		if ( in_array( $type, array( 'user', 'all' ), true ) ) {
			$keys = array_merge( $keys, $wpdb->get_col( "SELECT DISTINCT meta_key FROM $wpdb->usermeta ORDER BY meta_key" ) );
		}
		$keys = array_unique( $keys );
		sort( $keys );
		wp_send_json_success( array( 'keys' => $keys ) );
	}

	// ─── Metadata: delete selected keys ──────────────────────────────────────

	public static function ajax_delete_custom_fields() {
		self::ajax_check();
		global $wpdb;
		$type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'post';
		$keys = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) $_POST['keys'] ) : array();
		if ( empty( $keys ) ) { wp_send_json_error( array( 'message' => __( 'No key selected.', 'pm-db-cleaner' ) ) ); }
		if ( count( $keys ) > 50 ) { wp_send_json_error( array( 'message' => __( 'Maximum 50 keys per operation.', 'pm-db-cleaner' ) ) ); }

		$tables = array();
		if ( in_array( $type, array( 'post', 'all' ), true ) ) { $tables[] = array( 'table' => $wpdb->postmeta, 'col' => 'meta_key' ); }
		if ( in_array( $type, array( 'term', 'all' ), true ) ) { $tables[] = array( 'table' => $wpdb->termmeta, 'col' => 'meta_key' ); }
		if ( in_array( $type, array( 'user', 'all' ), true ) ) { $tables[] = array( 'table' => $wpdb->usermeta, 'col' => 'meta_key' ); }

		$total = 0; $details = array();
		foreach ( $keys as $key ) {
			$kt = 0;
			foreach ( $tables as $t ) {
				$d = $wpdb->delete( $t['table'], array( $t['col'] => $key ), array( '%s' ) );
				if ( $d ) { $kt += $d; }
			}
			if ( $kt > 0 ) { $details[] = $key . ': ' . $kt; $total += $kt; }
		}
		if ( $total > 0 ) {
			PM_DB_Cleaner_Logger::log( 'custom_fields', $total, 0, 'MANUAL' );
			PM_DB_Cleaner_Logger::log_detail( $details );
		}
		wp_send_json_success( array(
			'message' => $total > 0
				? sprintf( __( '%d entries deleted (%d key(s))', 'pm-db-cleaner' ), $total, count( $details ) )
				: __( 'No entries found for the selected keys.', 'pm-db-cleaner' ),
		) );
	}

	// ─── Dirsize Cache ────────────────────────────────────────────────────────

	public static function cleanup_dirsize_cache() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s LIMIT 500",
			'%' . $wpdb->esc_like( 'dirsize_cache' ) . '%'
		) );
	}

	public static function ajax_cleanup_dirsize_cache() {
		self::ajax_check();
		$c = self::cleanup_dirsize_cache();
		PM_DB_Cleaner_Logger::log( 'dirsize_cache', $c, 0, 'MANUAL' );
		wp_send_json_success( array( 'message' => sprintf( __( '%d dirsize_cache entry(ies) deleted', 'pm-db-cleaner' ), $c ) ) );
	}

	// ─── DB Overhead ──────────────────────────────────────────────────────────

	public static function ajax_cleanup_db_overhead() {
		self::ajax_check();
		global $wpdb;
		$tables = $wpdb->get_results( $wpdb->prepare(
			"SELECT table_name, Data_free FROM information_schema.TABLES
			WHERE table_schema = DATABASE() AND table_name LIKE %s AND Data_free > 0
			ORDER BY Data_free DESC",
			$wpdb->prefix . '%'
		) );
		if ( empty( $tables ) ) {
			wp_send_json_success( array( 'message' => __( 'No overhead detected, your tables are already optimized.', 'pm-db-cleaner' ) ) );
		}
		$optimized = 0; $freed = 0;
		foreach ( $tables as $t ) {
			$freed += (int) $t->Data_free;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $t->table_name ) . '`' );
			$optimized++;
		}
		PM_DB_Cleaner_Logger::log( 'db_overhead', $optimized, 0, 'MANUAL' );
		wp_send_json_success( array(
			'message' => sprintf( __( '%d table(s) optimized — %s reclaimed', 'pm-db-cleaner' ), $optimized, round( $freed / 1024 / 1024, 1 ) . ' MB' ),
		) );
	}

}
