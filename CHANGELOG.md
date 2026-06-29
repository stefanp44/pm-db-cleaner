# Changelog — PM DB Cleaner

Toutes les modifications notables de ce projet sont documentées dans ce fichier.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).

---

## [2026-06-22]

### Modifié

- **Conversion mu-plugin → plugin classique** : le plugin peut désormais être
  géré via Extensions > Activer/Désactiver et recevoir des mises à jour
  automatiques.
- Ajout de `register_activation_hook` : planifie les 3 tâches cron à
  l'activation (remplace la planification sur `init` qui se déclenchait à
  chaque requête).
- Ajout de `register_deactivation_hook` : supprime les 3 tâches cron à la
  désactivation via l'interface WordPress — couvre la majorité des cas et
  évite des tâches orphelines sans intervention manuelle.
- Ajout de `uninstall.php` : filet de sécurité supplémentaire qui supprime
  les crons lors d'une désinstallation via Extensions > Supprimer (couvre
  le cas où le plugin aurait été désactivé sans passer par le hook).
- Suppression de `schedule_cleanups()` appelé sur `init` : la planification
  est maintenant gérée exclusivement par le hook d'activation.
- Initialisation déplacée de `PM_DB_Cleaner::get_instance()` direct vers
  `add_action( 'plugins_loaded', ... )` — bonne pratique pour un plugin
  classique.
- En-tête plugin : ajout de `Update URI` pour la compatibilité avec
  Plugin Update Checker (GitHub).
- Section "Avant de supprimer ce plugin" renommée "Supprimer le plugin via
  SFTP/SSH ?" avec texte mis à jour : clarifie que la désactivation normale
  via WordPress suffit, et que le bouton manuel ne concerne que la
  suppression directe de fichier via SFTP/SSH.

## [2026-06-13] (3)

### Ajouté

- Section "Avant de supprimer ce plugin" : bouton de désinstallation qui
  retire les 3 tâches cron récurrentes de PM DB Cleaner
  (`pm_cleanup_action_scheduler_daily`, `pm_cleanup_database_weekly`,
  `pm_cleanup_monthly`) via `wp_clear_scheduled_hook()`. Nécessaire car le
  plugin est un mu-plugin sans hook de désinstallation WordPress : sans ce
  bouton, supprimer le fichier via SFTP/SSH laisserait ces 3 tâches
  planifiées indéfiniment (elles deviendraient des "tâches cron
  orphelines"). Confirmation explicite requise, action réversible (la
  réactivation du plugin replanifie automatiquement ces tâches).

## [2026-06-13] (2)

### Corrigé

- Heures affichées pour les tâches cron (planifications automatiques et
  tâches orphelines) corrigées : remplacement de `date_i18n()` par
  `get_date_from_gmt()` pour la conversion UTC → heure locale du site
  (Réglages > Général > Fuseau horaire), gérant correctement le passage
  heure d'été/hiver. Un décalage de 2h était visible sur les tâches
  BackWPup en comparaison avec WP Crontrol.

## [2026-06-13]

### Ajouté

- Nouvelle section "Tâches Cron orphelines" : détecte les hooks planifiés
  sans aucun callback enregistré (`has_action()` vide), généralement des
  résidus d'un plugin désinstallé. Détection effectuée au chargement de la
  page admin (pas via `admin-ajax.php`) pour refléter le même contexte
  d'exécution que WP Crontrol et éviter les faux positifs liés aux plugins
  qui n'enregistrent leur callback que via leur propre `admin_menu` (cas
  observé avec SureMail). Suppression manuelle uniquement, avec confirmation
  explicite obligatoire ("je sais que ce plugin n'est plus utilisé"),
  revérifiée côté serveur. Limite de 50 tâches par opération.
- Hook Core `wp_delete_temp_updater_backups` ajouté à la liste des hooks
  jamais considérés comme orphelins.

### Modifié

- Bloc "Nettoyage automatique programmé" : chaque ligne explicite désormais
  précisément ce qu'elle couvre (sections Posts / Commentaires / Termes &
  Taxonomies / Utilisateurs / WooCommerce pour le nettoyage hebdomadaire,
  Commentaires + Système pour le mensuel), avec une note sur ce qui n'est
  jamais automatisé (Custom Fields, Autoload, Overhead BDD).
- Custom Fields et Autoload (wp_options) déplacés hors du tableau principal,
  dans une grille deux colonnes dédiée en bas de page. Ajout du CSS
  `.pm-two-col` / `.pm-pane` / `.pm-pane-title` (absent jusqu'ici).

## [2026-06-09]

Version de référence v6, déploiement en cours sur les sites pilotes.
