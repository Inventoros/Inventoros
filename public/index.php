<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// Refuse an unsupported PHP with a plain message before Composer's platform
// check (or newer syntax) fails cryptically. Keep this block PHP 5 safe and
// in step with composer.json's "php" requirement.
if (version_compare(PHP_VERSION, '8.4.1', '<')) {
    if (! headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
    }
    echo 'Inventoros requires PHP 8.4.1 or newer. This server runs PHP '.PHP_VERSION.'.'.PHP_EOL
        .'Switch this site to PHP 8.4 or newer (in cPanel: MultiPHP Manager) and reload the page.'.PHP_EOL;
    exit(1);
}

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
