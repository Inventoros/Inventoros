Inventoros has a built-in updater so you can apply new releases from the admin panel, and it verifies that each release is signed before installing it. This section covers updating, the signature model, and backups.

### Upgrading from 1.0.x to 2.0.0

Every 1.0.x install must be upgraded by hand once. The updater in 1.0.8 and earlier cannot install any release, because GitHub serves release downloads through a redirect that it refuses. Back up (and check the database backup), replace the files from the release package or check out the tag, run `php artisan optimize:clear`, preview with `php artisan migrate --pretend --force` on MySQL or PostgreSQL (without `--force` it waits at a confirmation prompt), migrate, and add the scheduler cron entry. The full steps are in `UPGRADE.md` in the Inventoros repository. From 2.0.0 on, the updater below installs new releases again.

### Updating from the admin panel

To update an existing installation, sign in as an admin and open Admin then Update. The updater checks for the latest published release, shows you the current and available versions, and applies the update in place. After the new code is extracted, database migrations run automatically so your schema stays in sync.

After an update completes, the application caches are refreshed. If you run a manual install (cPanel or VPS from source), you can also re-cache yourself:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Signed releases

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

This prints a public key (for `INVENTOROS_UPDATE_PUBLIC_KEY`) and a secret key. Keep the secret key out of your repository and your `.env` that ships with the app; it belongs only in your build pipeline.

The Sodium PHP extension must be enabled for signature verification to work.

### Automatic backups

The updater takes a backup before it touches anything, and you can take one yourself from Admin then Update (or with `php artisan app:update --backup`). Backups are ZIP files in `storage/app/backups` holding the application files and the database. The database is captured the best way available:

| Database | Method |
|---|---|
| MySQL / MariaDB | `mysqldump`, or the built-in PHP dump when `exec()` is disabled or `mysqldump` is missing |
| PostgreSQL | `pg_dump`, or the built-in PHP dump |
| SQLite | a consistent snapshot of the database file |

The built-in PHP dump includes the table structure as well as the rows, so restoring it after a failed migration puts the previous schema back. The method used is shown after each backup and written to the log.

If no method can back up the database, the backup fails and the updater stops without changing anything. To accept a files-only backup (for example when you back up the database some other way), set `INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP=true`.

### Manual backups

For your own backups, capture at least two things:

- The database. For MySQL or MariaDB: `mysqldump -u USER -p DBNAME > inventoros-backup.sql`. For PostgreSQL: `pg_dump -U USER DBNAME > inventoros-backup.sql`.
- The application files, especially `.env` and the `storage/` directory (uploaded files and logs).

A quick file snapshot on a VPS:

```bash
tar -czf inventoros-files-backup.tar.gz /var/www/inventoros/.env /var/www/inventoros/storage
```

### Restoring

To restore after a failed update, put back your file snapshot and reload the database dump:

```bash
mysql -u USER -p DBNAME < inventoros-backup.sql        # MySQL / MariaDB
psql -U USER -d DBNAME -f inventoros-backup.sql        # PostgreSQL
```

Backups made by the updater are restored from Admin then Update, which replays the database the same way it was captured.

Then re-cache configuration, routes, and views as shown above. Because migrations run forward during an update, restoring the matching database dump alongside the matching code version keeps the two consistent.
