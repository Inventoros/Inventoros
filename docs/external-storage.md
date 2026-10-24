# External file storage

Inventoros supports local disks, Amazon S3 and S3-compatible services such as
MinIO, Cloudflare R2 and DigitalOcean Spaces. The S3 adapter is included.
Configuration is installation-wide and managed in `.env` / `config/filesystems.php`.

```dotenv
INVENTOROS_UPLOADS_DISK=s3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-private-bucket
# Optional for S3-compatible services:
# AWS_ENDPOINT=https://your-endpoint
# AWS_USE_PATH_STYLE_ENDPOINT=true
# Optional prefix inside the bucket:
# AWS_ROOT=inventoros
```

IAM roles / the AWS default credential provider chain are supported: leave the
access key and secret unset. Temporary credentials can use `AWS_SESSION_TOKEN`.
For R2, use its account endpoint and `AWS_DEFAULT_REGION=auto`; for MinIO, use
the server endpoint and path-style addressing. The endpoint must be reachable
from both the web application and queue workers. Keep TLS certificate verification
enabled. No public bucket policy or public object ACL is needed.
The supplied S3 disk sets `omit_acl` to true, so uploads omit object ACL headers
for AWS Bucket owner enforced buckets and R2. Use this option on additional
private S3 disk definitions too; access is controlled by bucket/IAM policies.

After editing configuration, run `php artisan config:cache` and
`php artisan queue:restart` (restart long-running application servers too).
Open **Settings > File storage** as an installation administrator and select
**Check connection**. The check writes a random private object under
`.inventoros/connection-checks/`, reads it back, then deletes it and confirms it
is gone. Success confirms all three operations, rather than only valid credentials.
If cleanup fails, remove remaining probe objects from that prefix after fixing
permissions. The result is cached for one hour and invalidated by configuration
changes; it records the check time, rather than claiming continuous availability.
Failed checks show the operation, without exposing SDK errors or credentials.

Allow `s3:PutObject`, `s3:GetObject` and `s3:DeleteObject` on the configured
bucket/prefix, including the probe prefix. AWS may also require `s3:ListBucket`
on the bucket for missing-object checks (restrict its prefix condition to the
configured root). The bucket must already exist. Bucket encryption using KMS
also requires the appropriate KMS permissions. Do not grant public access.

The setting covers new product images, organization logos, shipping labels,
queued import uploads and generated export files. `IMPORT_DISK` and `EXPORT_DISK`
remain available as explicit overrides. Synchronous imports/exports and temporary
spreadsheet working files still use local temporary storage. Application code,
plugin packages, logs, sessions and updater backups remain on their existing
storage; `FILESYSTEM_DISK` is independent from uploaded application files.

Existing files are not moved. Their recorded disks are used for reads and deletes;
older product images default to `public`, older logos and labels to `local`.
The web UI streams images and logos through authenticated, tenant-scoped routes,
so private buckets work without changing CSP or exposing signed URLs.
Product API responses also include `image_urls` for authenticated image reads
with the caller's usual `view_products` permission and token. API edits may send
back an existing image's `url` to keep it, or a base64 `preview` for a new upload.

To switch storage again, add a **new named disk** in `config/filesystems.php`
and select it with `INVENTOROS_UPLOADS_DISK`. Keep old disk definitions and
credentials available while records or queued jobs reference them. Do not repoint
an existing disk name to a different bucket/prefix or remove it without migrating
its files and references. Back up both the database and every referenced disk.
Removing the setting sends new files to the original local/public disks while
existing externally stored files continue to use their recorded disk.

Storage checks are restricted to base administrators with `manage_organization`
in the installation's administrator organization (the same organization used for
plugin administration, configurable with `INVENTOROS_PLUGIN_ADMIN_ORG`). Tenant
administrators cannot inspect or probe installation-wide storage.

See [Laravel filesystem configuration](https://laravel.com/docs/13.x/filesystem)
for additional named disk definitions.
