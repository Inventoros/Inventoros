Inventoros has a built-in updater so you can apply new releases from the admin panel, and it verifies that each release is signed before installing it. This section covers updating, the signature model, and backups.

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

### Backups before you update

Always back up before applying an update. At minimum, capture two things:

- The database. For MySQL or MariaDB: `mysqldump -u USER -p DBNAME > inventoros-backup.sql`.
- The application files, especially `.env` and the `storage/` directory (uploaded files and logs).

A quick file snapshot on a VPS:

```bash
tar -czf inventoros-files-backup.tar.gz /var/www/inventoros/.env /var/www/inventoros/storage
```

### Restoring

To restore after a failed update, put back your file snapshot and reload the database dump:

```bash
mysql -u USER -p DBNAME < inventoros-backup.sql
```

Then re-cache configuration, routes, and views as shown above. Because migrations run forward during an update, restoring the matching database dump alongside the matching code version keeps the two consistent.
