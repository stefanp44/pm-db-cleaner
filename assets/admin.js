/* PM DB Cleaner — Admin JS */
jQuery(document).ready(function($) {

	// ─── Nettoyage individuel (boutons Nettoyer) ──────────────────────────

	$('.pm-cleanup-btn').on('click', function(e) {
		e.preventDefault();
		var btn = $(this);
		var action = btn.data('action');

		// Avertissement spécifique pour l'overhead BDD
		if (action === 'pm_cleanup_db_overhead') {
			var confirmed = confirm(
				'⚠️ Optimisation des tables MySQL (OPTIMIZE TABLE)\n\n' +
				'Cette opération libère l\'espace perdu après les suppressions.\n\n' +
				'Sur les sites à fort trafic, elle peut provoquer un ralentissement temporaire pendant son exécution.\n\n' +
				'Procédez de préférence en dehors des heures de pointe.\n\n' +
				'Continuer ?'
			);
			if (!confirmed) return;
		}

		btn.prop('disabled', true).text(pmDBCleaner.processing);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: action, nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					btn.prop('disabled', false).text('Nettoyer');
					alert(pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Nettoyer');
				alert(pmDBCleaner.error);
			}
		});
	});

	// ─── Tout nettoyer ───────────────────────────────────────────────────

	$('#pm-cleanup-all').on('click', function(e) {
		e.preventDefault();
		if (!confirm('Nettoyer tous les éléments ? Cette opération peut prendre du temps.')) return;

		var btn = $(this);
		btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + pmDBCleaner.processing);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_cleanup_all', nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					btn.prop('disabled', false).html('<span class="dashicons dashicons-database"></span> Tout nettoyer');
					alert(pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-database"></span> Tout nettoyer');
				alert(pmDBCleaner.error);
			}
		});
	});

	// ─── Métadonnées (postmeta) ───────────────────────────────────────────

	$('#pm-cf-analyze').on('click', function(e) {
		e.preventDefault();
		var btn = $(this);
		var type = $('input[name="pm_cf_type"]:checked').val();

		btn.prop('disabled', true).text('...');
		$('#pm-cf-results').hide();
		$('#pm-cf-keys-list').empty();
		$('#pm-cf-confirm-wrap').hide();
		$('#pm-cf-delete').prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_get_meta_keys', type: type, nonce: pmDBCleaner.nonce },
			success: function(response) {
				btn.prop('disabled', false).text('Analyser');
				if (response.success && response.data.keys.length > 0) {
					$.each(response.data.keys, function(i, key) {
						var safe = $('<div>').text(key).html();
						$('#pm-cf-keys-list').append(
							'<label class="pm-key-label">' +
							'<input type="checkbox" class="pm-cf-key" value="' + safe + '"> ' +
							safe + '</label>'
						);
					});
					$('#pm-cf-results').show();
					$('#pm-cf-filter').val('').trigger('input');
				} else {
					$('#pm-cf-results').show();
					$('#pm-cf-keys-list').html('<p style="color:#666;font-style:italic">Aucune clé trouvée.</p>');
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Analyser');
				alert(pmDBCleaner.error);
			}
		});
	});

	// Filtre en temps réel — postmeta
	$('#pm-cf-filter').on('input', function() {
		var val = $(this).val().toLowerCase();
		$('#pm-cf-keys-list .pm-key-label').each(function() {
			$(this).toggle($(this).text().toLowerCase().includes(val));
		});
	});

	// Activation bouton suppression postmeta
	$(document).on('change', '.pm-cf-key, #pm-cf-confirm', function() {
		var hasChecked = $('.pm-cf-key:checked').length > 0;
		var confirmed  = $('#pm-cf-confirm').is(':checked');
		$('#pm-cf-delete').prop('disabled', !(hasChecked && confirmed));
		if (hasChecked) {
			$('#pm-cf-confirm-wrap').show();
		} else {
			$('#pm-cf-confirm-wrap').hide();
			$('#pm-cf-confirm').prop('checked', false);
			$('#pm-cf-delete').prop('disabled', true);
		}
	});

	// Suppression postmeta
	$('#pm-cf-delete').on('click', function(e) {
		e.preventDefault();
		var btn  = $(this);
		var type = $('input[name="pm_cf_type"]:checked').val();
		var keys = [];
		$('.pm-cf-key:checked').each(function() { keys.push($(this).val()); });

		if (!keys.length || !$('#pm-cf-confirm').is(':checked')) return;
		btn.prop('disabled', true).text('Suppression...');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_delete_custom_fields', type: type, keys: keys, nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					$('.pm-cf-key:checked').closest('label').remove();
					$('#pm-cf-confirm').prop('checked', false);
					$('#pm-cf-confirm-wrap').hide();
					btn.prop('disabled', true).text('Supprimer la sélection');
					alert('✅ ' + response.data.message);
				} else {
					btn.prop('disabled', false).text('Supprimer la sélection');
					alert(pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Supprimer la sélection');
				alert(pmDBCleaner.error);
			}
		});
	});

	// ─── WP Options — accès table complète ───────────────────────────────

	function pmWpoLoad(search) {
		var btn = $('#pm-wpo-analyze');
		btn.prop('disabled', true).text('...');
		$('#pm-wpo-keys-list').empty();
		$('#pm-wpo-confirm-wrap').hide();
		$('#pm-wpo-delete').prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_get_option_keys', search: search || '', nonce: pmDBCleaner.nonce },
			success: function(response) {
				btn.prop('disabled', false).text('Analyser');
				if (response.success && response.data.keys.length > 0) {
					var total = response.data.total || 0;
					var shown = response.data.keys.length;
					var note = total > shown
						? '<p style="font-size:12px;color:#856404;margin:0 0 8px">⚠️ ' + shown + ' affichées sur ' + total + ' — utilisez le filtre pour affiner.</p>'
						: '';
					$('#pm-wpo-keys-list').html(note);
					$.each(response.data.keys, function(i, key) {
						var safe = $('<div>').text(key).html();
						$('#pm-wpo-keys-list').append(
							'<label class="pm-key-label">' +
							'<input type="checkbox" class="pm-wpo-key" value="' + safe + '"> ' +
							safe + '</label>'
						);
					});
					$('#pm-wpo-results').show();
				} else {
					$('#pm-wpo-results').show();
					$('#pm-wpo-keys-list').html('<p style="color:#666;font-style:italic">Aucune option trouvée.</p>');
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Analyser');
				alert(pmDBCleaner.error);
			}
		});
	}

	$('#pm-wpo-analyze').on('click', function(e) {
		e.preventDefault();
		$('#pm-wpo-results').hide();
		pmWpoLoad($('#pm-wpo-filter').val().trim());
	});

	// Filtre WP Options : recherche côté serveur au bout de 400ms
	var pmWpoTimer;
	$('#pm-wpo-filter').on('input', function() {
		clearTimeout(pmWpoTimer);
		var val = $(this).val().trim();
		if (val.length === 0 || val.length >= 2) {
			pmWpoTimer = setTimeout(function() { pmWpoLoad(val); }, 400);
		}
	});

	// Activation bouton suppression wp_options
	$(document).on('change', '.pm-wpo-key, #pm-wpo-confirm', function() {
		var hasChecked = $('.pm-wpo-key:checked').length > 0;
		var confirmed  = $('#pm-wpo-confirm').is(':checked');
		$('#pm-wpo-delete').prop('disabled', !(hasChecked && confirmed));
		if (hasChecked) {
			$('#pm-wpo-confirm-wrap').show();
		} else {
			$('#pm-wpo-confirm-wrap').hide();
			$('#pm-wpo-confirm').prop('checked', false);
			$('#pm-wpo-delete').prop('disabled', true);
		}
	});

	// Suppression wp_options
	$('#pm-wpo-delete').on('click', function(e) {
		e.preventDefault();
		var btn  = $(this);
		var keys = [];
		$('.pm-wpo-key:checked').each(function() { keys.push($(this).val()); });

		if (!keys.length || !$('#pm-wpo-confirm').is(':checked')) return;
		btn.prop('disabled', true).text('Suppression...');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_delete_options', keys: keys, nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					$('.pm-wpo-key:checked').closest('label').remove();
					$('#pm-wpo-confirm').prop('checked', false);
					$('#pm-wpo-confirm-wrap').hide();
					btn.prop('disabled', true).text('Supprimer la sélection');
					alert('✅ ' + response.data.message);
				} else {
					btn.prop('disabled', false).text('Supprimer la sélection');
					alert(response.data.message || pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Supprimer la sélection');
				alert(pmDBCleaner.error);
			}
		});
	});

	// ─── Autoload ────────────────────────────────────────────────────────

	$('#pm-options-analyze').on('click', function(e) {
		e.preventDefault();
		var btn = $(this);
		btn.prop('disabled', true).text('...');
		$('#pm-options-autoload-results').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_analyze_autoload', nonce: pmDBCleaner.nonce },
			success: function(response) {
				btn.prop('disabled', false).text('Analyser');
				if (response.success) {
					var d = response.data;
					$('#pm-options-autoload-size').html(
						'<strong style="color:' + d.status_color + ';font-size:16px;display:block">' + d.size_label + '</strong>' +
						'<span style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:' + d.status_bg + ';color:' + d.status_color + '">' + d.status + '</span>' +
						'<span style="display:block;color:#666;font-size:12px;margin-top:4px">Taille totale autoloadée</span>'
					);
					$('#pm-options-autoload-count').html(
						'<strong style="color:#289dcc;font-size:16px;display:block">' + d.count + '</strong>' +
						'<span style="color:#666;font-size:12px">Entrées autoloadées</span>'
					);
					var rows = '';
					$.each(d.top10, function(i, row) {
						var safe = $('<div>').text(row.name).html();
						if (row.autoload === 'yes') {
							rows += '<label class="pm-key-label">' +
								'<input type="checkbox" class="pm-autoload-key" value="' + safe + '">' +
								'<span>' + safe + '</span>' +
								'<span class="pm-key-size">' + row.size + '</span></label>';
						} else {
							rows += '<label class="pm-key-label" style="opacity:0.5;cursor:default">' +
								'<span style="color:#1e8c3b;width:16px;text-align:center">✓</span>' +
								'<span>' + safe + '</span>' +
								'<span class="pm-key-size">' + row.size + '</span></label>';
						}
					});
					$('#pm-options-top10-body').html(rows);
					$('#pm-autoload-confirm-wrap').hide();
					$('#pm-autoload-confirm').prop('checked', false);
					$('#pm-autoload-disable').prop('disabled', true);
					$('#pm-options-autoload-results').show();
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Analyser');
				alert(pmDBCleaner.error);
			}
		});
	});

	// Activation bouton désactiver autoload
	$(document).on('change', '.pm-autoload-key, #pm-autoload-confirm', function() {
		var hasChecked = $('.pm-autoload-key:checked').length > 0;
		var confirmed  = $('#pm-autoload-confirm').is(':checked');
		$('#pm-autoload-disable').prop('disabled', !(hasChecked && confirmed));
		if (hasChecked) {
			$('#pm-autoload-confirm-wrap').show();
		} else {
			$('#pm-autoload-confirm-wrap').hide();
			$('#pm-autoload-confirm').prop('checked', false);
			$('#pm-autoload-disable').prop('disabled', true);
		}
	});

	// Désactiver l'autoload
	$('#pm-autoload-disable').on('click', function(e) {
		e.preventDefault();
		var btn   = $(this);
		var names = [];
		$('.pm-autoload-key:checked').each(function() { names.push($(this).val()); });

		if (!names.length || !$('#pm-autoload-confirm').is(':checked')) return;
		btn.prop('disabled', true).text('...');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_disable_autoload', option_names: names, nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					$('.pm-autoload-key:checked').each(function() {
						$(this).prop('checked', false).prop('disabled', true);
					});
					$('#pm-autoload-confirm').prop('checked', false);
					$('#pm-autoload-confirm-wrap').hide();
					btn.prop('disabled', true).text('Désactiver l\'autoload de la sélection');
					alert('✅ ' + response.data.message);
				} else {
					btn.prop('disabled', false).text('Désactiver l\'autoload de la sélection');
					alert(response.data.message || pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Désactiver l\'autoload de la sélection');
				alert(pmDBCleaner.error);
			}
		});
	});

	// ─── Tâches Cron orphelines ───────────────────────────────────────────

	$('#pm-cron-toggle').on('click', function(e) {
		e.preventDefault();
		$('#pm-cron-results').slideToggle(150);
	});

	$(document).on('change', '.pm-cron-key, #pm-cron-confirm', function() {
		var hasChecked = $('.pm-cron-key:checked').length > 0;
		var confirmed  = $('#pm-cron-confirm').is(':checked');
		$('#pm-cron-delete').prop('disabled', !(hasChecked && confirmed));
		if (hasChecked) {
			$('#pm-cron-confirm-wrap').show();
		} else {
			$('#pm-cron-confirm-wrap').hide();
			$('#pm-cron-confirm').prop('checked', false);
			$('#pm-cron-delete').prop('disabled', true);
		}
	});

	$('#pm-cron-delete').on('click', function(e) {
		e.preventDefault();
		var btn    = $(this);
		var events = [];
		$('.pm-cron-key:checked').each(function() {
			events.push({ hook: $(this).data('hook'), timestamp: $(this).data('timestamp') });
		});

		if (!events.length || !$('#pm-cron-confirm').is(':checked')) return;
		btn.prop('disabled', true).text('Suppression...');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_delete_cron_orphans', events: events, confirmed: '1', nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					$('.pm-cron-key:checked').closest('label').remove();
					$('#pm-cron-confirm').prop('checked', false);
					$('#pm-cron-confirm-wrap').hide();
					btn.prop('disabled', true).text('Supprimer la sélection');
					if ($('#pm-cron-list label').length === 0) {
						$('#pm-cron-list').html('<p style="font-size:13px;color:#646970;padding:6px">Aucune tâche cron orpheline détectée.</p>');
					}
					alert('✅ ' + response.data.message);
					location.reload();
				} else {
					btn.prop('disabled', false).text('Supprimer la sélection');
					alert(response.data.message || pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Supprimer la sélection');
				alert(pmDBCleaner.error);
			}
		});
	});

	// ─── Désinstallation manuelle (SFTP/SSH) ─────────────────────────────

	$('#pm-uninstall-toggle').on('click', function(e) {
		e.preventDefault();
		$('#pm-uninstall-confirm-wrap').show();
		$('#pm-uninstall-confirm-btn').show();
		$(this).hide();
	});

	$('#pm-uninstall-confirm').on('change', function() {
		$('#pm-uninstall-confirm-btn').prop('disabled', !$(this).is(':checked'));
	});

	$('#pm-uninstall-confirm-btn').on('click', function(e) {
		e.preventDefault();
		if (!$('#pm-uninstall-confirm').is(':checked')) return;
		var btn = $(this);
		btn.prop('disabled', true).text('Suppression...');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'pm_uninstall_cron', confirmed: '1', nonce: pmDBCleaner.nonce },
			success: function(response) {
				if (response.success) {
					btn.text('Tâches cron supprimées ✅').prop('disabled', true);
					$('#pm-uninstall-confirm-wrap').hide();
					alert('✅ ' + response.data.message);
				} else {
					btn.prop('disabled', false).text('Supprimer les tâches cron du plugin');
					alert(response.data.message || pmDBCleaner.error);
				}
			},
			error: function() {
				btn.prop('disabled', false).text('Supprimer les tâches cron du plugin');
				alert(pmDBCleaner.error);
			}
		});
	});

});
