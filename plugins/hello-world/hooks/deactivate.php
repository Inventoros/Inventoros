<?php

/**
 * Runs once when the plugin is deactivated.
 *
 * Clear caches or stop scheduled work here. Keep data: the plugin may be
 * activated again. The plugin is deactivated even if this file throws.
 */

use Illuminate\Support\Facades\Log;

Log::info('Hello World plugin deactivated.');
