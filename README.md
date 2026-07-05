# PM DB Cleaner

WordPress plugin for automatic and manual database cleanup. Developed by [Perspectives Marketing](https://perspectives.marketing).

---

## Features

### Scheduled automatic cleanups

| Frequency | What is cleaned |
|-----------|----------------|
| Daily | Action Scheduler — completed, failed or cancelled tasks older than 7 days |
| Weekly | Posts, Comments, Terms & Taxonomies, Users (excluding multisite), WooCommerce — orphaned/duplicated metadata, oEmbed caches, orphaned taxonomy relationships, orphaned variations |
| Monthly | Trashed comments, expired transients, orphaned transient timeouts |

### Manual cleanups

> ⚠️ **Danger Zone** — the four panels below are grouped in a dedicated danger zone in the interface. Unlike automatic cleanups, these operations rely on your own selection. A wrong action can damage the site irreversibly.

- **Metadata (postmeta)** — list meta keys with filter, selection and deletion by table (posts, terms, users)
- **WP Options** — full access to the `wp_options` table with filter and deletion (high-risk area, explicit warning)
- **Autoload** — analyze total autoloaded size, top 10 heaviest options, selective autoload disabling
- **Dirsize Cache** — delete the uploads folder size cache
- **DB Overhead** — optimize fragmented MySQL tables (`OPTIMIZE TABLE`)
- **Orphan cron tasks** — detect and delete scheduled hooks with no registered callback

### Informational

- **Post revisions** — counter and status of the `WP_POST_REVISIONS` constant

---

## Installation

1. Download the plugin and unzip it into `wp-content/plugins/pm-db-cleaner/`
2. Make sure the `plugin-update-checker/` folder is present at the plugin root
3. Activate via **Plugins > Activate** — the 3 cron tasks are scheduled automatically
4. Go to **Tools > PM DB Cleaner**

---

## Updates

Updates are managed via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (v5.7) from this GitHub repository. WordPress notifies automatically when a new version is available.

---

## Uninstallation

**Via Plugins > Deactivate then Delete** — cron tasks are removed automatically on deactivation and uninstallation.

**Via SFTP/SSH** — use the "Prepare manual deletion" button in the plugin interface **before** deleting the file, to prevent the 3 cron tasks from remaining scheduled indefinitely.

---

## Security

- All AJAX actions are protected by nonce (`pm_db_cleanup`) and `manage_options` capability check
- Destructive deletions require explicit confirmation via checkbox
- Per-operation limits (500 records, 50 keys, 15 options) prevent timeouts
- Logs are stored in `wp-content/uploads/pm-db-cleaner.txt` (protected by Wordfence against PHP execution)
- Log entries expose no user identifiers (AUTO/MANUAL mode only)

---

## Logs

All automatic and manual cleanups are logged in `wp-content/uploads/pm-db-cleaner.txt`.

- Automatic rotation above 5 MB
- Archives older than 90 days are deleted
- On multisite: one file per site (`pm-db-cleaner-site-{id}.txt`)

---

## Internationalization

The plugin is natively in English. A French translation (`fr_FR`) is included in the `languages/` folder. To add another language, use the `.pot` template file provided.

---

## Structure

```
pm-db-cleaner/
├── pm-db-cleaner.php              # Main file (bootstrap)
├── update-checker-config.php      # Plugin Update Checker configuration
├── uninstall.php                  # Cleanup on uninstallation
├── assets/
│   ├── admin.css
│   ├── admin.js
│   └── img/
│       ├── icon-128x128.png
│       └── icon-256x256.png
├── includes/
│   ├── class-logger.php
│   ├── class-cleanup-auto.php
│   ├── class-cleanup-manual.php
│   ├── class-options.php
│   ├── class-cron-orphans.php
│   └── class-admin-page.php
├── languages/
│   ├── pm-db-cleaner.pot
│   ├── pm-db-cleaner-fr_FR.po
│   └── pm-db-cleaner-fr_FR.mo
└── plugin-update-checker/         # YahnisElsts/plugin-update-checker v5.7
```

---

## Author

[Perspectives Marketing](https://perspectives.marketing) — internal use, deployed across a fleet of client sites.
