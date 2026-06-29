<?php
/**
 * PM DB Cleaner — WP Options & Autoload
 * Access to the wp_options table (listing, deletion, autoload management).
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Options {

	private static function ajax_check() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
	}

	// ─── WP Options: list keys ────────────────────────────────────────────────

	public static function ajax_get_option_keys() {
		self::ajax_check();
		global $wpdb;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		if ( $search !== '' ) {
			$keys = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_name LIMIT 500",
				'%' . $wpdb->esc_like( $search ) . '%'
			) );
		} else {
			$keys = $wpdb->get_col( "SELECT option_name FROM $wpdb->options ORDER BY option_name LIMIT 500" );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options" );
		wp_send_json_success( array( 'keys' => $keys, 'total' => $total ) );
	}

	// ─── WP Options: delete selected ─────────────────────────────────────────

	public static function ajax_delete_options() {
		self::ajax_check();
		global $wpdb;
		$keys = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) $_POST['keys'] ) : array();
		if ( empty( $keys ) ) { wp_send_json_error( array( 'message' => __( 'No option selected.', 'pm-db-cleaner' ) ) ); }
		if ( count( $keys ) > 50 ) { wp_send_json_error( array( 'message' => __( 'Maximum 50 options per operation.', 'pm-db-cleaner' ) ) ); }

		$deleted = 0; $details = array();
		foreach ( $keys as $key ) {
			$result = $wpdb->delete( $wpdb->options, array( 'option_name' => $key ), array( '%s' ) );
			if ( $result ) { $deleted++; $details[] = $key; }
		}
		if ( $deleted > 0 ) { PM_DB_Cleaner_Logger::log( 'wp_options', $deleted, 0, 'MANUAL' ); }
		wp_send_json_success( array(
			'message' => $deleted > 0
				? sprintf( __( '%d option(s) deleted: %s', 'pm-db-cleaner' ), $deleted, implode( ', ', $details ) )
				: __( 'No option found.', 'pm-db-cleaner' ),
		) );
	}

	// ─── Autoload: analyze ────────────────────────────────────────────────────

	public static function ajax_analyze_autoload() {
		self::ajax_check();
		global $wpdb;
		$al         = "'yes','on','1','true','auto','auto-on','auto-update'";
		$size_bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM $wpdb->options WHERE autoload IN ($al)" );
		$count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options WHERE autoload IN ($al)" );
		$top10_raw  = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size_bytes FROM $wpdb->options WHERE autoload IN ($al) ORDER BY size_bytes DESC LIMIT 10" );
		$top10 = array();
		foreach ( $top10_raw as $row ) {
			$kb      = round( $row->size_bytes / 1024, 1 );
			$top10[] = array(
				'name'     => $row->option_name,
				'size'     => $kb >= 1 ? $kb . ' KB' : $row->size_bytes . ' B',
				'autoload' => 'yes',
			);
		}
		$size_kb    = round( $size_bytes / 1024, 1 );
		$size_label = $size_kb >= 1024 ? round( $size_kb / 1024, 2 ) . ' MB' : $size_kb . ' KB';
		if ( $size_kb < 300 )     { $status = __( 'Normal', 'pm-db-cleaner' );      $color = '#1e8c3b'; $bg = '#edfaef'; }
		elseif ( $size_kb < 800 ) { $status = __( 'Monitor', 'pm-db-cleaner' );     $color = '#856404'; $bg = '#fff3cd'; }
		else                      { $status = __( 'Overloaded', 'pm-db-cleaner' );  $color = '#dc3232'; $bg = '#fef0f0'; }
		wp_send_json_success( array(
			'size_kb' => $size_kb, 'size_label' => $size_label,
			'count' => number_format_i18n( $count ), 'top10' => $top10,
			'status' => $status, 'status_color' => $color, 'status_bg' => $bg,
		) );
	}

	// ─── Autoload: disable ────────────────────────────────────────────────────

	public static function ajax_disable_autoload() {
		self::ajax_check();
		global $wpdb;
		$names = isset( $_POST['option_names'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $_POST['option_names'] ) ) : array();
		if ( empty( $names ) ) { wp_send_json_error( array( 'message' => __( 'No option selected.', 'pm-db-cleaner' ) ) ); }
		if ( count( $names ) > 15 ) { wp_send_json_error( array( 'message' => __( 'Maximum 15 options per operation.', 'pm-db-cleaner' ) ) ); }
		$updated = 0; $details = array();
		foreach ( $names as $name ) {
			$r = $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
			if ( $r !== false ) { $updated++; $details[] = $name; }
		}
		if ( $updated > 0 ) { PM_DB_Cleaner_Logger::log( 'wp_options_autoload', $updated, 0, 'MANUAL' ); }
		wp_send_json_success( array(
			'message' => sprintf( __( 'Autoload disabled for %d option(s): %s', 'pm-db-cleaner' ), $updated, implode( ', ', $details ) ),
		) );
	}

}
