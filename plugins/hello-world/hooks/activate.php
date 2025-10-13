<?php

/**
 * Hello World Plugin - Activation Hook
 *
 * This runs when you activate the plugin. It does... well, you guessed it - nothing!
 * But it COULD do things. Important things. Like create database tables or
 * configure settings. This plugin just says hi though. 👋
 */

use Illuminate\Support\Facades\Log;

Log::info('🎉 Hello World Plugin: Activated! Time to do absolutely nothing productive.');

// In a real plugin, you might:
// - Create database tables
// - Set up initial configuration
// - Register scheduled tasks
// - Send welcome notifications
// - Actually do useful things (unlike this plugin)
