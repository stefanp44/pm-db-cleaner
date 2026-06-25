<?php
/**
 * Plugin Name: PM DB Cleaner
 * Description: Nettoie automatiquement la base de données WordPress : métadonnées orphelines et dupliquées, caches oEmbed, commentaires en corbeille, transients expirés, Action Scheduler, variations WooCommerce orphelines, custom fields, options wp_options et autoload inutilisés, et tâches Cron orphelines. Nettoyages quotidiens/hebdomadaires/mensuels programmés avec logs traçables et limitation anti-timeout.
 * Version: 2026-06-25
 * Author: Perspectives Marketing
 * Author URI: https://perspectives.marketing
 * Update URI: https://github.com/perspectives-marketing/pm-db-cleaner
 *
 * @package PM_DB_Cleaner
 * @license GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

// ─── Plugin Update Checker ────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/load-v5p7.php';
$updateChecker = YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://github.com/stefanp44/pm-db-cleaner/',
	__FILE__,
	'pm-db-cleaner'
);
$updateChecker->setBranch( 'main' );
$updateChecker->setAuthentication( 'xxx' );
$updateChecker->addResultFilter( function( $info ) {
	$info->icons = array(
		'1x' => 'https://raw.githubusercontent.com/stefanp44/pm-assets/main/pm-db-cleaner/icon-128x128.png',
		'2x' => 'https://raw.githubusercontent.com/stefanp44/pm-assets/main/pm-db-cleaner/icon-256x256.png',
	);
	return $info;
} );

// ─── Hooks d'activation / désactivation ──────────────────────────────────────

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

// ─── Classe principale ────────────────────────────────────────────────────────

class PM_DB_Cleaner {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_monthly_schedule' ) );

		add_action( 'pm_cleanup_action_scheduler_daily', array( $this, 'cleanup_action_scheduler' ) );
		add_action( 'pm_cleanup_database_weekly', array( $this, 'cleanup_database' ) );
		add_action( 'pm_cleanup_monthly', array( $this, 'cleanup_monthly' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		// Nettoyages automatiques
		add_action( 'wp_ajax_pm_cleanup_action_scheduler', array( $this, 'ajax_cleanup_action_scheduler' ) );
		add_action( 'wp_ajax_pm_cleanup_orphan_postmeta', array( $this, 'ajax_cleanup_orphan_postmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_duplicated_postmeta', array( $this, 'ajax_cleanup_duplicated_postmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_oembed_postmeta', array( $this, 'ajax_cleanup_oembed_postmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_orphan_commentmeta', array( $this, 'ajax_cleanup_orphan_commentmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_duplicated_commentmeta', array( $this, 'ajax_cleanup_duplicated_commentmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_orphan_termmeta', array( $this, 'ajax_cleanup_orphan_termmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_duplicated_termmeta', array( $this, 'ajax_cleanup_duplicated_termmeta' ) );
		add_action( 'wp_ajax_pm_cleanup_orphan_term_relationships', array( $this, 'ajax_cleanup_orphan_term_relationships' ) );
		add_action( 'wp_ajax_pm_cleanup_orphan_usermeta', array( $this, 'ajax_cleanup_orphan_usermeta' ) );
		add_action( 'wp_ajax_pm_cleanup_duplicated_usermeta', array( $this, 'ajax_cleanup_duplicated_usermeta' ) );
		add_action( 'wp_ajax_pm_cleanup_orphaned_variations', array( $this, 'ajax_cleanup_orphaned_variations' ) );
		add_action( 'wp_ajax_pm_cleanup_trashed_comments', array( $this, 'ajax_cleanup_trashed_comments' ) );
		add_action( 'wp_ajax_pm_cleanup_expired_transients', array( $this, 'ajax_cleanup_expired_transients' ) );
		add_action( 'wp_ajax_pm_cleanup_all', array( $this, 'ajax_cleanup_all' ) );

		// Métadonnées (postmeta) : récupérer les clés + supprimer
		add_action( 'wp_ajax_pm_get_meta_keys', array( $this, 'ajax_get_meta_keys' ) );
		add_action( 'wp_ajax_pm_delete_custom_fields', array( $this, 'ajax_delete_custom_fields' ) );

		// WP Options : accès table complète + autoload
		add_action( 'wp_ajax_pm_get_option_keys', array( $this, 'ajax_get_option_keys' ) );
		add_action( 'wp_ajax_pm_delete_options', array( $this, 'ajax_delete_options' ) );
		add_action( 'wp_ajax_pm_analyze_autoload', array( $this, 'ajax_analyze_autoload' ) );
		add_action( 'wp_ajax_pm_disable_autoload', array( $this, 'ajax_disable_autoload' ) );

		// Overhead BDD
		add_action( 'wp_ajax_pm_cleanup_db_overhead', array( $this, 'ajax_cleanup_db_overhead' ) );

		// Transients orphelins + Dirsize Cache
		add_action( 'wp_ajax_pm_cleanup_orphan_transient_timeouts', array( $this, 'ajax_cleanup_orphan_transient_timeouts' ) );
		add_action( 'wp_ajax_pm_cleanup_dirsize_cache', array( $this, 'ajax_cleanup_dirsize_cache' ) );

		// Tâches Cron orphelines
		add_action( 'wp_ajax_pm_delete_cron_orphans', array( $this, 'ajax_delete_cron_orphans' ) );

		// Désinstallation manuelle (SFTP/SSH)
		add_action( 'wp_ajax_pm_uninstall_cron', array( $this, 'ajax_uninstall_cron' ) );
	}

	// ─── Cron ────────────────────────────────────────────────────────────────

	public function add_monthly_schedule( $schedules ) {
		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Une fois par mois', 'pm-db-cleaner' )
		);
		return $schedules;
	}

	public function cleanup_action_scheduler() {
		global $wpdb;
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" ) ) {
			return 0;
		}
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}actionscheduler_actions
			WHERE status IN ('complete', 'failed', 'canceled')
			AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)
			LIMIT 500",
			7
		) );
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}actionscheduler_logs
			WHERE action_id NOT IN (SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions)
			LIMIT 500"
		);
		$this->log_cleanup( 'action_scheduler_daily', $deleted );
		return $deleted;
	}

	public function cleanup_database() {
		$total = 0;
		$total += $this->cleanup_orphan_postmeta();
		$total += $this->cleanup_duplicated_postmeta();
		$total += $this->cleanup_oembed_postmeta();
		$total += $this->cleanup_orphan_commentmeta();
		$total += $this->cleanup_duplicated_commentmeta();
		$total += $this->cleanup_orphan_termmeta();
		$total += $this->cleanup_duplicated_termmeta();
		$total += $this->cleanup_orphan_term_relationships();
		// Sur multisite, usermeta est partagée — ne pas nettoyer
		if ( ! is_multisite() ) {
			$total += $this->cleanup_orphan_usermeta();
			$total += $this->cleanup_duplicated_usermeta();
		}
		$total += $this->cleanup_orphaned_variations();
		$this->log_cleanup( 'database_weekly', $total );
		return $total;
	}

	public function cleanup_monthly() {
		$total  = 0;
		$total += $this->cleanup_trashed_comments();
		$total += $this->cleanup_expired_transients();
		$total += $this->cleanup_orphan_transient_timeouts();
		$this->log_cleanup( 'monthly', $total );
		return $total;
	}

	// ─── Nettoyages individuels ───────────────────────────────────────────────

	private function cleanup_orphan_postmeta() {
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

	private function cleanup_duplicated_postmeta() {
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

	private function cleanup_oembed_postmeta() {
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

	private function cleanup_orphan_commentmeta() {
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

	private function cleanup_duplicated_commentmeta() {
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

	private function cleanup_orphan_termmeta() {
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

	private function cleanup_duplicated_termmeta() {
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

	private function cleanup_orphan_term_relationships() {
		global $wpdb;
		$count = 0;
		$excl  = $this->get_excluded_taxonomies();
		// Passage par prepare() : on construit les placeholders %s pour chaque taxonomie exclue
		$ph    = implode( ',', array_fill( 0, count( $excl ), '%s' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tr.term_taxonomy_id, tt.term_id, tt.taxonomy
				FROM $wpdb->term_relationships AS tr
				INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy NOT IN ($ph)
				AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts) LIMIT 500",
				$excl
			)
		);
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

	private function cleanup_orphan_usermeta() {
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

	private function cleanup_duplicated_usermeta() {
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

	private function cleanup_orphaned_variations() {
		global $wpdb;
		if ( ! class_exists( 'WooCommerce' ) ) { return 0; }
		return (int) $wpdb->query(
			"DELETE products FROM {$wpdb->posts} products
			LEFT JOIN {$wpdb->posts} wp ON wp.ID = products.post_parent
			WHERE wp.ID IS NULL AND products.post_type = 'product_variation' LIMIT 500"
		);
	}

	private function cleanup_trashed_comments() {
		global $wpdb;
		$count = 0;
		$ids   = $wpdb->get_col( "SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = 'trash' LIMIT 500" );
		foreach ( (array) $ids as $id ) {
			wp_delete_comment( intval( $id ), true );
			$count++;
		}
		return $count;
	}

	private function cleanup_expired_transients() {
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
	 * Transients orphelins : supprime les _transient_timeout_xxx
	 * dont la valeur _transient_xxx correspondante est absente.
	 * Approche la plus safe : le transient lui-même n'existe plus,
	 * on ne supprime qu'un timeout qui pointe vers rien.
	 * On ne touche jamais aux transients sans timeout (permanents voulus).
	 */
	private function cleanup_orphan_transient_timeouts() {
		global $wpdb;
		$count = (int) $wpdb->query(
			"DELETE t FROM $wpdb->options t
			WHERE t.option_name LIKE \'_transient_timeout_%\'
			AND NOT EXISTS (
				SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub
				WHERE sub.option_name = REPLACE(t.option_name, \'_transient_timeout_\', \'_transient_\')
			)
			LIMIT 500"
		);
		$count += (int) $wpdb->query(
			"DELETE t FROM $wpdb->options t
			WHERE t.option_name LIKE \'_site_transient_timeout_%\'
			AND NOT EXISTS (
				SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub
				WHERE sub.option_name = REPLACE(t.option_name, \'_site_transient_timeout_\', \'_site_transient_\')
			)
			LIMIT 500"
		);
		return $count;
	}

	/**
	 * Dirsize Cache : cache WordPress de la taille des dossiers uploads.
	 * Peut devenir très volumineux. Se recrée automatiquement à la prochaine
	 * visite de la médiathèque. Suppression sans aucun risque.
	 */
	private function cleanup_dirsize_cache() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s LIMIT 500",
			'%' . $wpdb->esc_like( 'dirsize_cache' ) . '%'
		) );
	}

	// ─── Utilitaires ──────────────────────────────────────────────────────────

	private function get_excluded_taxonomies() {
		$excl = array( 'link_category', 'term_language', 'term_translations' );
		return array_map( 'sanitize_key', (array) apply_filters( 'pm_db_cleaner_excluded_taxonomies', $excl ) );
	}

	private function log_cleanup( $type, $count, $remaining = 0, $mode = 'AUTO' ) {
		$upload_dir = wp_upload_dir();
		if ( is_multisite() ) {
			$bid      = get_current_blog_id();
			$log_file = $upload_dir['basedir'] . '/pm-db-cleaner-site-' . $bid . '.txt';
			$log_glob = $upload_dir['basedir'] . '/pm-db-cleaner-site-' . $bid . '-*.txt';
		} else {
			$log_file = $upload_dir['basedir'] . '/pm-db-cleaner.txt';
			$log_glob = $upload_dir['basedir'] . '/pm-db-cleaner-*.txt';
		}
		// Rotation > 5 MB
		if ( file_exists( $log_file ) && filesize( $log_file ) > 5 * 1024 * 1024 ) {
			rename( $log_file, str_replace( '.txt', '-' . date( 'Y-m-d-His' ) . '.txt', $log_file ) );
			foreach ( (array) glob( $log_glob ) as $old ) {
				if ( filemtime( $old ) < time() - 90 * DAY_IN_SECONDS ) { unlink( $old ); }
			}
		}
		$ts      = current_time( 'Y-m-d H:i:s' );
		$label   = $this->get_cleanup_label( $type );
		$message = $remaining > 0
			? sprintf( '[%s] %s | %s | %d supprimés | %d restants', $ts, $mode, $label, $count, $remaining )
			: sprintf( '[%s] %s | %s | %d supprimés', $ts, $mode, $label, $count );
		if ( is_multisite() ) {
			$message .= ' | site:' . get_blog_details( get_current_blog_id() )->blogname;
		}
		file_put_contents( $log_file, $message . "\n", FILE_APPEND | LOCK_EX );
	}

	private function get_cleanup_label( $type ) {
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
			'orphaned_variations'       => 'WooCommerce - Variations orphelines',
			'database_weekly'           => 'Base de données (hebdo)',
			'monthly'                   => 'Commentaires + Transients (mensuel)',
			'custom_fields'             => 'Métadonnées - Suppression manuelle',
			'wp_options'                => 'WP Options - Suppression manuelle',
			'wp_options_autoload'       => 'WP Options - Autoload désactivé',
			'db_overhead'               => 'Overhead BDD - Tables optimisées',
			'cron_orphans'              => 'Cron - Tâches orphelines supprimées',
			'orphan_transient_timeouts' => 'Transients - Timeouts orphelins',
			'dirsize_cache'             => 'Système - Dirsize Cache supprimé',
		);
		return $labels[ $type ] ?? $type;
	}

	private function format_cron_time( $timestamp ) {
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'd/m/Y à H:i' );
	}

	private function get_recent_logs( $lines = 20 ) {
		$upload_dir = wp_upload_dir();
		$log_file   = is_multisite()
			? $upload_dir['basedir'] . '/pm-db-cleaner-site-' . get_current_blog_id() . '.txt'
			: $upload_dir['basedir'] . '/pm-db-cleaner.txt';
		if ( ! file_exists( $log_file ) ) { return array(); }
		$content = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return $content ? array_slice( array_reverse( $content ), 0, $lines ) : array();
	}

	// ─── Admin ───────────────────────────────────────────────────────────────

	public function admin_menu() {
		add_management_page( 'PM DB Cleaner', 'PM DB Cleaner', 'manage_options', 'pm-db-cleaner', array( $this, 'admin_page' ) );
	}

	public function admin_scripts( $hook ) {
		if ( 'tools_page_pm-db-cleaner' !== $hook ) { return; }

		$base = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_style( 'pm-db-cleaner', $base . 'admin.css', array(), '2026-06-25' );
		wp_enqueue_script( 'pm-db-cleaner', $base . 'admin.js', array( 'jquery' ), '2026-06-25', true );
		wp_localize_script( 'pm-db-cleaner', 'pmDBCleaner', array(
			'nonce'      => wp_create_nonce( 'pm_db_cleanup' ),
			'processing' => 'Nettoyage...',
			'success'    => 'Terminé !',
			'error'      => 'Erreur',
		) );
	}

	// ─── Page admin ───────────────────────────────────────────────────────────

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Vous n\'avez pas les permissions nécessaires.' );
		}

		$stats        = $this->get_stats();
		$cron_orphans = $this->get_cron_orphans();
		$as_next      = wp_next_scheduled( 'pm_cleanup_action_scheduler_daily' );
		$db_next      = wp_next_scheduled( 'pm_cleanup_database_weekly' );
		$monthly_next = wp_next_scheduled( 'pm_cleanup_monthly' );
		?>
		<div class="wrap pm-db-cleaner-wrap">
			<h1><span class="dashicons dashicons-database"></span> PM DB Cleaner</h1>

			<!-- ── Header : nettoyage automatique + branding ── -->
			<div class="pm-header-grid">
				<details class="pm-collapsible pm-collapsible-success">
					<summary>
						<span class="dashicons dashicons-clock"></span>
						<strong>Nettoyage automatique programmé</strong>
					</summary>
					<div class="pm-collapsible-content">
						<ul>
							<li><strong>Action Scheduler :</strong> Nettoyage quotidien — supprime les tâches terminées, échouées ou annulées de plus de 7 jours (et leurs logs)
								<ul><li>Prochain : <strong><?php echo $as_next ? $this->format_cron_time( $as_next ) : 'Non planifié'; ?></strong></li></ul>
							</li>
							<li><strong>Base de données :</strong> Nettoyage hebdomadaire — Posts, Commentaires, Termes &amp; Taxonomies<?php echo is_multisite() ? '' : ', Utilisateurs'; ?>, WooCommerce
								<ul><li>Prochain : <strong><?php echo $db_next ? $this->format_cron_time( $db_next ) : 'Non planifié'; ?></strong></li></ul>
							</li>
							<li><strong>Commentaires + Transients :</strong> Nettoyage mensuel
								<ul><li>Prochain : <strong><?php echo $monthly_next ? $this->format_cron_time( $monthly_next ) : 'Non planifié'; ?></strong></li></ul>
							</li>
						</ul>
						<p style="margin-top:10px;font-size:12px;color:#646970">
							<strong>Non automatisé — manuel uniquement :</strong> Métadonnées (postmeta), WP Options, Autoload, Overhead BDD.
						</p>
						<?php if ( is_multisite() ) : ?>
						<p style="margin-top:8px;padding:8px;background:#fff3cd;border-left:3px solid #ffc107;font-size:12px;color:#856404">
							<strong>ℹ️ Multisite :</strong> Les métadonnées utilisateurs ne sont pas nettoyées (table partagée).
						</p>
						<?php endif; ?>
						<?php
						$recent_logs = $this->get_recent_logs( 20 );
						if ( $recent_logs ) :
							$log_filename = is_multisite() ? 'pm-db-cleaner-site-' . get_current_blog_id() . '.txt' : 'pm-db-cleaner.txt';
						?>
						<div style="margin-top:20px;padding-top:15px;border-top:2px solid #ddd">
							<strong style="display:block;margin-bottom:10px;font-size:14px">📋 Historique (<?php echo count( $recent_logs ); ?> derniers)</strong>
							<div style="background:#f6f7f7;border-radius:4px;padding:10px;font-family:monospace;font-size:12px;max-height:200px;overflow-y:auto;line-height:1.8">
								<?php foreach ( $recent_logs as $line ) :
									$color = strpos( $line, 'MANUEL' ) !== false ? '#B11F8F' : '#1e8c3b';
									echo '<div style="color:' . $color . '">' . esc_html( $line ) . '</div>';
								endforeach; ?>
							</div>
							<p style="margin:10px 0 0;font-size:12px">
								<a href="<?php echo esc_url( wp_upload_dir()['baseurl'] . '/' . $log_filename ); ?>" download style="color:#289dcc;text-decoration:none">📥 Télécharger le fichier complet</a>
							</p>
						</div>
						<?php endif; ?>
						<div style="margin-top:15px;padding-top:15px;border-top:1px solid rgba(0,0,0,0.1)">
							<button id="pm-cleanup-all" class="pm-btn pm-btn-all">
								<span class="dashicons dashicons-database"></span> Tout nettoyer maintenant
							</button>
						</div>
					</div>
				</details>
				<div class="pm-header-branding">
					<div class="pm-author-compact">
						Développé par <a href="https://perspectives.marketing" target="_blank"><strong>Perspectives Marketing</strong></a>
					</div>
				</div>
			</div>

			<!-- ── Milieu : tableau nettoyage (2/3) + autoload (1/3) ── -->
			<div class="pm-main-grid">

				<!-- Tableau de nettoyage -->
				<div class="pm-settings-section" style="margin-bottom:0">
					<table class="pm-cleanup-table">
						<tbody>
							<tr class="pm-category-row"><td colspan="3"><strong>Posts</strong></td></tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées orphelines</td>
								<td style="text-align:center;width:80px"><span class="pm-count<?php echo $stats['orphan_postmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['orphan_postmeta'] ); ?></span></td>
								<td style="text-align:center;width:100px"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphan_postmeta" <?php echo $stats['orphan_postmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées dupliquées</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['duplicated_postmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['duplicated_postmeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_duplicated_postmeta" <?php echo $stats['duplicated_postmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Caches oEmbed</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['oembed_postmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['oembed_postmeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_oembed_postmeta" <?php echo $stats['oembed_postmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>

							<tr class="pm-category-row"><td colspan="3"><strong>Commentaires</strong></td></tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées orphelines</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['orphan_commentmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['orphan_commentmeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphan_commentmeta" <?php echo $stats['orphan_commentmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées dupliquées</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['duplicated_commentmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['duplicated_commentmeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_duplicated_commentmeta" <?php echo $stats['duplicated_commentmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Commentaires en corbeille</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['trashed_comments'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['trashed_comments'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_trashed_comments" <?php echo $stats['trashed_comments'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>

							<tr class="pm-category-row"><td colspan="3"><strong>Termes & Taxonomies</strong></td></tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées orphelines</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['orphan_termmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['orphan_termmeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphan_termmeta" <?php echo $stats['orphan_termmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées dupliquées</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['duplicated_termmeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['duplicated_termmeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_duplicated_termmeta" <?php echo $stats['duplicated_termmeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Relations orphelines</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['orphan_term_relationships'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['orphan_term_relationships'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphan_term_relationships" <?php echo $stats['orphan_term_relationships'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>

							<?php if ( ! is_multisite() ) : ?>
							<tr class="pm-category-row"><td colspan="3"><strong>Utilisateurs</strong></td></tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées orphelines</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['orphan_usermeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['orphan_usermeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphan_usermeta" <?php echo $stats['orphan_usermeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Métadonnées dupliquées</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['duplicated_usermeta'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['duplicated_usermeta'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_duplicated_usermeta" <?php echo $stats['duplicated_usermeta'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<?php endif; ?>

							<tr class="pm-category-row"><td colspan="3"><strong>Système</strong></td></tr>
							<tr>
								<td class="pm-cleanup-item">Action Scheduler (actions de +7 jours)</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['action_scheduler_old'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['action_scheduler_old'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_action_scheduler" <?php echo $stats['action_scheduler_old'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Transients expirés</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['expired_transients'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['expired_transients'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_expired_transients" <?php echo $stats['expired_transients'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">Transients — timeouts orphelins (timeout sans transient correspondant)</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['orphan_transient_timeouts'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['orphan_transient_timeouts'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphan_transient_timeouts" <?php echo $stats['orphan_transient_timeouts'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<?php if ( class_exists( 'WooCommerce' ) && $stats['orphaned_variations'] > 0 ) : ?>
							<tr class="pm-category-row"><td colspan="3"><strong>WooCommerce</strong></td></tr>
							<tr>
								<td class="pm-cleanup-item">Variations orphelines</td>
								<td style="text-align:center"><span class="pm-count"><?php echo number_format_i18n( $stats['orphaned_variations'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_orphaned_variations">Nettoyer</button></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Colonne 1/3 : Autoload + Nettoyages manuels -->
				<div>

				<!-- Autoload -->
				<div class="pm-pane">
					<div class="pm-pane-title">
						Autoload (wp_options)
						<button id="pm-options-analyze" class="pm-btn" style="float:right">Analyser</button>
					</div>
					<div class="pm-cf-wrap">
						<div class="pm-autoload-box">
							<div id="pm-options-autoload-results" style="display:none">
								<div class="pm-autoload-stats">
									<div class="pm-autoload-stat" id="pm-options-autoload-size"></div>
									<div class="pm-autoload-stat" id="pm-options-autoload-count"></div>
								</div>
								<div class="pm-keys-wrap pm-keys-wrap--short">
									<div id="pm-options-top10-body"></div>
								</div>
								<div id="pm-autoload-confirm-wrap" class="pm-confirm-box" style="display:none">
									<label>
										<input type="checkbox" id="pm-autoload-confirm">
										<span><strong>⚠️ Confirmation :</strong> Désactiver l'autoload modifie le comportement de chargement. Réversible, mais peut affecter les performances.</span>
									</label>
								</div>
								<button id="pm-autoload-disable" class="pm-btn pm-btn-autoload" disabled>Désactiver l'autoload de la sélection</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Nettoyages manuels -->
				<div class="pm-pane pm-pane--top">
					<div class="pm-pane-title">Nettoyages manuels</div>
					<table class="pm-cleanup-table">
						<tbody>
							<tr>
								<td class="pm-cleanup-item">
									Dirsize Cache<br>
									<span class="pm-item-desc">Cache de la taille des dossiers <code>uploads</code>. Se recrée automatiquement à la prochaine visite de la médiathèque. Sans risque.</span>
								</td>
								<td style="text-align:center;width:60px"><span class="pm-count<?php echo $stats['dirsize_cache'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['dirsize_cache'] ); ?></span></td>
								<td style="text-align:center;width:90px"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_dirsize_cache" <?php echo $stats['dirsize_cache'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">
									Overhead base de données<br>
									<span class="pm-item-desc">Espace perdu après suppressions (tables MySQL fragmentées). À effectuer de préférence hors heures de pointe.</span>
								</td>
								<td style="text-align:center"><span class="pm-count<?php echo $stats['db_overhead_bytes'] == 0 ? ' zero' : ''; ?>"><?php echo esc_html( $stats['db_overhead_label'] ); ?></span></td>
								<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_db_overhead" <?php echo $stats['db_overhead_bytes'] == 0 ? 'disabled' : ''; ?>>Nettoyer</button></td>
							</tr>
							<tr>
								<td class="pm-cleanup-item">
									Révisions d'articles<br>
									<span class="pm-item-desc">Limitez les futures révisions via <code>define('WP_POST_REVISIONS', 10);</code> dans <code>wp-config.php</code>.<br>
									<?php
									$rev = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : null;
									if ( $rev === null ) {
										echo '<span style="color:#856404">⚠️ Illimité — constante absente de wp-config.php</span>';
									} elseif ( $rev === false || $rev === 0 ) {
										echo '<span style="color:#1e8c3b">✅ Révisions désactivées</span>';
									} else {
										$n = (int) $rev; $lbl = $n === 1 ? 'révision' : 'révisions';
										$col = $n <= 15 ? '#1e8c3b' : ( $n <= 30 ? '#856404' : '#dc3232' );
										$icon = $n <= 15 ? '✅' : ( $n <= 30 ? '⚠️' : '🔴' );
										echo '<span style="color:' . $col . '">' . $icon . ' Limité à ' . $n . ' ' . $lbl . ' par article</span>';
									}
									?></span>
								</td>
								<td style="text-align:center"><span class="pm-info-count"><?php echo number_format_i18n( $stats['revisions'] ); ?></span></td>
								<td style="text-align:center"><span class="pm-readonly">Lecture seule</span></td>
							</tr>
						</tbody>
					</table>
				</div>

				</div><!-- colonne 1/3 -->

			</div><!-- .pm-main-grid -->

			<!-- ── Bas : Métadonnées (postmeta) + WP Options ── -->
			<div class="pm-two-col">

				<!-- Métadonnées (postmeta) -->
				<div class="pm-pane">
					<div class="pm-pane-title">
						Métadonnées (postmeta)
						<button id="pm-cf-analyze" class="pm-btn" style="float:right">Analyser</button>
					</div>
					<div class="pm-cf-wrap">
						<div class="pm-cf-radio-group">
							<label><input type="radio" name="pm_cf_type" value="post" checked> Posts</label>
							<label><input type="radio" name="pm_cf_type" value="term"> Termes</label>
							<label><input type="radio" name="pm_cf_type" value="user"> Utilisateurs</label>
							<label><input type="radio" name="pm_cf_type" value="all"> Toutes</label>
						</div>
						<div id="pm-cf-results" style="display:none">
							<input type="text" id="pm-cf-filter" class="pm-filter-input" placeholder="Filtrer les clés...">
							<div class="pm-keys-wrap"><div id="pm-cf-keys-list"></div></div>
							<div id="pm-cf-confirm-wrap" class="pm-confirm-box" style="display:none">
								<label>
									<input type="checkbox" id="pm-cf-confirm">
									<span><strong>⚠️ Confirmation obligatoire :</strong> J'ai effectué une sauvegarde complète de la base de données. La suppression est <strong>définitive et irréversible</strong>.</span>
								</label>
							</div>
							<button id="pm-cf-delete" class="pm-btn pm-btn-danger" disabled>Supprimer la sélection</button>
						</div>
					</div>
				</div>

				<!-- WP Options — accès table complète -->
				<div class="pm-pane">
					<div class="pm-pane-title">
						WP Options
						<button id="pm-wpo-analyze" class="pm-btn" style="float:right">Analyser</button>
					</div>
					<p style="font-size:12px;color:#dc3232;background:#fef0f0;border:1px solid #dc3232;border-radius:4px;padding:10px;margin:0 0 12px">
						<strong>⚠️ Zone à risque — à utiliser à vos risques et périls.</strong><br>
						La table <code>wp_options</code> contient des données critiques pour le fonctionnement de WordPress et de vos plugins. Supprimer une option incorrecte peut casser le site. N'agissez ici que si vous savez exactement ce que vous supprimez (résidus d'un plugin désinstallé, par exemple) et après avoir effectué une sauvegarde.
					</p>
					<div id="pm-wpo-results" style="display:none">
						<input type="text" id="pm-wpo-filter" class="pm-filter-input" placeholder="Filtrer les options...">
						<div class="pm-keys-wrap"><div id="pm-wpo-keys-list"></div></div>
						<div id="pm-wpo-confirm-wrap" class="pm-confirm-box pm-confirm-box-danger" style="display:none">
							<label>
								<input type="checkbox" id="pm-wpo-confirm">
								<span><strong>⚠️ Confirmation obligatoire :</strong> J'ai effectué une sauvegarde complète de la base de données. Je sais exactement ce que je supprime et j'accepte les risques. La suppression est <strong>définitive et irréversible</strong>.</span>
							</label>
						</div>
						<button id="pm-wpo-delete" class="pm-btn pm-btn-danger" disabled>Supprimer la sélection</button>
					</div>
				</div>

			</div><!-- .pm-two-col -->

			<!-- ── Bas : Cron orphelines + SFTP ── -->
			<div class="pm-two-col">

				<!-- Tâches Cron orphelines -->
				<div class="pm-pane">
					<div class="pm-pane-title">
						Tâches Cron orphelines
						<button id="pm-cron-toggle" class="pm-btn" style="float:right"><?php echo count( $cron_orphans ); ?> détectée(s) — afficher</button>
					</div>
					<p style="font-size:12px;color:#646970;margin:0 0 8px">
						Hooks planifiés sans callback enregistré (<code>has_action()</code> vide) — résidus d'un plugin désinstallé. Détection au chargement de la page (comme WP Crontrol) pour éviter les faux positifs.
					</p>
					<div id="pm-cron-results" style="display:none;margin-top:12px">
						<?php if ( empty( $cron_orphans ) ) : ?>
							<p style="font-size:13px;color:#646970;padding:6px">Aucune tâche cron orpheline détectée.</p>
						<?php else : ?>
							<div class="pm-keys-wrap">
								<div id="pm-cron-list">
									<?php foreach ( $cron_orphans as $o ) : ?>
									<label class="pm-key-label">
										<input type="checkbox" class="pm-cron-key" data-hook="<?php echo esc_attr( $o['hook'] ); ?>" data-timestamp="<?php echo esc_attr( $o['timestamp'] ); ?>">
										<span><?php echo esc_html( $o['hook'] ); ?></span>
										<span class="pm-key-size"><?php echo esc_html( $o['recurrence'] ); ?> — prochain : <?php echo esc_html( $o['next_run'] ); ?></span>
									</label>
									<?php endforeach; ?>
								</div>
							</div>
							<div id="pm-cron-confirm-wrap" class="pm-confirm-box" style="display:none">
								<label>
									<input type="checkbox" id="pm-cron-confirm">
									<span><strong>⚠️ Confirmation obligatoire :</strong> Je sais que le(s) plugin(s) à l'origine de ces tâches ne sont plus utilisés sur ce site.</span>
								</label>
							</div>
							<button id="pm-cron-delete" class="pm-btn pm-btn-danger" disabled>Supprimer la sélection</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Désinstallation manuelle SFTP/SSH -->
				<div class="pm-pane">
					<div class="pm-pane-title">Supprimer le plugin via SFTP/SSH ?</div>
					<p style="font-size:12px;color:#646970;margin:0 0 12px">
						<strong>Désactivation via Extensions &gt; Désactiver</strong> → les 3 tâches cron sont supprimées automatiquement, rien à faire ici.<br><br>
						<strong>Suppression directe via SFTP/SSH</strong> sans désactivation WordPress → cliquez ci-dessous <strong>avant</strong> de supprimer le fichier pour éviter que ces tâches restent orphelines indéfiniment.
					</p>
					<div id="pm-uninstall-confirm-wrap" class="pm-confirm-box" style="display:none">
						<label>
							<input type="checkbox" id="pm-uninstall-confirm">
							<span>Je vais supprimer le fichier via SFTP/SSH — retirer les 3 tâches cron du plugin (<code>pm_cleanup_action_scheduler_daily</code>, <code>pm_cleanup_database_weekly</code>, <code>pm_cleanup_monthly</code>).</span>
						</label>
					</div>
					<button id="pm-uninstall-toggle" class="pm-btn">Préparer la suppression manuelle</button>
					<button id="pm-uninstall-confirm-btn" class="pm-btn pm-btn-danger" style="display:none" disabled>Supprimer les tâches cron du plugin</button>
				</div>

			</div><!-- .pm-two-col -->

		</div><!-- .pm-db-cleaner-wrap -->
		<?php
	}

	// ─── Statistiques ────────────────────────────────────────────────────────

	private function get_stats() {
		global $wpdb;
		$s = array();

		$s['action_scheduler_old'] = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" )
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)", 7 ) )
			: 0;

		$s['orphan_postmeta']    = $wpdb->get_var( "SELECT COUNT(pm.meta_id) FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(meta_id) AS c FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_postmeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;
		$s['oembed_postmeta']    = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(meta_id) FROM $wpdb->postmeta WHERE meta_key LIKE(%s)", '%_oembed_%' ) );

		$s['orphan_commentmeta']    = $wpdb->get_var( "SELECT COUNT(cm.meta_id) FROM $wpdb->commentmeta cm LEFT JOIN $wpdb->comments c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(meta_id) AS c FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_commentmeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;
		$s['trashed_comments']       = $wpdb->get_var( "SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = 'trash'" );

		$s['orphan_termmeta']    = $wpdb->get_var( "SELECT COUNT(tm.meta_id) FROM $wpdb->termmeta tm LEFT JOIN $wpdb->terms t ON tm.term_id = t.term_id WHERE t.term_id IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(meta_id) AS c FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_termmeta']    = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;
		$excl = $this->get_excluded_taxonomies();
		$ph   = implode( ',', array_fill( 0, count( $excl ), '%s' ) );
		$s['orphan_term_relationships'] = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(object_id) FROM $wpdb->term_relationships AS tr
				INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy NOT IN ($ph)
				AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)",
				$excl
			)
		);

		$s['orphan_usermeta']    = $wpdb->get_var( "SELECT COUNT(um.umeta_id) FROM $wpdb->usermeta um LEFT JOIN $wpdb->users u ON um.user_id = u.ID WHERE u.ID IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(umeta_id) AS c FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_usermeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;

		$time = time();
		$s['expired_transients']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $time ) );
		$s['expired_transients'] += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_site_transient_timeout_' ) . '%', $time ) );

		// Timeouts orphelins (timeout sans transient correspondant)
		$s['orphan_transient_timeouts'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $wpdb->options t
			WHERE t.option_name LIKE \'_transient_timeout_%\'
			AND NOT EXISTS (
				SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub
				WHERE sub.option_name = REPLACE(t.option_name, \'_transient_timeout_\', \'_transient_\')
			)"
		);
		$s['orphan_transient_timeouts'] += (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $wpdb->options t
			WHERE t.option_name LIKE \'_site_transient_timeout_%\'
			AND NOT EXISTS (
				SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub
				WHERE sub.option_name = REPLACE(t.option_name, \'_site_transient_timeout_\', \'_site_transient_\')
			)"
		);

		// Dirsize Cache
		$s['dirsize_cache'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s",
			'%' . $wpdb->esc_like( 'dirsize_cache' ) . '%'
		) );

		$s['orphaned_variations'] = class_exists( 'WooCommerce' )
			? $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} products LEFT JOIN {$wpdb->posts} wp ON wp.ID = products.post_parent WHERE wp.ID IS NULL AND products.post_type = 'product_variation'" )
			: 0;

		$s['revisions'] = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'revision'" );

		$overhead = $wpdb->get_results( $wpdb->prepare( "SELECT SUM(Data_free) as overhead FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s AND Data_free > 0", $wpdb->prefix . '%' ) );
		$ob = isset( $overhead[0]->overhead ) ? (int) $overhead[0]->overhead : 0;
		$s['db_overhead_bytes'] = $ob >= 5 * 1024 * 1024 ? $ob : 0;
		$s['db_overhead_label'] = $s['db_overhead_bytes'] > 0 ? round( $s['db_overhead_bytes'] / 1024 / 1024, 1 ) . ' MB' : '0 MB';

		return $s;
	}

	// ─── AJAX — nettoyages individuels ───────────────────────────────────────

	private function ajax_check() {
		check_ajax_referer( 'pm_db_cleanup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
	}

	public function ajax_cleanup_action_scheduler() {
		$this->ajax_check();
		wp_send_json_success( array( 'message' => sprintf( '%d actions supprimées', $this->cleanup_action_scheduler() ) ) );
	}
	public function ajax_cleanup_orphan_postmeta() {
		$this->ajax_check();
		$c = $this->cleanup_orphan_postmeta(); $this->log_cleanup( 'orphan_postmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_duplicated_postmeta() {
		$this->ajax_check();
		$c = $this->cleanup_duplicated_postmeta(); $this->log_cleanup( 'duplicated_postmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_oembed_postmeta() {
		$this->ajax_check();
		$c = $this->cleanup_oembed_postmeta(); $this->log_cleanup( 'oembed_postmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_orphan_commentmeta() {
		$this->ajax_check();
		$c = $this->cleanup_orphan_commentmeta(); $this->log_cleanup( 'orphan_commentmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_duplicated_commentmeta() {
		$this->ajax_check();
		$c = $this->cleanup_duplicated_commentmeta(); $this->log_cleanup( 'duplicated_commentmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_orphan_termmeta() {
		$this->ajax_check();
		$c = $this->cleanup_orphan_termmeta(); $this->log_cleanup( 'orphan_termmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_duplicated_termmeta() {
		$this->ajax_check();
		$c = $this->cleanup_duplicated_termmeta(); $this->log_cleanup( 'duplicated_termmeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_orphan_term_relationships() {
		$this->ajax_check();
		$c = $this->cleanup_orphan_term_relationships(); $this->log_cleanup( 'orphan_term_relationships', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_orphan_usermeta() {
		$this->ajax_check();
		$c = $this->cleanup_orphan_usermeta(); $this->log_cleanup( 'orphan_usermeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_duplicated_usermeta() {
		$this->ajax_check();
		$c = $this->cleanup_duplicated_usermeta(); $this->log_cleanup( 'duplicated_usermeta', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_orphaned_variations() {
		$this->ajax_check();
		$c = $this->cleanup_orphaned_variations(); $this->log_cleanup( 'orphaned_variations', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d éléments supprimés', $c ) ) );
	}
	public function ajax_cleanup_trashed_comments() {
		$this->ajax_check();
		$c = $this->cleanup_trashed_comments(); $this->log_cleanup( 'trashed_comments', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d commentaires supprimés', $c ) ) );
	}
	public function ajax_cleanup_expired_transients() {
		$this->ajax_check();
		$c = $this->cleanup_expired_transients(); $this->log_cleanup( 'expired_transients', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d transients supprimés', $c ) ) );
	}
	public function ajax_cleanup_orphan_transient_timeouts() {
		$this->ajax_check();
		$c = $this->cleanup_orphan_transient_timeouts(); $this->log_cleanup( 'orphan_transient_timeouts', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d timeout(s) orphelin(s) supprimé(s)', $c ) ) );
	}
	public function ajax_cleanup_dirsize_cache() {
		$this->ajax_check();
		$c = $this->cleanup_dirsize_cache(); $this->log_cleanup( 'dirsize_cache', $c, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d entrée(s) dirsize_cache supprimée(s)', $c ) ) );
	}
	public function ajax_cleanup_all() {
		$this->ajax_check();
		$total = $this->cleanup_action_scheduler() + $this->cleanup_database() + $this->cleanup_monthly();
		wp_send_json_success( array( 'message' => sprintf( 'Total : %d éléments nettoyés', $total ) ) );
	}

	// ─── AJAX — Métadonnées (postmeta) ───────────────────────────────────────

	public function ajax_get_meta_keys() {
		$this->ajax_check();
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

	public function ajax_delete_custom_fields() {
		$this->ajax_check();
		global $wpdb;
		$type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'post';
		$keys = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) $_POST['keys'] ) : array();
		if ( empty( $keys ) ) { wp_send_json_error( array( 'message' => 'Aucune clé sélectionnée.' ) ); }
		if ( count( $keys ) > 50 ) { wp_send_json_error( array( 'message' => 'Maximum 50 clés par opération.' ) ); }

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
			if ( $kt > 0 ) { $details[] = $key . ' : ' . $kt; $total += $kt; }
		}
		if ( $total > 0 ) {
			$this->log_cleanup( 'custom_fields', $total, 0, 'MANUEL' );
			$upload_dir = wp_upload_dir();
			$log_file   = is_multisite() ? $upload_dir['basedir'] . '/pm-db-cleaner-site-' . get_current_blog_id() . '.txt' : $upload_dir['basedir'] . '/pm-db-cleaner.txt';
			file_put_contents( $log_file, '[' . current_time( 'Y-m-d H:i:s' ) . '] DETAIL | ' . implode( ' | ', $details ) . "\n", FILE_APPEND | LOCK_EX );
		}
		wp_send_json_success( array(
			'message' => $total > 0
				? sprintf( '%d entrées supprimées (%d clé(s))', $total, count( $details ) )
				: 'Aucune entrée trouvée pour les clés sélectionnées.',
		) );
	}

	// ─── AJAX — WP Options table complète ────────────────────────────────────

	public function ajax_get_option_keys() {
		$this->ajax_check();
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

	public function ajax_delete_options() {
		$this->ajax_check();
		global $wpdb;
		$keys = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) $_POST['keys'] ) : array();
		if ( empty( $keys ) ) { wp_send_json_error( array( 'message' => 'Aucune option sélectionnée.' ) ); }
		if ( count( $keys ) > 50 ) { wp_send_json_error( array( 'message' => 'Maximum 50 options par opération.' ) ); }

		$deleted = 0; $details = array();
		foreach ( $keys as $key ) {
			$result = $wpdb->delete( $wpdb->options, array( 'option_name' => $key ), array( '%s' ) );
			if ( $result ) { $deleted++; $details[] = $key; }
		}
		if ( $deleted > 0 ) { $this->log_cleanup( 'wp_options', $deleted, 0, 'MANUEL' ); }
		wp_send_json_success( array(
			'message' => $deleted > 0
				? sprintf( '%d option(s) supprimée(s) : %s', $deleted, implode( ', ', $details ) )
				: 'Aucune option trouvée.',
		) );
	}

	// ─── AJAX — Autoload ─────────────────────────────────────────────────────

	public function ajax_analyze_autoload() {
		$this->ajax_check();
		global $wpdb;
		$al_values  = "'yes','on','1','true','auto','auto-on','auto-update'";
		$size_bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM $wpdb->options WHERE autoload IN ($al_values)" );
		$count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options WHERE autoload IN ($al_values)" );
		$top10_raw  = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size_bytes, autoload FROM $wpdb->options WHERE autoload IN ($al_values) ORDER BY size_bytes DESC LIMIT 10" );
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
		if ( $size_kb < 300 )      { $status = 'Normal';       $color = '#1e8c3b'; $bg = '#edfaef'; }
		elseif ( $size_kb < 800 )  { $status = 'À surveiller'; $color = '#856404'; $bg = '#fff3cd'; }
		else                       { $status = 'Surchargé';    $color = '#dc3232'; $bg = '#fef0f0'; }
		wp_send_json_success( array( 'size_kb' => $size_kb, 'size_label' => $size_label, 'count' => number_format_i18n( $count ), 'top10' => $top10, 'status' => $status, 'status_color' => $color, 'status_bg' => $bg ) );
	}

	public function ajax_disable_autoload() {
		$this->ajax_check();
		global $wpdb;
		$names = isset( $_POST['option_names'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $_POST['option_names'] ) ) : array();
		if ( empty( $names ) ) { wp_send_json_error( array( 'message' => 'Aucune option sélectionnée.' ) ); }
		if ( count( $names ) > 15 ) { wp_send_json_error( array( 'message' => 'Maximum 15 options par opération.' ) ); }
		$updated = 0; $details = array();
		foreach ( $names as $name ) {
			$r = $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
			if ( $r !== false ) { $updated++; $details[] = $name; }
		}
		if ( $updated > 0 ) { $this->log_cleanup( 'wp_options_autoload', $updated, 0, 'MANUEL' ); }
		wp_send_json_success( array( 'message' => sprintf( 'Autoload désactivé pour %d option(s) : %s', $updated, implode( ', ', $details ) ) ) );
	}

	// ─── AJAX — Overhead BDD ─────────────────────────────────────────────────

	public function ajax_cleanup_db_overhead() {
		$this->ajax_check();
		global $wpdb;
		$tables = $wpdb->get_results( $wpdb->prepare( "SELECT table_name, Data_free FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s AND Data_free > 0 ORDER BY Data_free DESC", $wpdb->prefix . '%' ) );
		if ( empty( $tables ) ) { wp_send_json_success( array( 'message' => 'Aucun overhead détecté, vos tables sont déjà optimisées.' ) ); }
		$optimized = 0; $freed = 0;
		foreach ( $tables as $t ) {
			$freed += (int) $t->Data_free;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $t->table_name ) . '`' );
			$optimized++;
		}
		$this->log_cleanup( 'db_overhead', $optimized, 0, 'MANUEL' );
		wp_send_json_success( array( 'message' => sprintf( '%d table(s) optimisée(s) — %s récupérés', $optimized, round( $freed / 1024 / 1024, 1 ) . ' MB' ) ) );
	}

	// ─── AJAX — Cron orphelines ───────────────────────────────────────────────

	/**
	 * Détecte les hooks planifiés sans callback enregistré.
	 * Appelé depuis admin_page() uniquement — pas depuis admin-ajax —
	 * pour que admin_menu ait bien été déclenché (évite les faux positifs).
	 */
	private function get_cron_orphans() {
		$cron = _get_cron_array();
		if ( empty( $cron ) ) { return array(); }
		$core = array( 'wp_version_check','wp_update_plugins','wp_update_themes','wp_scheduled_delete','wp_scheduled_auto_draft_delete','delete_expired_transients','wp_privacy_delete_old_export_files','recovery_mode_clean_expired_keys','wp_site_health_scheduled_check','do_pings','wp_update_user_counts','wp_https_detection','jetpack_clean_nonces','wp_delete_temp_updater_backups' );
		$orphans = array();
		foreach ( $cron as $ts => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( in_array( $hook, $core, true ) || has_action( $hook ) ) { continue; }
				foreach ( $events as $key => $event ) {
					$orphans[] = array(
						'hook'       => $hook,
						'key'        => $key,
						'timestamp'  => $ts,
						'next_run'   => $this->format_cron_time( $ts ),
						'recurrence' => $event['schedule'] ? ( wp_get_schedules()[ $event['schedule'] ]['display'] ?? $event['schedule'] ) : 'Ponctuel',
						'args'       => $event['args'],
					);
				}
			}
		}
		return $orphans;
	}

	public function ajax_delete_cron_orphans() {
		$this->ajax_check();
		if ( empty( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) { wp_send_json_error( array( 'message' => 'Confirmation requise.' ) ); }
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
		if ( $deleted > 0 ) { $this->log_cleanup( 'cron_orphans', $deleted, 0, 'MANUEL' ); }
		wp_send_json_success( array( 'message' => sprintf( '%d tâche(s) cron orpheline(s) supprimée(s).', $deleted ) ) );
	}

	// ─── AJAX — Désinstallation manuelle ─────────────────────────────────────

	public function ajax_uninstall_cron() {
		$this->ajax_check();
		if ( empty( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) { wp_send_json_error( array( 'message' => 'Confirmation requise.' ) ); }
		foreach ( array( 'pm_cleanup_action_scheduler_daily', 'pm_cleanup_database_weekly', 'pm_cleanup_monthly' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_send_json_success( array( 'message' => 'Tâches cron de PM DB Cleaner supprimées. Vous pouvez maintenant supprimer le fichier en toute sécurité.' ) );
	}

}

// ─── Initialisation ───────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( 'PM_DB_Cleaner', 'get_instance' ) );
