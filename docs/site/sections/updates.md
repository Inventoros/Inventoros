Inventoros has a built-in updater so you can apply new releases from the admin panel, and it verifies that each release is signed before installing it. This section covers updating, the signature model, and backups.

## Upgrading from 1.0.x to 2.0.0

Every 1.0.x install must be upgraded by hand once. The updater in 1.0.8 and earlier cannot install any release, because GitHub serves release downloads through a redirect that it refuses. Back up (and check the database backup), replace the files from the release package or check out the tag, run `php artisan optimize:clear`, preview with `php artisan migrate --pretend --force` on MySQL or PostgreSQL (without `--force` it waits at a confirmation prompt), migrate, and add the scheduler cron entry. The full steps are in [UPGRADE.md](https://github.com/Inventoros/Inventoros/blob/main/UPGRADE.md) in the Inventoros repository. From 2.0.0 on, the updater below installs new releases again.

The license changes with this upgrade: Inventoros is licensed under AGPL-3.0-only, while releases up to and including 1.0.8 remain under MIT.

## Updating from the admin panel

To update an existing installation, sign in as an administrator and open Settings, then Updates (the System Update page at `/admin/update`). Updates and backups affect the whole installation, so only administrators with the `manage_organization` permission in the installation's administrator organization (`INVENTOROS_PLUGIN_ADMIN_ORG`, or the first organization when unset) can use this page. The updater checks for the latest published release, shows you the current and available versions, and applies the update in place.

An update runs in this order:

1. Download the release package (`inventoros-cpanel-<version>.zip`) and its signature, verify the signature, extract it to a temporary folder and check the package (version, layout, supported PHP range). A bad package is refused while the site is still up.
2. Put the site in maintenance mode and take a backup (see [Automatic backups](#automatic-backups)).
3. Replace the application files, clear the caches, run the database migrations, rebuild the caches and signal queue workers to restart.
4. Bring the site back up.

If any step after the backup fails (replacing files, migrating or caching), the updater restores the backup, files and database, and reports that the previous version was put back. If the restore fails too, the site stays in maintenance mode and the message names the backup to restore by hand. Only one update can run at a time.

You can run the same update from the command line with `php artisan app:update` (`--check` only reports whether a newer release exists).

On Docker, do not use the in-app updater: the files it replaces are lost when the container is recreated. Pull the new image instead, as described in [Install with Docker](installation-docker#updating).

The updater rebuilds the caches itself. If you run a manual install (cPanel or VPS from source), you can also re-cache yourself:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Signed releases

Releases ship with a detached Ed25519 signature alongside the archive (an `<asset>.zip.sig` file next to the `.zip`). Before extracting anything, the updater downloads the signature and verifies the archive bytes against the configured public key. If verification fails, the update is refused and nothing is written.

Signature verification is required by default and fails closed. If no public key is configured, updates are refused rather than installed unverified. The `.env.example` ships with the official Inventoros release-signing public key already set:

```bash
INVENTOROS_UPDATE_PUBLIC_KEY=dtH972Sp6dfKcdT/NqdXOeBGSrTOfBHxJbdZ3UfRN24=
```

To make the requirement explicit (or re-enable it after disabling), set:

```bash
INVENTOROS_UPDATE_SIGNATURE_REQUIRED=true
```

You can set `INVENTOROS_UPDATE_SIGNATURE_REQUIRED=false` to install unsigned builds, but this is not recommended. The matching secret key never leaves the Inventoros CI; only the public key is distributed.

If you install from a fork or mirror, override the download allowlist so the updater accepts your release URLs:

```bash
INVENTOROS_UPDATE_PREFIXES=https://github.com/Inventoros/Inventoros/releases/download/
```

Updates from URLs that do not start with one of these prefixes are rejected before any bytes are downloaded.

Generate your own signing keypair (for self-built releases) with:

```bash
php artisan update:signing-keypair
```

This prints a public key (for `INVENTOROS_UPDATE_PUBLIC_KEY`) and a secret key. Keep the secret key out of your repository and your `.env` that ships with the app; it belongs only in your build pipeline. There, with the secret key in `INVENTOROS_UPDATE_SECRET_KEY`, sign a release archive with:

```bash
php artisan update:sign inventoros-cpanel-2.0.0.zip
```

This writes `inventoros-cpanel-2.0.0.zip.sig` next to the archive; upload both to the release.

The Sodium PHP extension must be enabled for signature verification to work.

## Automatic backups

The updater takes a backup before it changes any files or the database, and you can take one yourself from Settings, then Updates (or with `php artisan app:update --backup`). `php artisan app:update --list-backups` lists them. Backups are ZIP files in `storage/app/backups` holding the application files (including `vendor/` and the web root) and the database. Old backups are not deleted automatically; remove the ones you no longer need from the Updates page. The database is captured the best way available:

| Database | Method |
|---|---|
| MySQL / MariaDB | `mysqldump`, or the built-in PHP dump when `exec()` is disabled or `mysqldump` is missing |
| PostgreSQL | `pg_dump`, or the built-in PHP dump |
| SQLite | a consistent snapshot of the database file, or the built-in PHP dump |

The built-in PHP dump includes the table structure as well as the rows, so restoring it after a failed migration puts the previous schema back. The method used is shown after each backup and written to the log.

If no method can back up the database, the backup fails and the updater stops without changing anything. To accept a files-only backup (for example when you back up the database some other way), set `INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP=true`.

## Manual backups

For your own backups, capture at least two things:

- The database. For MySQL or MariaDB: `mysqldump -u USER -p DBNAME > inventoros-backup.sql`. For PostgreSQL: `pg_dump -U USER DBNAME > inventoros-backup.sql`.
- The application files, especially `.env` and the `storage/` directory (uploaded files and logs).

A quick file snapshot on a VPS:

```bash
tar -czf inventoros-files-backup.tar.gz /var/www/inventoros/.env /var/www/inventoros/storage
```

## Restoring

To restore after a failed update, put back your file snapshot and reload the database dump:

```bash
mysql -u USER -p DBNAME < inventoros-backup.sql        # MySQL / MariaDB
psql -U USER -d DBNAME -f inventoros-backup.sql        # PostgreSQL
```

Backups made by the updater are restored from Settings, then Updates (Restore next to the backup), or with `php artisan app:update --restore=<backup file name>`. A restore puts the files back and replays the database the same way it was captured, with the site in maintenance mode while it runs.

Then re-cache configuration, routes, and views as shown above. Because migrations run forward during an update, restoring the matching database dump alongside the matching code version keeps the two consistent.
