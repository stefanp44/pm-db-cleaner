<?php
/**
 * PM DB Cleaner — Page admin
 * Rendu HTML, statistiques et enqueue des assets.
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Admin {

	public static function admin_menu() {
		add_management_page( 'PM DB Cleaner', 'PM DB Cleaner', 'manage_options', 'pm-db-cleaner', array( __CLASS__, 'render' ) );
	}

	public static function admin_scripts( $hook ) {
		if ( 'tools_page_pm-db-cleaner' !== $hook ) { return; }
		$base = plugin_dir_url( PM_DB_CLEANER_FILE ) . 'assets/';
		wp_enqueue_style( 'pm-db-cleaner', $base . 'admin.css', array(), PM_DB_CLEANER_VERSION );
		wp_enqueue_script( 'pm-db-cleaner', $base . 'admin.js', array( 'jquery' ), PM_DB_CLEANER_VERSION, true );
		wp_localize_script( 'pm-db-cleaner', 'pmDBCleaner', array(
			'nonce'      => wp_create_nonce( 'pm_db_cleanup' ),
			'processing' => 'Nettoyage...',
			'success'    => 'Terminé !',
			'error'      => 'Erreur',
		) );
	}

	// ─── Statistiques ─────────────────────────────────────────────────────────

	private static function get_stats() {
		global $wpdb;
		$s = array();

		$s['action_scheduler_old'] = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" )
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)", 7 ) )
			: 0;

		$s['orphan_postmeta']     = $wpdb->get_var( "SELECT COUNT(pm.meta_id) FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(meta_id) AS c FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_postmeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;
		$s['oembed_postmeta']     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(meta_id) FROM $wpdb->postmeta WHERE meta_key LIKE(%s)", '%_oembed_%' ) );

		$s['orphan_commentmeta']     = $wpdb->get_var( "SELECT COUNT(cm.meta_id) FROM $wpdb->commentmeta cm LEFT JOIN $wpdb->comments c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(meta_id) AS c FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_commentmeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;
		$s['trashed_comments']       = $wpdb->get_var( "SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = 'trash'" );

		$s['orphan_termmeta']     = $wpdb->get_var( "SELECT COUNT(tm.meta_id) FROM $wpdb->termmeta tm LEFT JOIN $wpdb->terms t ON tm.term_id = t.term_id WHERE t.term_id IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(meta_id) AS c FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_termmeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;
		$excl = PM_DB_Cleaner_Auto::get_excluded_taxonomies();
		$ph   = implode( ',', array_fill( 0, count( $excl ), '%s' ) );
		$s['orphan_term_relationships'] = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(object_id) FROM $wpdb->term_relationships AS tr
			INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy NOT IN ($ph)
			AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)",
			$excl
		) );

		$s['orphan_usermeta']     = $wpdb->get_var( "SELECT COUNT(um.umeta_id) FROM $wpdb->usermeta um LEFT JOIN $wpdb->users u ON um.user_id = u.ID WHERE u.ID IS NULL" );
		$q = $wpdb->get_col( $wpdb->prepare( "SELECT COUNT(umeta_id) AS c FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING c > %d", 1 ) );
		$s['duplicated_usermeta'] = is_array( $q ) ? array_sum( array_map( 'intval', $q ) ) : 0;

		$time = time();
		$s['expired_transients']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $time ) );
		$s['expired_transients'] += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_site_transient_timeout_' ) . '%', $time ) );

		$s['orphan_transient_timeouts']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options t WHERE t.option_name LIKE '_transient_timeout_%' AND NOT EXISTS (SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub WHERE sub.option_name = REPLACE(t.option_name, '_transient_timeout_', '_transient_'))" );
		$s['orphan_transient_timeouts'] += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options t WHERE t.option_name LIKE '_site_transient_timeout_%' AND NOT EXISTS (SELECT 1 FROM (SELECT option_name FROM $wpdb->options) AS sub WHERE sub.option_name = REPLACE(t.option_name, '_site_transient_timeout_', '_site_transient_'))" );

		$s['dirsize_cache']     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'dirsize_cache' ) . '%' ) );
		$s['orphaned_variations'] = class_exists( 'WooCommerce' )
			? $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} products LEFT JOIN {$wpdb->posts} wp ON wp.ID = products.post_parent WHERE wp.ID IS NULL AND products.post_type = 'product_variation'" )
			: 0;
		$s['revisions']         = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'revision'" );

		$overhead = $wpdb->get_results( $wpdb->prepare( "SELECT SUM(Data_free) as overhead FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s AND Data_free > 0", $wpdb->prefix . '%' ) );
		$ob = isset( $overhead[0]->overhead ) ? (int) $overhead[0]->overhead : 0;
		$s['db_overhead_bytes'] = $ob >= 5 * 1024 * 1024 ? $ob : 0;
		$s['db_overhead_label'] = $s['db_overhead_bytes'] > 0 ? round( $s['db_overhead_bytes'] / 1024 / 1024, 1 ) . ' MB' : '0 MB';

		return $s;
	}

	// ─── Rendu HTML ───────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Vous n\'avez pas les permissions nécessaires.' ); }

		$stats        = self::get_stats();
		$cron_orphans = PM_DB_Cleaner_Cron_Orphans::get_orphans();
		$as_next      = wp_next_scheduled( 'pm_cleanup_action_scheduler_daily' );
		$db_next      = wp_next_scheduled( 'pm_cleanup_database_weekly' );
		$monthly_next = wp_next_scheduled( 'pm_cleanup_monthly' );

		$fmt = function( $ts ) { return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'd/m/Y à H:i' ); };
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
								<ul><li>Prochain : <strong><?php echo $as_next ? $fmt( $as_next ) : 'Non planifié'; ?></strong></li></ul>
							</li>
							<li><strong>Base de données :</strong> Nettoyage hebdomadaire — Posts, Commentaires, Termes &amp; Taxonomies<?php echo is_multisite() ? '' : ', Utilisateurs'; ?>, WooCommerce
								<ul><li>Prochain : <strong><?php echo $db_next ? $fmt( $db_next ) : 'Non planifié'; ?></strong></li></ul>
							</li>
							<li><strong>Commentaires + Transients :</strong> Nettoyage mensuel
								<ul><li>Prochain : <strong><?php echo $monthly_next ? $fmt( $monthly_next ) : 'Non planifié'; ?></strong></li></ul>
							</li>
						</ul>
						<p class="pm-pane-desc pm-pane-desc--top">
							<strong>Non automatisé — manuel uniquement :</strong> Métadonnées (postmeta), WP Options, Autoload, Overhead BDD.
						</p>
						<?php if ( is_multisite() ) : ?>
						<p class="pm-notice pm-notice--warning">
							<strong>ℹ️ Multisite :</strong> Les métadonnées utilisateurs ne sont pas nettoyées (table partagée).
						</p>
						<?php endif; ?>
						<?php
						$recent_logs = PM_DB_Cleaner_Logger::get_recent( 20 );
						if ( $recent_logs ) :
						?>
						<div class="pm-log-block">
							<strong class="pm-log-title">📋 Historique (<?php echo count( $recent_logs ); ?> derniers)</strong>
							<div class="pm-log-lines">
								<?php foreach ( $recent_logs as $line ) :
									$color = strpos( $line, 'MANUEL' ) !== false ? '#B11F8F' : '#1e8c3b';
									echo '<div style="color:' . $color . '">' . esc_html( $line ) . '</div>';
								endforeach; ?>
							</div>
							<p class="pm-log-dl"><a href="<?php echo esc_url( PM_DB_Cleaner_Logger::get_log_url() ); ?>" download class="pm-log-dl-link">📥 Télécharger le fichier complet</a></p>
						</div>
						<?php endif; ?>
						<div class="pm-cleanup-all-wrap">
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

			<!-- ── Milieu : tableau nettoyage (2/3) + colonne outils (1/3) ── -->
			<div class="pm-main-grid">

				<!-- Tableau de nettoyage -->
				<div class="pm-settings-section pm-settings-section--flush">
					<table class="pm-cleanup-table">
						<tbody>
							<tr class="pm-category-row"><td colspan="3"><strong>Posts</strong></td></tr>
							<?php self::cleanup_row( 'Métadonnées orphelines', $stats['orphan_postmeta'], 'pm_cleanup_orphan_postmeta' ); ?>
							<?php self::cleanup_row( 'Métadonnées dupliquées', $stats['duplicated_postmeta'], 'pm_cleanup_duplicated_postmeta' ); ?>
							<?php self::cleanup_row( 'Caches oEmbed', $stats['oembed_postmeta'], 'pm_cleanup_oembed_postmeta' ); ?>

							<tr class="pm-category-row"><td colspan="3"><strong>Commentaires</strong></td></tr>
							<?php self::cleanup_row( 'Métadonnées orphelines', $stats['orphan_commentmeta'], 'pm_cleanup_orphan_commentmeta' ); ?>
							<?php self::cleanup_row( 'Métadonnées dupliquées', $stats['duplicated_commentmeta'], 'pm_cleanup_duplicated_commentmeta' ); ?>
							<?php self::cleanup_row( 'Commentaires en corbeille', $stats['trashed_comments'], 'pm_cleanup_trashed_comments' ); ?>

							<tr class="pm-category-row"><td colspan="3"><strong>Termes & Taxonomies</strong></td></tr>
							<?php self::cleanup_row( 'Métadonnées orphelines', $stats['orphan_termmeta'], 'pm_cleanup_orphan_termmeta' ); ?>
							<?php self::cleanup_row( 'Métadonnées dupliquées', $stats['duplicated_termmeta'], 'pm_cleanup_duplicated_termmeta' ); ?>
							<?php self::cleanup_row( 'Relations orphelines', $stats['orphan_term_relationships'], 'pm_cleanup_orphan_term_relationships' ); ?>

							<?php if ( ! is_multisite() ) : ?>
							<tr class="pm-category-row"><td colspan="3"><strong>Utilisateurs</strong></td></tr>
							<?php self::cleanup_row( 'Métadonnées orphelines', $stats['orphan_usermeta'], 'pm_cleanup_orphan_usermeta' ); ?>
							<?php self::cleanup_row( 'Métadonnées dupliquées', $stats['duplicated_usermeta'], 'pm_cleanup_duplicated_usermeta' ); ?>
							<?php endif; ?>

							<tr class="pm-category-row"><td colspan="3"><strong>Système</strong></td></tr>
							<?php self::cleanup_row( 'Action Scheduler (actions de +7 jours)', $stats['action_scheduler_old'], 'pm_cleanup_action_scheduler' ); ?>
							<?php self::cleanup_row( 'Transients expirés', $stats['expired_transients'], 'pm_cleanup_expired_transients' ); ?>
							<?php self::cleanup_row( 'Transients — timeouts orphelins (timeout sans transient correspondant)', $stats['orphan_transient_timeouts'], 'pm_cleanup_orphan_transient_timeouts' ); ?>

							<?php if ( class_exists( 'WooCommerce' ) && $stats['orphaned_variations'] > 0 ) : ?>
							<tr class="pm-category-row"><td colspan="3"><strong>WooCommerce</strong></td></tr>
							<?php self::cleanup_row( 'Variations orphelines', $stats['orphaned_variations'], 'pm_cleanup_orphaned_variations' ); ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Colonne 1/3 -->
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
										<span class="pm-item-desc">Limitez via <code>define('WP_POST_REVISIONS', 10);</code> dans <code>wp-config.php</code>.<br>
										<?php
										$rev = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : null;
										if ( $rev === null ) {
											echo '<span style="color:#856404">⚠️ Illimité — constante absente de wp-config.php</span>';
										} elseif ( $rev === false || $rev === 0 ) {
											echo '<span style="color:#1e8c3b">✅ Révisions désactivées</span>';
										} else {
											$n = (int) $rev; $lbl = $n === 1 ? 'révision' : 'révisions';
											$col  = $n <= 15 ? '#1e8c3b' : ( $n <= 30 ? '#856404' : '#dc3232' );
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

				<!-- WP Options -->
				<div class="pm-pane">
					<div class="pm-pane-title">
						WP Options
						<button id="pm-wpo-analyze" class="pm-btn" style="float:right">Analyser</button>
					</div>
					<p class="pm-notice pm-notice--danger">
						<strong>⚠️ Zone à risque — à utiliser à vos risques et périls.</strong><br>
						La table <code>wp_options</code> contient des données critiques pour le fonctionnement de WordPress et de vos plugins. Supprimer une option incorrecte peut casser le site. N'agissez ici que si vous savez exactement ce que vous supprimez et après avoir effectué une sauvegarde.
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
					<p class="pm-pane-desc">
						Hooks planifiés sans callback enregistré (<code>has_action()</code> vide) — résidus d'un plugin désinstallé. Détection au chargement de la page (comme WP Crontrol) pour éviter les faux positifs.
					</p>
					<div id="pm-cron-results" style="display:none;margin-top:12px">
						<?php if ( empty( $cron_orphans ) ) : ?>
							<p class="pm-pane-desc">Aucune tâche cron orpheline détectée.</p>
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
					<div class="pm-pane-title pm-pane-title--danger">Supprimer le plugin via SFTP/SSH ?</div>
					<p class="pm-pane-desc">
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

	/**
	 * Génère une ligne du tableau de nettoyage.
	 */
	private static function cleanup_row( $label, $count, $action ) {
		$zero = $count == 0 ? ' zero' : '';
		echo '<tr>';
		echo '<td class="pm-cleanup-item">' . esc_html( $label ) . '</td>';
		echo '<td style="text-align:center;width:80px"><span class="pm-count' . $zero . '">' . number_format_i18n( $count ) . '</span></td>';
		echo '<td style="text-align:center;width:100px"><button class="pm-btn pm-cleanup-btn" data-action="' . esc_attr( $action ) . '"' . ( $count == 0 ? ' disabled' : '' ) . '>Nettoyer</button></td>';
		echo '</tr>';
	}

}
