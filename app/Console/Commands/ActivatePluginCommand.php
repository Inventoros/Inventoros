<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PluginService;
use Illuminate\Console\Command;

/**
 * Activate an installed plugin from the command line, running the same
 * lifecycle as the Plugins page (requirements, activate hook, UI publish).
 */
final class ActivatePluginCommand extends Command
{
    protected $signature = 'plugin:activate {slug : The plugin folder name under /plugins}';

    protected $description = 'Activate an installed plugin';

    public function handle(PluginService $plugins): int
    {
        $slug = (string) $this->argument('slug');

        try {
            $plugins->activatePlugin($slug);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin {$slug} activated.");

        return self::SUCCESS;
    }
}
