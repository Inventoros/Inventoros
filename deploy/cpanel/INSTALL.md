# Inventoros - cPanel Installation Guide

This package is built for cPanel shared hosting. It needs PHP 8.4.1 or newer
(`release.json` in this package names the exact range).

## Directory Structure

```
/home/username/
├── inventoros/          <- Laravel application files
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   └── ...
└── public_html/         <- Web-accessible files
    ├── index.php        <- Points to ../inventoros
    ├── build/           <- Compiled frontend assets
    ├── .htaccess
    └── ...
```

If your application folder is not `~/inventoros`, edit the `$laravelPath`
line in `public_html/index.php`. The in-app updater keeps it pointed at the
real folder.

## Installation Steps

1. **Upload Files**
   - Upload the `inventoros` folder to your home directory (e.g. `/home/username/inventoros`)
   - Upload the contents of `public_html` to your `public_html` directory

2. **Set Permissions**
   ```bash
   chmod -R 755 ~/inventoros
   chmod -R 775 ~/inventoros/storage
   chmod -R 775 ~/inventoros/bootstrap/cache
   ```

3. **Configure Environment**
   ```bash
   cd ~/inventoros
   cp .env.example .env
   nano .env
   ```
   Set `APP_PUBLIC_PATH=../public_html` (the web installer does this for
   you) so command-line tasks write to the same web root the site serves.

4. **Generate Application Key**
   ```bash
   php artisan key:generate
   ```

5. **Run Migrations**
   ```bash
   php artisan migrate
   ```

6. **Create Storage Link**
   ```bash
   ln -s ~/inventoros/storage/app/public ~/public_html/storage
   ```

7. **Add the cron job** (cPanel > Cron Jobs, every minute)
   ```
   * * * * * cd ~/inventoros && php artisan schedule:run >> /dev/null 2>&1
   ```
   The scheduler also works the queue (emails, webhooks) on hosts without
   a queue worker.

8. **Cache configuration**
   ```bash
   php artisan optimize
   ```

## Updating

Use **Admin > Updates**. The updater downloads `inventoros-cpanel-<version>.zip`,
verifies its signature and `release.json`, backs up files and database, and
installs `inventoros/` into the app folder and `public_html/` into the web
root. Your `.env`, `storage/`, installed plugins, `public_html/plugin-assets/`
and an existing `.htaccess` are never overwritten. If anything fails the
backup is restored.

Upgrading from 1.0.x? The 1.0.x in-app updater cannot install releases, so
upgrade by hand once: see UPGRADE.md in the repository.

## Troubleshooting

### 500 Internal Server Error
- Check file permissions on storage and bootstrap/cache
- Verify .env file exists and has correct database settings
- Check ~/inventoros/storage/logs/laravel.log for errors

### "Inventoros requires PHP 8.4.1 or newer"
- Switch the site to PHP 8.4 in cPanel > MultiPHP Manager

### "Table already exists" in the web installer
- An earlier attempt stopped part-way through creating the tables (often the
  host's PHP time limit). Use **Reset database and install** on the database
  step: it deletes every table in that database and starts again, so only use
  it on a database that holds nothing but Inventoros.

### Assets Not Loading
- Ensure the `build` folder was uploaded to public_html
- Check that .htaccess is present in public_html

## Support

For issues, please visit: https://github.com/Inventoros/Inventoros/issues
