# cPanel Deployment Files

These files are templates for deploying Inventoros on cPanel shared hosting.

## Automated Builds

The GitHub Actions workflow at `.github/workflows/cpanel-release.yml` automatically creates a cPanel-ready zip file when:

1. **Tagged releases** - Push a tag like `v1.0.0` to trigger a release build
2. **Manual trigger** - Use "Actions" tab → "Build cPanel Release" → "Run workflow"

The package is built by `build-release.php` in this folder, which CI and a
developer machine run the same way (after `composer install --no-dev` and
`npm run build`):

```bash
php deploy/cpanel/build-release.php --version=2.0.0 --out=dist
php deploy/cpanel/build-release.php --verify=dist/inventoros-cpanel-2.0.0.zip --version=2.0.0
```

It writes `release.json` (version, layout `cpanel-v1`, `min_php` from
composer.json, `max_php` from the locked dependencies) for the in-app updater,
and fails if the zip holds `.env` files, tests, dev dependencies, runtime
state or is missing a required file. On Windows use a short `--out` path.

## Directory Structure

The release package creates this structure:

```
your-home-directory/
├── inventoros/           <- Laravel application (outside web root)
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   └── ...
└── public_html/          <- Web root (document root)
    ├── index.php         <- Points to ../inventoros
    ├── build/            <- Compiled assets
    ├── .htaccess
    └── ...
```

## Manual Deployment

If you need to deploy manually without the GitHub release:

1. Upload all files except `public/` to `/home/username/inventoros/`
2. Upload `public/` contents to `/home/username/public_html/`
3. Replace `public_html/index.php` with the one in this directory
4. Copy the `.htaccess` from this directory to `public_html/`

## Files in this Directory

- `index.php` - Modified entry point that references `../inventoros` (the updater rewrites `$laravelPath` to the real folder)
- `.htaccess` - Apache rewrite rules for Laravel
- `INSTALL.md` - The installation guide shipped in the package
- `build-release.php` - Builds and verifies the package

## SSH release updates

For an installed cPanel site, copy `ssh-settings.example.json` to `ssh-settings.local.json` and set the SSH config alias, application directory and PHP binary. The local file is ignored by Git. Configure key authentication and verify the server host key in your SSH client first; the helper requires an existing trusted host key. Keep private keys in your SSH agent/configuration.

```bash
php deploy/cpanel/ssh-update.php          # print the plan; no connection
php deploy/cpanel/ssh-update.php --check  # read the server update status
php deploy/cpanel/ssh-update.php --apply  # run the interactive updater
```

`--apply` requires a clean local `main` matching the freshly fetched `origin/main`. It opens an SSH terminal and runs `php artisan app:update` in the configured application directory. Review the release and confirm in that terminal. The existing updater handles signed-package verification, backup, maintenance, migrations and rollback. Publish a release from main before updating the server; the helper installs published releases using the site's update configuration. A nonstandard local settings path can be supplied with `--settings=/path/to/settings.json`.

The packaging workflow builds PR packages for review, and only signs and publishes releases whose commit is merged into main. Manual release tags point to the exact commit that was packaged.
