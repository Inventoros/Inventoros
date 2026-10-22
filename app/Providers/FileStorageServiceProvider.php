<?php

declare(strict_types=1);

namespace App\Providers;

use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class FileStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('s3', function ($app, array $config) {
            $disk = $app['filesystem']->createS3Driver($config);
            if ($config['omit_acl'] ?? false) {
                // Flysystem supplies a private ACL even when none was requested.
                // Modern S3 buckets and R2 instead use bucket/IAM policies.
                $disk->getClient()->getHandlerList()->appendInit(Middleware::mapCommand(function (CommandInterface $command) {
                    if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload', 'CopyObject'], true)) {
                        unset($command['ACL']);
                    }

                    return $command;
                }), 'inventoros_omit_acl');
            }

            return $disk;
        });
    }
}
