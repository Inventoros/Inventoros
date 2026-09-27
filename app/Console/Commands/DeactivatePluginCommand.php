<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PluginHookFailed;
use App\Services\PluginService;
use Illuminate\Console\Command;

/**
 * Deactivate a plugin from the command line, running the same lifecycle as
 * the Plugins page (deactivate hook, UI assets removed).
 */
final class DeactivatePluginCommand extends Command
{
    protected $signature = 'plugin:deactivate {slug : The plugin folder name under /plugins}';

    protected $description = 'Deactivate a plugin';

    public function handle(PluginService $plugins): int
    {
        $slug = (string) $this->argument('slug');

        try {
            $plugins->deactivatePlugin($slug);
        } catch (PluginHookFailed $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin {$slug} deactivated.");

        return self::SUCCESS;
    }
}
