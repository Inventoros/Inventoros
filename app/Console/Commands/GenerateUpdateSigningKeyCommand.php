<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Mint an Ed25519 keypair for signing update releases.
 *
 * The PUBLIC key goes in INVENTOROS_UPDATE_PUBLIC_KEY (committed config / app
 * .env) so every install can verify downloads. The SECRET key is shown once —
 * store it in the release-signing environment (e.g. a CI secret) and NEVER
 * commit it. Sign release archives with `php artisan update:sign <file>`.
 */
final class GenerateUpdateSigningKeyCommand extends Command
{
    protected $signature = 'update:signing-keypair';

    protected $description = 'Generate an Ed25519 keypair for signing update releases';

    public function handle(): int
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->error('libsodium is required to generate signing keys but is not available.');

            return self::FAILURE;
        }

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $this->info('Update signing keypair generated.');
        $this->newLine();

        $this->line('Public key (commit to config / set on every install):');
        $this->line('  INVENTOROS_UPDATE_PUBLIC_KEY='.base64_encode($publicKey));
        $this->newLine();

        $this->line('Secret key (store as a release-signing secret — DO NOT COMMIT):');
        $this->line('  INVENTOROS_UPDATE_SECRET_KEY='.base64_encode($secretKey));
        $this->newLine();

        $this->warn('The secret key is shown only once. Save it now.');

        sodium_memzero($secretKey);
        sodium_memzero($keypair);

        return self::SUCCESS;
    }
}
