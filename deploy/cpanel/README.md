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
