# Multisite Exporter

A WordPress plugin that moves **single sites between two WordPress multisite networks**, for example from `cms1.com` (100+ sites) to `cms2.com` on another host, using only **wp-admin**: no SSH, WP-CLI or phpMyAdmin needed.

Each site is exported as a package (`.tar`) with:

- **Database:** every table that belongs to the site, including plugin tables (Yoast, Rank Math, WooCommerce and so on).
- **Users:** the site's users, with their password hashes, roles and profile data.
- **Media:** all files in the site's uploads folder.
- **Manifest:** the active theme, active plugins and versions, and environment details.

The package is imported into the other network as a new site, or over an existing one.

## Installation

1. Download `wordpress-multisite-exporter.zip` from the latest [release](https://github.com/didoivanov/wordpress-multisite-exporter/releases).
2. On **both** networks go to **Network Admin → Plugins → Add New → Upload Plugin** and upload the zip.
3. Click **Network Activate**.
4. Open **Network Admin → Site Migrator**.

Requires WordPress 5.8+ and PHP 7.4+.

## Updates from wp-admin

The plugin checks this repository's GitHub Releases. When a newer version is published, it appears under **Network Admin → Updates** and **Plugins** like any other plugin, and you update with one click. Use the plugin's **Check for updates** link to check immediately instead of waiting up to 6 hours.

### Publishing a new version (maintainers)

1. Bump the version in `wordpress-multisite-exporter.php`, in both the `Version:` header and `MSE_VERSION`, and add an entry to `CHANGELOG.md`.
2. Commit, then tag and push:
   ```bash
   git tag v0.2.0
   ```
   ```bash
   git push origin main --tags
   ```
3. The **Release** GitHub Action checks that the tag matches the version, runs the tests, builds `wordpress-multisite-exporter.zip` and publishes the release.

## Migrating a site

### 1. Export (on the source network, e.g. cms1.com)

**Site Migrator → Export**: filter or select the sites (one, several or all), then click **Export selected**. Exports run one after another in small steps. **Keep the tab open**, because work only advances while the page is open.

When a site is done, use **Copy URL** (or **Download**).

### 2. Import (on the target network, e.g. cms2.com)

**Site Migrator → Import**, then add packages in one of three ways:

| Method | When to use |
|---|---|
| **From URL** (recommended) | Paste the export URLs, one per line. The target server downloads them directly in 20 MB pieces, so file size limits don't matter. Best for bulk migrations. |
| **Upload** | Upload a downloaded `.tar` from your computer. It is sent in small pieces, so PHP's upload limit doesn't matter. |
| **Already on this server** | A package you copied into the imports folder shown on the screen (e.g. with the host's file manager). |

Each package is checked and waits for **review**:

- **Compatibility check:** missing plugins (blocks the import unless you tick *Import anyway*), different plugin, theme or WordPress versions, a site that already uses the domain, and free disk space.
- **Replacement theme:** if the site's theme is not installed on this network, choose one of the installed themes instead. When starting all imports at once, one replacement theme can be chosen for all of them.
- **Target domain:** defaults to the site's current domain (e.g. `testsite.com`).
- **Create new site** or **Overwrite existing site** with that domain.
- **Earlier addresses:** URLs from before a domain switch (e.g. the install subdomain `testsite.cms1.com`) are detected and replaced with the target domain (on by default).
- **Advanced:** extra `old => new` replacements, and post GUID handling.

Click **Check again & start import**, or **Start all ready imports with default settings** to queue every reviewed package at once.

### 3. Switch DNS

Point the domain to the new host. Before switching, you can preview the site by adding the domain to your computer's `hosts` file with the new server's IP.

## What the import does

- **Database:** creates every table of the site with the target network's table prefix (e.g. `wp_17_posts` → `cx_42_posts`), including renamed option and meta keys such as `wp_17_user_roles`. MySQL 8 collations are converted automatically on MariaDB.
- **URLs and paths:** replaces the domain, upload paths (`/uploads/sites/17/` → `/uploads/sites/42/`) and server paths. Replacement is safe for **serialized PHP data** (string lengths are recalculated, and objects are never unserialized), **JSON** (`https:\/\/…`, e.g. Elementor) and **URL-encoded** values. Host matching is strict, so `site.com` does not touch `site.com.au`.
- **Users:**
  - **Matched by e-mail:** existing accounts on the target network are reused.
  - **Missing users:** created with their original password hash, so passwords keep working.
  - **Login taken by someone else:** the new user gets a suffix (`author-2`).
  - **Content ownership:** post authors, comment users and `_edit_last` are remapped to the new user IDs.
- **No e-mails:** nothing is sent while importing, including core's "new site" notification (which also fails on hosts where PHP `mail()` is disabled).
- **Clean-up:** resets cached data (rewrite rules, transients, Elementor CSS), sets `home` and `siteurl`, copies the site's flags (public, archived and others) and clears caches.
- **Media:** copied into the new site's uploads folder. Executable files (`.php` and similar) are skipped for safety.

Every step can be resumed. If a request is interrupted, the job continues from its last saved position: **Retry** on an error, or **Pause** / **Resume**.

## Limitations

- **Network-wide data is not moved:** network settings, network-activated plugin settings stored in `sitemeta`, super admins, and plugin tables that aren't prefixed per site.
- **Main site:** exporting the network's **main site** is supported, but importing over a network's main site is not.
- **Domain mapping:** only WordPress's native domain mapping (setting the site's domain under *Sites → Edit*) is handled. Mapping plugins that store domains in their own tables need those tables migrated separately.
- **Package security:** packages contain password hashes, and export URLs are unguessable but public. **Delete jobs and packages after the migration** (the *Delete* button removes the package file).
- **Very large sites:** packages need free disk space on both servers (roughly the site's size, plus the media again on import).

## Development

```bash
php tests/run-unit-tests.php
```

Hooks: `mse_time_budget`, `mse_base_dir`, `mse_exports_url`, `mse_export_tables`, `mse_export_row_filter`, `mse_export_skip_upload_dirs`, `mse_export_skip_user_meta`, `mse_import_blocked_file`.
