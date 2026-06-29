<?php
/**
 * PM DB Cleaner — WP Options & Autoload
 * Accès à la table wp_options (lecture, suppression, gestion autoload).
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Options {

	private static function ajax_check() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
	}

	// ─── WP Options : liste des clés ─────────────────────────────────────────

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

	// ─── WP Options : suppression ─────────────────────────────────────────────

	public static function ajax_delete_options() {
		self::ajax_check();
		global $wpdb;
		$keys = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) $_POST['keys'] ) : array();
		if ( empty( $keys ) ) { wp_send_json_error( array( 'message' => 'Aucune option sélectionnée.' ) ); }
		if ( count( $keys ) > 50 ) { wp_send_json_error( array( 'message' => 'Maximum 50 options par opération.' ) ); }

		$deleted = 0; $details = array();
		foreach ( $keys as $key ) {
			$result = $wpdb->delete( $wpdb->options, array( 'option_name' => $key ), array( '%s' ) );
			if ( $result ) { $deleted++; $details[] = $key; }
		}
		if ( $deleted > 0 ) { PM_DB_Cleaner_Logger::log( 'wp_options', $deleted, 0, 'MANUEL' ); }
		wp_send_json_success( array(
			'message' => $deleted > 0
				? sprintf( '%d option(s) supprimée(s) : %s', $deleted, implode( ', ', $details ) )
				: 'Aucune option trouvée.',
		) );
	}

	// ─── Autoload : analyse ───────────────────────────────────────────────────

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
		if ( $size_kb < 300 )     { $status = 'Normal';       $color = '#1e8c3b'; $bg = '#edfaef'; }
		elseif ( $size_kb < 800 ) { $status = 'À surveiller'; $color = '#856404'; $bg = '#fff3cd'; }
		else                      { $status = 'Surchargé';    $color = '#dc3232'; $bg = '#fef0f0'; }
		wp_send_json_success( array(
			'size_kb' => $size_kb, 'size_label' => $size_label,
			'count' => number_format_i18n( $count ), 'top10' => $top10,
			'status' => $status, 'status_color' => $color, 'status_bg' => $bg,
		) );
	}

	// ─── Autoload : désactivation ─────────────────────────────────────────────

	public static function ajax_disable_autoload() {
		self::ajax_check();
		global $wpdb;
		$names = isset( $_POST['option_names'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $_POST['option_names'] ) ) : array();
		if ( empty( $names ) ) { wp_send_json_error( array( 'message' => 'Aucune option sélectionnée.' ) ); }
		if ( count( $names ) > 15 ) { wp_send_json_error( array( 'message' => 'Maximum 15 options par opération.' ) ); }
		$updated = 0; $details = array();
		foreach ( $names as $name ) {
			$r = $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
			if ( $r !== false ) { $updated++; $details[] = $name; }
		}
		if ( $updated > 0 ) { PM_DB_Cleaner_Logger::log( 'wp_options_autoload', $updated, 0, 'MANUEL' ); }
		wp_send_json_success( array(
			'message' => sprintf( 'Autoload désactivé pour %d option(s) : %s', $updated, implode( ', ', $details ) ),
		) );
	}

}
