<?php

/**
 * Hello World Plugin - Deactivation Hook
 *
 * This runs when you deactivate the plugin. We're not crying, you're crying. 😢
 * Just kidding, we don't have feelings. We're code.
 */

use Illuminate\Support\Facades\Log;

Log::info('😴 Hello World Plugin: Deactivated. Taking a nap now. Wake me if you need me!');

// In a real plugin, you might:
// - Clean up temporary data
// - Remove scheduled tasks
// - Cancel subscriptions
// - Say goodbye to users
// - Feel sad (but plugins can't feel emotions, so that's good)
