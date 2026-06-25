# PM DB Cleaner

Plugin WordPress de nettoyage automatique et manuel de la base de données. Développé par [Perspectives Marketing](https://perspectives.marketing).

---

## Fonctionnalités

### Nettoyages automatiques programmés

| Fréquence | Ce qui est nettoyé |
|-----------|-------------------|
| Quotidien | Action Scheduler — actions terminées, échouées ou annulées de plus de 7 jours |
| Hebdomadaire | Posts, Commentaires, Termes & Taxonomies, Utilisateurs (hors multisite), WooCommerce — métadonnées orphelines/dupliquées, caches oEmbed, relations de taxonomie orphelines, variations orphelines |
| Mensuel | Commentaires en corbeille, transients expirés, timeouts de transients orphelins |

### Nettoyages manuels

- **Métadonnées (postmeta)** — liste des clés meta avec filtre, sélection et suppression par table (posts, termes, utilisateurs)
- **WP Options** — accès complet à la table `wp_options` avec filtre et suppression (zone à risque, avertissement explicite)
- **Autoload** — analyse du poids total autoloadé, top 10 des options les plus lourdes, désactivation sélective de l'autoload
- **Dirsize Cache** — suppression du cache de taille des dossiers uploads
- **Overhead BDD** — optimisation des tables MySQL fragmentées (`OPTIMIZE TABLE`)
- **Tâches Cron orphelines** — détection et suppression des hooks planifiés sans callback enregistré

### Informations

- **Révisions d'articles** — compteur et statut de la constante `WP_POST_REVISIONS`

---

## Installation

1. Télécharger le plugin et le décompresser dans `wp-content/plugins/pm-db-cleaner/`
2. S'assurer que le dossier `plugin-update-checker/` est présent à la racine du plugin
3. Activer via **Extensions > Activer** — les 3 tâches cron sont planifiées automatiquement
4. Accéder à **Outils > PM DB Cleaner**

---

## Mises à jour

Les mises à jour sont gérées via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (v5.7) depuis ce dépôt GitHub privé. WordPress notifie automatiquement lorsqu'une nouvelle version est disponible.

---

## Désinstallation

**Via Extensions > Désactiver puis Supprimer** — les tâches cron sont supprimées automatiquement à la désactivation et à la désinstallation.

**Via SFTP/SSH** — utiliser le bouton "Préparer la suppression manuelle" dans l'interface du plugin **avant** de supprimer le fichier, pour éviter que les 3 tâches cron restent planifiées indéfiniment.

---

## Sécurité

- Toutes les actions AJAX sont protégées par nonce (`pm_db_cleanup`) et vérification `manage_options`
- Les suppressions destructives requièrent une confirmation explicite par case à cocher
- Les limites par opération (500 enregistrements, 50 clés, 15 options) préviennent les timeouts
- Les logs sont stockés dans `wp-content/uploads/pm-db-cleaner.txt` (protégé par Wordfence contre l'exécution PHP)
- Les entrées de log n'exposent aucun identifiant utilisateur (AUTO/MANUEL uniquement)

---

## Logs

Les nettoyages automatiques et manuels sont tracés dans `wp-content/uploads/pm-db-cleaner.txt`.

- Rotation automatique au-delà de 5 MB
- Suppression des archives de plus de 90 jours
- Sur multisite : un fichier par site (`pm-db-cleaner-site-{id}.txt`)

---

## Structure

```
pm-db-cleaner/
├── pm-db-cleaner.php          # Fichier principal
├── uninstall.php              # Nettoyage à la désinstallation
├── assets/
│   ├── admin.css
│   └── admin.js
└── plugin-update-checker/     # YahnisElsts/plugin-update-checker v5.7
```

---

## Auteur

[Perspectives Marketing](https://perspectives.marketing) — usage interne, déploiement sur flotte de sites clients.
