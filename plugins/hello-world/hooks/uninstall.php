<?php

/**
 * Runs once when the plugin is deleted, whether or not it was active.
 *
 * Drop the plugin's tables and remove its settings here. The plugin files are
 * removed even if this file throws, so do the important cleanup first.
 */

use Illuminate\Support\Facades\Log;

Log::info('Hello World plugin uninstalled.');
