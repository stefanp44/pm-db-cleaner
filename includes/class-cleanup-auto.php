<?php
/**
 * PM DB Cleaner — Nettoyages automatiques
 * Méthodes déclenchées par les tâches cron planifiées.
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Auto {

	/**
	 * Retourne les taxonomies exclues du nettoyage des relations.
	 */
	public static function get_excluded_taxonomies() {
		$excl = array( 'link_category', 'term_language', 'term_translations' );
		return array_map( 'sanitize_key', (array) apply_filters( 'pm_db_cleaner_excluded_taxonomies', $excl ) );
	}

	// ─── Action Scheduler ────────────────────────────────────────────────────

	public static function cleanup_action_scheduler() {
		global $wpdb;
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" ) ) { return 0; }
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}actionscheduler_actions
			WHERE status IN ('complete', 'failed', 'canceled')
			AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)
			LIMIT 500", 7
		) );
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}actionscheduler_logs
			WHERE action_id NOT IN (SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions)
			LIMIT 500"
		);
		PM_DB_Cleaner_Logger::log( 'action_scheduler_daily', $deleted );
		return $deleted;
	}

	// ─── Nettoyage hebdomadaire ───────────────────────────────────────────────

	public static function cleanup_database() {
		$total = 0;
		$total += self::cleanup_orphan_postmeta();
		$total += self::cleanup_duplicated_postmeta();
		$total += self::cleanup_oembed_postmeta();
		$total += self::cleanup_orphan_commentmeta();
		$total += self::cleanup_duplicated_commentmeta();
		$total += self::cleanup_orphan_termmeta();
		$total += self::cleanup_duplicated_termmeta();
		$total += self::cleanup_orphan_term_relationships();
		// Sur multisite, usermeta est partagée — ne pas nettoyer
		if ( ! is_multisite() ) {
			$total += self::cleanup_orphan_usermeta();
			$total += self::cleanup_duplicated_usermeta();
		}
		$total += self::cleanup_orphaned_variations();
		PM_DB_Cleaner_Logger::log( 'database_weekly', $total );
		return $total;
	}

	// ─── Nettoyage mensuel ────────────────────────────────────────────────────

	public static function cleanup_monthly() {
		$total  = 0;
		$total += self::cleanup_trashed_comments();
		$total += self::cleanup_expired_transients();
		$total += self::cleanup_orphan_transient_timeouts();
		PM_DB_Cleaner_Logger::log( 'monthly', $total );
		return $total;
	}

	// ─── Posts ───────────────────────────────────────────────────────────────

	public static function cleanup_orphan_postmeta() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT pm.meta_id FROM $wpdb->postmeta pm
			LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID
			WHERE p.ID IS NULL LIMIT 500"
		);
		if ( empty( $ids ) ) { return 0; }
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_id IN ($ph)", $ids ) );
	}

	public static function cleanup_duplicated_postmeta() {
		global $wpdb;
		$count = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT GROUP_CONCAT(meta_id ORDER BY meta_id DESC) AS ids, post_id, COUNT(*) AS cnt
			FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING cnt > %d LIMIT 500", 1
		) );
		foreach ( (array) $rows as $row ) {
			$ids = array_map( 'intval', explode( ',', $row->ids ) );
			array_pop( $ids );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->postmeta WHERE meta_id IN (" . implode( ',', $ids ) . ') AND post_id = %d',
				intval( $row->post_id )
			) );
			$count += count( $ids );
		}
		return $count;
	}

	public static function cleanup_oembed_postmeta() {
		global $wpdb;
		$count = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_key FROM $wpdb->postmeta WHERE meta_key LIKE(%s) LIMIT 500", '%_oembed_%'
		) );
		foreach ( (array) $rows as $row ) {
			$post_id = intval( $row->post_id );
			if ( 0 === $post_id ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", $post_id, $row->meta_key ) );
			} else {
				delete_post_meta( $post_id, $row->meta_key );
			}
			$count++;
		}
		return $count;
	}

	// ─── Commentaires ─────────────────────────────────────────────────────────

	public static function cleanup_orphan_commentmeta() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT cm.meta_id FROM $wpdb->commentmeta cm
			LEFT JOIN $wpdb->comments c ON cm.comment_id = c.comment_ID
			WHERE c.comment_ID IS NULL LIMIT 500"
		);
		if ( empty( $ids ) ) { return 0; }
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->commentmeta WHERE meta_id IN ($ph)", $ids ) );
	}

	public static function cleanup_duplicated_commentmeta() {
		global $wpdb;
		$count = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT GROUP_CONCAT(meta_id ORDER BY meta_id DESC) AS ids, comment_id, COUNT(*) AS cnt
			FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING cnt > %d LIMIT 500", 1
		) );
		foreach ( (array) $rows as $row ) {
			$ids = array_map( 'intval', explode( ',', $row->ids ) );
			array_pop( $ids );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->commentmeta WHERE meta_id IN (" . implode( ',', $ids ) . ') AND comment_id = %d',
				intval( $row->comment_id )
			) );
			$count += count( $ids );
		}
		return $count;
	}

	public static function cleanup_trashed_comments() {
		global $wpdb;
		$count = 0;
		$ids   = $wpdb->get_col( "SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = 'trash' LIMIT 500" );
		foreach ( (array) $ids as $id ) {
			wp_delete_comment( intval( $id ), true );
			$count++;
		}
		return $count;
	}

	// ─── Termes & Taxonomies ──────────────────────────────────────────────────

	public static function cleanup_orphan_termmeta() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT tm.meta_id FROM $wpdb->termmeta tm
			LEFT JOIN $wpdb->terms t ON tm.term_id = t.term_id
			WHERE t.term_id IS NULL LIMIT 500"
		);
		if ( empty( $ids ) ) { return 0; }
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->termmeta WHERE meta_id IN ($ph)", $ids ) );
	}

	public static function cleanup_duplicated_termmeta() {
		global $wpdb;
		$count = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT GROUP_CONCAT(meta_id ORDER BY meta_id DESC) AS ids, term_id, COUNT(*) AS cnt
			FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING cnt > %d LIMIT 500", 1
		) );
		foreach ( (array) $rows as $row ) {
			$ids = array_map( 'intval', explode( ',', $row->ids ) );
			array_pop( $ids );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->termmeta WHERE meta_id IN (" . implode( ',', $ids ) . ') AND term_id = %d',
				intval( $row->term_id )
			) );
			$count += count( $ids );
		}
		return $count;
	}

	public static function cleanup_orphan_term_relationships() {
		global $wpdb;
		$count = 0;
		$excl  = self::get_excluded_taxonomies();
		$ph    = implode( ',', array_fill( 0, count( $excl ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT tr.object_id, tr.term_taxonomy_id, tt.term_id, tt.taxonomy
			FROM $wpdb->term_relationships AS tr
			INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy NOT IN ($ph)
			AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts) LIMIT 500",
			$excl
		) );
		foreach ( (array) $rows as $tax ) {
			$res = wp_remove_object_terms( intval( $tax->object_id ), intval( $tax->term_id ), $tax->taxonomy );
			if ( true !== $res ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id = %d",
					$tax->object_id, $tax->term_taxonomy_id
				) );
			}
			$count++;
		}
		return $count;
	}

	// ─── Utilisateurs ─────────────────────────────────────────────────────────

	public static function cleanup_orphan_usermeta() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT um.umeta_id FROM $wpdb->usermeta um
			LEFT JOIN $wpdb->users u ON um.user_id = u.ID
			WHERE u.ID IS NULL LIMIT 500"
		);
		if ( empty( $ids ) ) { return 0; }
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE umeta_id IN ($ph)", $ids ) );
	}

	public static function cleanup_duplicated_usermeta() {
		global $wpdb;
		$count = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT GROUP_CONCAT(umeta_id ORDER BY umeta_id DESC) AS ids, user_id, COUNT(*) AS cnt
			FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING cnt > %d LIMIT 500", 1
		) );
		foreach ( (array) $rows as $row ) {
			$ids = array_map( 'intval', explode( ',', $row->ids ) );
			array_pop( $ids );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->usermeta WHERE umeta_id IN (" . implode( ',', $ids ) . ') AND user_id = %d',
				intval( $row->user_id )
			) );
			$count += count( $ids );
		}
		return $count;
	}

	// ─── Système ──────────────────────────────────────────────────────────────

	public static function cleanup_expired_transients() {
		global $wpdb;
		$time  = time();
		$count = $wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d LIMIT 500",
			$wpdb->esc_like( '_transient_timeout_' ) . '%', $time
		) );
		$count += $wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d LIMIT 500",
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%', $time
		) );
		return $count;
	}

	/**
	 * Supprime les _transient_timeout_xxx dont _transient_xxx est absent.
	 * Approche la plus safe : on supprime uniquement un timeout qui pointe vers rien.
	 */
	public static function cleanup_orphan_transient_timeouts() {
		global $wpdb;
		$count = (int) $wpdb->query(
			"DELETE t FROM $wpdb->options t
			WHERE t.option_name LIKE '_transient_timeout_%'
			AND NOT EXISTS (
				SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub
				WHERE sub.option_name = REPLACE(t.option_name, '_transient_timeout_', '_transient_')
			)
			LIMIT 500"
		);
		$count += (int) $wpdb->query(
			"DELETE t FROM $wpdb->options t
			WHERE t.option_name LIKE '_site_transient_timeout_%'
			AND NOT EXISTS (
				SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub
				WHERE sub.option_name = REPLACE(t.option_name, '_site_transient_timeout_', '_site_transient_')
			)
			LIMIT 500"
		);
		return $count;
	}

	// ─── Wrappers AJAX ───────────────────────────────────────────────────────

	private static function ajax_check() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
	}

	public static function ajax_cleanup_action_scheduler() {
		self::ajax_check();
		$c = self::cleanup_action_scheduler();
		wp_send_json_success( array( 'message' => sprintf( '%d actions supprimées', $c ) ) );
	}
	public static function ajax_cleanup_orphan_postmeta() {
		self::ajax_check();
		$c = self::cleanup_orphan_postmeta(); PM_DB_Cleaner_Logger::log( 'orphan_postmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_duplicated_postmeta() {
		self::ajax_check();
		$c = self::cleanup_duplicated_postmeta(); PM_DB_Cleaner_Logger::log( 'duplicated_postmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_oembed_postmeta() {
		self::ajax_check();
		$c = self::cleanup_oembed_postmeta(); PM_DB_Cleaner_Logger::log( 'oembed_postmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_orphan_commentmeta() {
		self::ajax_check();
		$c = self::cleanup_orphan_commentmeta(); PM_DB_Cleaner_Logger::log( 'orphan_commentmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_duplicated_commentmeta() {
		self::ajax_check();
		$c = self::cleanup_duplicated_commentmeta(); PM_DB_Cleaner_Logger::log( 'duplicated_commentmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_orphan_termmeta() {
		self::ajax_check();
		$c = self::cleanup_orphan_termmeta(); PM_DB_Cleaner_Logger::log( 'orphan_termmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_duplicated_termmeta() {
		self::ajax_check();
		$c = self::cleanup_duplicated_termmeta(); PM_DB_Cleaner_Logger::log( 'duplicated_termmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_orphan_term_relationships() {
		self::ajax_check();
		$c = self::cleanup_orphan_term_relationships(); PM_DB_Cleaner_Logger::log( 'orphan_term_relationships', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_orphan_usermeta() {
		self::ajax_check();
		$c = self::cleanup_orphan_usermeta(); PM_DB_Cleaner_Logger::log( 'orphan_usermeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_duplicated_usermeta() {
		self::ajax_check();
		$c = self::cleanup_duplicated_usermeta(); PM_DB_Cleaner_Logger::log( 'duplicated_usermeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_orphaned_variations() {
		self::ajax_check();
		$c = self::cleanup_orphaned_variations(); PM_DB_Cleaner_Logger::log( 'orphaned_variations', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public static function ajax_cleanup_trashed_comments() {
		self::ajax_check();
		$c = self::cleanup_trashed_comments(); PM_DB_Cleaner_Logger::log( 'trashed_comments', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d commentaires supprimés', $c ) ) );
	}
	public static function ajax_cleanup_expired_transients() {
		self::ajax_check();
		$c = self::cleanup_expired_transients(); PM_DB_Cleaner_Logger::log( 'expired_transients', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d transients supprimés', $c ) ) );
	}
	public static function ajax_cleanup_orphan_transient_timeouts() {
		self::ajax_check();
		$c = self::cleanup_orphan_transient_timeouts(); PM_DB_Cleaner_Logger::log( 'orphan_transient_timeouts', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d timeout(s) orphelin(s) supprimé(s)', $c ) ) );
	}

	// ─── WooCommerce ──────────────────────────────────────────────────────────

	public static function cleanup_orphaned_variations() {
		global $wpdb;
		if ( ! class_exists( 'WooCommerce' ) ) { return 0; }
		return (int) $wpdb->query(
			"DELETE products FROM {$wpdb->posts} products
			LEFT JOIN {$wpdb->posts} wp ON wp.ID = products.post_parent
			WHERE wp.ID IS NULL AND products.post_type = 'product_variation' LIMIT 500"
		);
	}

}
