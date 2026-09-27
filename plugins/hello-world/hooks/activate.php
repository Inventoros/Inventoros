<?php

/**
 * Runs once when the plugin is activated, after Plugin.php has loaded.
 *
 * This is where a real plugin would create its tables or default settings.
 * If this file throws, activation is aborted and the plugin stays inactive,
 * so keep it idempotent (check before you create).
 */

use Illuminate\Support\Facades\Log;

Log::info('Hello World plugin activated.');
