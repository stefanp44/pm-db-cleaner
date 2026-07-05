<?php
/**
 * PM DB Cleaner — Admin page
 * HTML rendering, statistics and asset enqueueing.
 *
 * @package PM_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) { die( '-1' ); }

class PM_DB_Cleaner_Admin {

	public static function admin_menu() {
		add_management_page(
			__( 'PM DB Cleaner', 'pm-db-cleaner' ),
			__( 'PM DB Cleaner', 'pm-db-cleaner' ),
			'manage_options',
			'pm-db-cleaner',
			array( __CLASS__, 'render' )
		);
	}

	public static function admin_scripts( $hook ) {
		if ( 'tools_page_pm-db-cleaner' !== $hook ) { return; }
		$base = plugin_dir_url( PM_DB_CLEANER_FILE ) . 'assets/';
		wp_enqueue_style( 'pm-db-cleaner', $base . 'admin.css', array(), PM_DB_CLEANER_VERSION );
		wp_enqueue_script( 'pm-db-cleaner', $base . 'admin.js', array( 'jquery' ), PM_DB_CLEANER_VERSION, true );
		wp_localize_script( 'pm-db-cleaner', 'pmDBCleaner', array(
			'nonce'      => wp_create_nonce( 'pm_db_cleanup' ),
			'processing' => __( 'Cleaning…', 'pm-db-cleaner' ),
			'success'    => __( 'Done!', 'pm-db-cleaner' ),
			'error'        => __( 'Error', 'pm-db-cleaner' ),
			'confirmAll'   => __( 'Clean all items? This may take a while.', 'pm-db-cleaner' ),
			'noCronOrphans'=> __( 'No orphan cron tasks detected.', 'pm-db-cleaner' ),
			'btnClean'     => __( 'Clean', 'pm-db-cleaner' ),
		) );
	}

	// ─── Statistics ───────────────────────────────────────────────────────────

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

		$s['dirsize_cache']       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'dirsize_cache' ) . '%' ) );
		$s['orphaned_variations'] = class_exists( 'WooCommerce' )
			? $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} products LEFT JOIN {$wpdb->posts} wp ON wp.ID = products.post_parent WHERE wp.ID IS NULL AND products.post_type = 'product_variation'" )
			: 0;
		$s['revisions']           = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'revision'" );

		$overhead = $wpdb->get_results( $wpdb->prepare( "SELECT SUM(Data_free) as overhead FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s AND Data_free > 0", $wpdb->prefix . '%' ) );
		$ob = isset( $overhead[0]->overhead ) ? (int) $overhead[0]->overhead : 0;
		$s['db_overhead_bytes'] = $ob >= 5 * 1024 * 1024 ? $ob : 0;
		$s['db_overhead_label'] = $s['db_overhead_bytes'] > 0 ? round( $s['db_overhead_bytes'] / 1024 / 1024, 1 ) . ' MB' : '0 MB';

		return $s;
	}

	// ─── Render ───────────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'pm-db-cleaner' ) );
		}

		$stats        = self::get_stats();
		$cron_orphans = PM_DB_Cleaner_Cron_Orphans::get_orphans();
		$as_next      = wp_next_scheduled( 'pm_cleanup_action_scheduler_daily' );
		$db_next      = wp_next_scheduled( 'pm_cleanup_database_weekly' );
		$monthly_next = wp_next_scheduled( 'pm_cleanup_monthly' );
		$fmt          = function( $ts ) { return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'd/m/Y \a\t H:i' ); };
		?>
		<div class="wrap pm-db-cleaner-wrap">
			<h1><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'PM DB Cleaner', 'pm-db-cleaner' ); ?></h1>

			<!-- ── Header: scheduled cleanup + branding ── -->
			<div class="pm-header-grid">
				<details class="pm-collapsible pm-collapsible-success">
					<summary>
						<span class="dashicons dashicons-clock"></span>
						<strong><?php esc_html_e( 'Scheduled automatic cleanup', 'pm-db-cleaner' ); ?></strong>
					</summary>
					<div class="pm-collapsible-content">
						<ul>
							<li><strong><?php esc_html_e( 'Action Scheduler:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'Daily cleanup — removes completed, failed or cancelled tasks older than 7 days (and their logs)', 'pm-db-cleaner' ); ?>
								<ul><li><?php esc_html_e( 'Next:', 'pm-db-cleaner' ); ?> <strong><?php echo $as_next ? esc_html( $fmt( $as_next ) ) : esc_html__( 'Not scheduled', 'pm-db-cleaner' ); ?></strong></li></ul>
							</li>
							<li><strong><?php esc_html_e( 'Database:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'Weekly cleanup — Posts, Comments, Terms & Taxonomies', 'pm-db-cleaner' ); ?><?php echo is_multisite() ? '' : esc_html__( ', Users', 'pm-db-cleaner' ); ?><?php esc_html_e( ', WooCommerce', 'pm-db-cleaner' ); ?>
								<ul><li><?php esc_html_e( 'Next:', 'pm-db-cleaner' ); ?> <strong><?php echo $db_next ? esc_html( $fmt( $db_next ) ) : esc_html__( 'Not scheduled', 'pm-db-cleaner' ); ?></strong></li></ul>
							</li>
							<li><strong><?php esc_html_e( 'Comments + Transients:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'Monthly cleanup', 'pm-db-cleaner' ); ?>
								<ul><li><?php esc_html_e( 'Next:', 'pm-db-cleaner' ); ?> <strong><?php echo $monthly_next ? esc_html( $fmt( $monthly_next ) ) : esc_html__( 'Not scheduled', 'pm-db-cleaner' ); ?></strong></li></ul>
							</li>
						</ul>
						<p class="pm-pane-desc pm-pane-desc--top">
							<strong><?php esc_html_e( 'Not automated — manual only:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'Metadata (postmeta), WP Options, Autoload, DB Overhead.', 'pm-db-cleaner' ); ?>
						</p>
						<?php if ( is_multisite() ) : ?>
						<p class="pm-notice pm-notice--warning">
							<strong><?php esc_html_e( 'ℹ️ Multisite:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'User metadata is not cleaned (shared table across all sites).', 'pm-db-cleaner' ); ?>
						</p>
						<?php endif; ?>
						<?php
						$recent_logs = PM_DB_Cleaner_Logger::get_recent( 20 );
						if ( $recent_logs ) :
						?>
						<div class="pm-log-block">
							<strong class="pm-log-title">📋 <?php printf( esc_html__( 'History (%d most recent)', 'pm-db-cleaner' ), count( $recent_logs ) ); ?></strong>
							<div class="pm-log-lines">
								<?php foreach ( $recent_logs as $line ) :
									$color = strpos( $line, 'MANUAL' ) !== false ? '#B11F8F' : '#1e8c3b';
									echo '<div style="color:' . esc_attr( $color ) . '">' . esc_html( $line ) . '</div>';
								endforeach; ?>
							</div>
							<p class="pm-log-dl"><a href="<?php echo esc_url( PM_DB_Cleaner_Logger::get_log_url() ); ?>" download class="pm-log-dl-link">📥 <?php esc_html_e( 'Download full log file', 'pm-db-cleaner' ); ?></a></p>
						</div>
						<?php endif; ?>

					</div>
				</details>
				<div class="pm-header-branding">
					<div class="pm-author-compact">
						<?php esc_html_e( 'Developed by', 'pm-db-cleaner' ); ?> <a href="https://perspectives.marketing" target="_blank"><strong>Perspectives Marketing</strong></a>
					</div>
				</div>
			</div>

			<!-- ── Middle: cleanup table (2/3) + tools column (1/3) ── -->
			<div class="pm-main-grid">

				<!-- Cleanup table -->
				<div class="pm-settings-section pm-settings-section--flush">
					<table class="pm-cleanup-table">
						<tbody>
							<tr class="pm-category-row"><td colspan="3"><strong><?php esc_html_e( 'Posts', 'pm-db-cleaner' ); ?></strong></td></tr>
							<?php self::cleanup_row( __( 'Orphaned metadata', 'pm-db-cleaner' ), $stats['orphan_postmeta'], 'pm_cleanup_orphan_postmeta' ); ?>
							<?php self::cleanup_row( __( 'Duplicated metadata', 'pm-db-cleaner' ), $stats['duplicated_postmeta'], 'pm_cleanup_duplicated_postmeta' ); ?>
							<?php self::cleanup_row( __( 'oEmbed caches', 'pm-db-cleaner' ), $stats['oembed_postmeta'], 'pm_cleanup_oembed_postmeta' ); ?>

							<tr class="pm-category-row"><td colspan="3"><strong><?php esc_html_e( 'Comments', 'pm-db-cleaner' ); ?></strong></td></tr>
							<?php self::cleanup_row( __( 'Orphaned metadata', 'pm-db-cleaner' ), $stats['orphan_commentmeta'], 'pm_cleanup_orphan_commentmeta' ); ?>
							<?php self::cleanup_row( __( 'Duplicated metadata', 'pm-db-cleaner' ), $stats['duplicated_commentmeta'], 'pm_cleanup_duplicated_commentmeta' ); ?>
							<?php self::cleanup_row( __( 'Trashed comments', 'pm-db-cleaner' ), $stats['trashed_comments'], 'pm_cleanup_trashed_comments' ); ?>

							<tr class="pm-category-row"><td colspan="3"><strong><?php esc_html_e( 'Terms & Taxonomies', 'pm-db-cleaner' ); ?></strong></td></tr>
							<?php self::cleanup_row( __( 'Orphaned metadata', 'pm-db-cleaner' ), $stats['orphan_termmeta'], 'pm_cleanup_orphan_termmeta' ); ?>
							<?php self::cleanup_row( __( 'Duplicated metadata', 'pm-db-cleaner' ), $stats['duplicated_termmeta'], 'pm_cleanup_duplicated_termmeta' ); ?>
							<?php self::cleanup_row( __( 'Orphaned relationships', 'pm-db-cleaner' ), $stats['orphan_term_relationships'], 'pm_cleanup_orphan_term_relationships' ); ?>

							<?php if ( ! is_multisite() ) : ?>
							<tr class="pm-category-row"><td colspan="3"><strong><?php esc_html_e( 'Users', 'pm-db-cleaner' ); ?></strong></td></tr>
							<?php self::cleanup_row( __( 'Orphaned metadata', 'pm-db-cleaner' ), $stats['orphan_usermeta'], 'pm_cleanup_orphan_usermeta' ); ?>
							<?php self::cleanup_row( __( 'Duplicated metadata', 'pm-db-cleaner' ), $stats['duplicated_usermeta'], 'pm_cleanup_duplicated_usermeta' ); ?>
							<?php endif; ?>

							<tr class="pm-category-row"><td colspan="3"><strong><?php esc_html_e( 'System', 'pm-db-cleaner' ); ?></strong></td></tr>
							<?php self::cleanup_row( __( 'Action Scheduler (tasks older than 7 days)', 'pm-db-cleaner' ), $stats['action_scheduler_old'], 'pm_cleanup_action_scheduler' ); ?>
							<?php self::cleanup_row( __( 'Expired transients', 'pm-db-cleaner' ), $stats['expired_transients'], 'pm_cleanup_expired_transients' ); ?>
							<?php self::cleanup_row( __( 'Transients — orphaned timeouts (timeout with no matching transient)', 'pm-db-cleaner' ), $stats['orphan_transient_timeouts'], 'pm_cleanup_orphan_transient_timeouts' ); ?>

							<?php if ( class_exists( 'WooCommerce' ) && $stats['orphaned_variations'] > 0 ) : ?>
							<tr class="pm-category-row"><td colspan="3"><strong><?php esc_html_e( 'WooCommerce', 'pm-db-cleaner' ); ?></strong></td></tr>
							<?php self::cleanup_row( __( 'Orphaned variations', 'pm-db-cleaner' ), $stats['orphaned_variations'], 'pm_cleanup_orphaned_variations' ); ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- 1/3 column -->
				<div>

					<!-- Autoload -->
					<div class="pm-pane">
						<div class="pm-pane-title">
							<?php esc_html_e( 'Autoload (wp_options)', 'pm-db-cleaner' ); ?>
							<button id="pm-options-analyze" class="pm-btn" style="float:right"><?php esc_html_e( 'Analyze', 'pm-db-cleaner' ); ?></button>
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
											<span><strong>⚠️ <?php esc_html_e( 'Confirmation:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'Disabling autoload changes how these options are loaded. Reversible, but may affect performance.', 'pm-db-cleaner' ); ?></span>
										</label>
									</div>
									<button id="pm-autoload-disable" class="pm-btn pm-btn-autoload" disabled><?php esc_html_e( 'Disable autoload for selection', 'pm-db-cleaner' ); ?></button>
								</div>
							</div>
						</div>
					</div>

					<!-- Manual cleanups -->
					<div class="pm-pane pm-pane--top">
						<div class="pm-pane-title"><?php esc_html_e( 'Manual cleanups', 'pm-db-cleaner' ); ?></div>
						<table class="pm-cleanup-table">
							<tbody>
								<tr>
									<td class="pm-cleanup-item">
										<?php esc_html_e( 'Dirsize Cache', 'pm-db-cleaner' ); ?><br>
										<span class="pm-item-desc"><?php esc_html_e( 'Cache of the uploads folder sizes. Rebuilt automatically on the next visit to the media library. Safe to delete.', 'pm-db-cleaner' ); ?></span>
									</td>
									<td style="text-align:center;width:60px"><span class="pm-count<?php echo $stats['dirsize_cache'] == 0 ? ' zero' : ''; ?>"><?php echo number_format_i18n( $stats['dirsize_cache'] ); ?></span></td>
									<td style="text-align:center;width:90px"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_dirsize_cache" <?php echo $stats['dirsize_cache'] == 0 ? 'disabled' : ''; ?>><?php esc_html_e( 'Clean', 'pm-db-cleaner' ); ?></button></td>
								</tr>
								<tr>
									<td class="pm-cleanup-item">
										<?php esc_html_e( 'Database overhead', 'pm-db-cleaner' ); ?><br>
										<span class="pm-item-desc"><?php esc_html_e( 'Wasted space after deletions (fragmented MySQL tables). Prefer running outside peak hours.', 'pm-db-cleaner' ); ?></span>
									</td>
									<td style="text-align:center"><span class="pm-count<?php echo $stats['db_overhead_bytes'] == 0 ? ' zero' : ''; ?>"><?php echo esc_html( $stats['db_overhead_label'] ); ?></span></td>
									<td style="text-align:center"><button class="pm-btn pm-cleanup-btn" data-action="pm_cleanup_db_overhead" <?php echo $stats['db_overhead_bytes'] == 0 ? 'disabled' : ''; ?>><?php esc_html_e( 'Clean', 'pm-db-cleaner' ); ?></button></td>
								</tr>
								<tr>
									<td class="pm-cleanup-item">
										<?php esc_html_e( 'Post revisions', 'pm-db-cleaner' ); ?><br>
										<span class="pm-item-desc"><?php esc_html_e( 'Limit future revisions via', 'pm-db-cleaner' ); ?> <code>define('WP_POST_REVISIONS', 10);</code> <?php esc_html_e( 'in', 'pm-db-cleaner' ); ?> <code>wp-config.php</code>.<br>
										<?php
										$rev = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : null;
										if ( $rev === null ) {
											echo '<span style="color:#856404">⚠️ ' . esc_html__( 'Unlimited — constant missing from wp-config.php', 'pm-db-cleaner' ) . '</span>';
										} elseif ( $rev === false || $rev === 0 ) {
											echo '<span style="color:#1e8c3b">✅ ' . esc_html__( 'Revisions disabled', 'pm-db-cleaner' ) . '</span>';
										} else {
											$n    = (int) $rev;
											$lbl  = $n === 1 ? __( 'revision', 'pm-db-cleaner' ) : __( 'revisions', 'pm-db-cleaner' );
											$col  = $n <= 15 ? '#1e8c3b' : ( $n <= 30 ? '#856404' : '#dc3232' );
											$icon = $n <= 15 ? '✅' : ( $n <= 30 ? '⚠️' : '🔴' );
											// translators: %1$s: icon, %2$d: number, %3$s: revision(s)
											echo '<span style="color:' . esc_attr( $col ) . '">' . $icon . ' ' . sprintf( esc_html__( 'Limited to %1$d %2$s per post', 'pm-db-cleaner' ), $n, esc_html( $lbl ) ) . '</span>';
										}
										?></span>
									</td>
									<td style="text-align:center"><span class="pm-info-count"><?php echo number_format_i18n( $stats['revisions'] ); ?></span></td>
									<td style="text-align:center"><span class="pm-readonly"><?php esc_html_e( 'Read only', 'pm-db-cleaner' ); ?></span></td>
								</tr>
							</tbody>
						</table>
					</div>

				</div><!-- 1/3 column -->

			</div><!-- .pm-main-grid -->

			<!-- ── Danger Zone ── -->
			<details class="pm-danger-zone">
				<summary>
					<span class="dashicons dashicons-warning"></span>
					<strong><?php esc_html_e( 'Danger Zone — manual operations', 'pm-db-cleaner' ); ?></strong>
				</summary>
				<div class="pm-danger-zone-content">
				<p class="pm-danger-zone-desc" style="padding-top:12px"><?php esc_html_e( 'Unlike the automatic cleanups above, the following operations rely on your own selection. A wrong action can damage the site irreversibly. Only proceed after making a full database backup and if you know exactly what you are deleting.', 'pm-db-cleaner' ); ?></p>

				<div class="pm-two-col">

					<!-- Metadata (postmeta) -->
					<div class="pm-pane">
						<div class="pm-pane-title">
							<?php esc_html_e( 'Metadata (postmeta)', 'pm-db-cleaner' ); ?>
							<button id="pm-cf-analyze" class="pm-btn" style="float:right"><?php esc_html_e( 'Analyze', 'pm-db-cleaner' ); ?></button>
						</div>
						<div class="pm-cf-wrap">
							<div class="pm-cf-radio-group">
								<label><input type="radio" name="pm_cf_type" value="post" checked> <?php esc_html_e( 'Posts', 'pm-db-cleaner' ); ?></label>
								<label><input type="radio" name="pm_cf_type" value="term"> <?php esc_html_e( 'Terms', 'pm-db-cleaner' ); ?></label>
								<label><input type="radio" name="pm_cf_type" value="user"> <?php esc_html_e( 'Users', 'pm-db-cleaner' ); ?></label>
								<label><input type="radio" name="pm_cf_type" value="all"> <?php esc_html_e( 'All', 'pm-db-cleaner' ); ?></label>
							</div>
							<div id="pm-cf-results" style="display:none">
								<input type="text" id="pm-cf-filter" class="pm-filter-input" placeholder="<?php esc_attr_e( 'Filter keys…', 'pm-db-cleaner' ); ?>">
								<div class="pm-keys-wrap"><div id="pm-cf-keys-list"></div></div>
								<div id="pm-cf-confirm-wrap" class="pm-confirm-box" style="display:none">
									<label>
										<input type="checkbox" id="pm-cf-confirm">
										<span><strong>⚠️ <?php esc_html_e( 'Mandatory confirmation:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'I have made a full database backup. Deletion is permanent and irreversible.', 'pm-db-cleaner' ); ?></span>
									</label>
								</div>
								<button id="pm-cf-delete" class="pm-btn pm-btn-danger" disabled><?php esc_html_e( 'Delete selection', 'pm-db-cleaner' ); ?></button>
							</div>
						</div>
					</div>

					<!-- WP Options -->
					<div class="pm-pane">
						<div class="pm-pane-title">
							<?php esc_html_e( 'WP Options', 'pm-db-cleaner' ); ?>
							<button id="pm-wpo-analyze" class="pm-btn" style="float:right"><?php esc_html_e( 'Analyze', 'pm-db-cleaner' ); ?></button>
						</div>
						<div id="pm-wpo-results" style="display:none">
							<input type="text" id="pm-wpo-filter" class="pm-filter-input" placeholder="<?php esc_attr_e( 'Filter options…', 'pm-db-cleaner' ); ?>">
							<div class="pm-keys-wrap"><div id="pm-wpo-keys-list"></div></div>
							<div id="pm-wpo-confirm-wrap" class="pm-confirm-box pm-confirm-box-danger" style="display:none">
								<label>
									<input type="checkbox" id="pm-wpo-confirm">
									<span><strong>⚠️ <?php esc_html_e( 'Mandatory confirmation:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'I have made a full database backup. I know exactly what I am deleting and accept the risks. Deletion is permanent and irreversible.', 'pm-db-cleaner' ); ?></span>
								</label>
							</div>
							<button id="pm-wpo-delete" class="pm-btn pm-btn-danger" disabled><?php esc_html_e( 'Delete selection', 'pm-db-cleaner' ); ?></button>
						</div>
					</div>

				</div><!-- .pm-two-col -->

				<div class="pm-two-col">

					<!-- Orphan cron tasks -->
					<div class="pm-pane">
						<div class="pm-pane-title">
							<?php esc_html_e( 'Orphan cron tasks', 'pm-db-cleaner' ); ?>
							<button id="pm-cron-toggle" class="pm-btn" style="float:right">
								<?php printf( esc_html__( '%d detected — show', 'pm-db-cleaner' ), count( $cron_orphans ) ); ?>
							</button>
						</div>
						<p class="pm-pane-desc">
							<?php esc_html_e( 'Scheduled hooks with no registered callback (has_action() empty) — leftovers from an uninstalled plugin. Detection runs at page load (like WP Crontrol) to avoid false positives.', 'pm-db-cleaner' ); ?>
						</p>
						<div id="pm-cron-results" style="display:none;margin-top:12px">
							<?php if ( empty( $cron_orphans ) ) : ?>
								<p class="pm-pane-desc"><?php esc_html_e( 'No orphan cron tasks detected.', 'pm-db-cleaner' ); ?></p>
							<?php else : ?>
								<div class="pm-keys-wrap">
									<div id="pm-cron-list">
										<?php foreach ( $cron_orphans as $o ) : ?>
										<label class="pm-key-label">
											<input type="checkbox" class="pm-cron-key" data-hook="<?php echo esc_attr( $o['hook'] ); ?>" data-timestamp="<?php echo esc_attr( $o['timestamp'] ); ?>">
											<span><?php echo esc_html( $o['hook'] ); ?></span>
											<span class="pm-key-size"><?php echo esc_html( $o['recurrence'] ); ?> — <?php esc_html_e( 'next:', 'pm-db-cleaner' ); ?> <?php echo esc_html( $o['next_run'] ); ?></span>
										</label>
										<?php endforeach; ?>
									</div>
								</div>
								<div id="pm-cron-confirm-wrap" class="pm-confirm-box" style="display:none">
									<label>
										<input type="checkbox" id="pm-cron-confirm">
										<span><strong>⚠️ <?php esc_html_e( 'Mandatory confirmation:', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'I know that the plugin(s) behind these tasks are no longer used on this site.', 'pm-db-cleaner' ); ?></span>
									</label>
								</div>
								<button id="pm-cron-delete" class="pm-btn pm-btn-danger" disabled><?php esc_html_e( 'Delete selection', 'pm-db-cleaner' ); ?></button>
							<?php endif; ?>
						</div>
					</div>

					<!-- Manual uninstall SFTP/SSH -->
					<div class="pm-pane">
						<div class="pm-pane-title pm-pane-title--danger"><?php esc_html_e( 'Deleting the plugin via SFTP/SSH?', 'pm-db-cleaner' ); ?></div>
						<p class="pm-pane-desc">
							<strong><?php esc_html_e( 'Deactivating via Plugins > Deactivate', 'pm-db-cleaner' ); ?></strong> → <?php esc_html_e( 'the 3 cron tasks are removed automatically. Nothing to do here.', 'pm-db-cleaner' ); ?><br><br>
							<strong><?php esc_html_e( 'Deleting the file directly via SFTP/SSH', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'without deactivating through WordPress → click below', 'pm-db-cleaner' ); ?> <strong><?php esc_html_e( 'before', 'pm-db-cleaner' ); ?></strong> <?php esc_html_e( 'deleting the file to prevent these tasks remaining scheduled indefinitely.', 'pm-db-cleaner' ); ?>
						</p>
						<div id="pm-uninstall-confirm-wrap" class="pm-confirm-box" style="display:none">
							<label>
								<input type="checkbox" id="pm-uninstall-confirm">
								<span><?php esc_html_e( 'I am about to delete the plugin file via SFTP/SSH — remove its 3 scheduled cron tasks', 'pm-db-cleaner' ); ?> (<code>pm_cleanup_action_scheduler_daily</code>, <code>pm_cleanup_database_weekly</code>, <code>pm_cleanup_monthly</code>).</span>
							</label>
						</div>
						<button id="pm-uninstall-toggle" class="pm-btn"><?php esc_html_e( 'Prepare manual deletion', 'pm-db-cleaner' ); ?></button>
						<button id="pm-uninstall-confirm-btn" class="pm-btn pm-btn-danger" style="display:none" disabled><?php esc_html_e( 'Remove plugin cron tasks', 'pm-db-cleaner' ); ?></button>
					</div>

				</div><!-- .pm-two-col -->

				</div><!-- .pm-danger-zone-content -->
			</details><!-- .pm-danger-zone -->

		</div><!-- .pm-db-cleaner-wrap -->
		<?php
	}

	/**
	 * Outputs a cleanup table row.
	 */
	private static function cleanup_row( $label, $count, $action ) {
		$zero = $count == 0 ? ' zero' : '';
		echo '<tr>';
		echo '<td class="pm-cleanup-item">' . esc_html( $label ) . '</td>';
		echo '<td style="text-align:center;width:80px"><span class="pm-count' . esc_attr( $zero ) . '">' . number_format_i18n( $count ) . '</span></td>';
		echo '<td style="text-align:center;width:100px"><button class="pm-btn pm-cleanup-btn" data-action="' . esc_attr( $action ) . '"' . ( $count == 0 ? ' disabled' : '' ) . '>' . esc_html__( 'Clean', 'pm-db-cleaner' ) . '</button></td>';
		echo '</tr>';
	}

}
