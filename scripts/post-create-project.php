<?php

/*
 * Runs once after `composer create-project inventoros/inventoros`, from the
 * new project's folder. .env has already been copied from .env.example and
 * APP_KEY generated. The database is left alone on purpose: the web
 * installer at /install sets it up, along with the first administrator.
 */

$directory = basename(getcwd() ?: '.');
$frontendBuilt = is_file(__DIR__.'/../public/build/manifest.json');

$steps = [];

if (! $frontendBuilt) {
    $steps[] = 'Build the frontend (Node.js 22.12 or newer):'
        .PHP_EOL.'       npm ci && npm run build';
}

$steps[] = 'Point your web server at the public/ folder, or try it locally with:'
    .PHP_EOL.'       php artisan serve';

$steps[] = 'Open /install in your browser to finish with the web installer.'
    .PHP_EOL.'       It asks for a setup token: set INVENTOROS_SETUP_TOKEN in .env, or use'
    .PHP_EOL.'       the one written to storage/app/private/setup-token when /install is first opened.';

$lines = ['', 'Inventoros is downloaded. To finish setting it up, from the '.$directory.' folder:', ''];

foreach ($steps as $number => $step) {
    $lines[] = '  '.($number + 1).'. '.$step;
}

$lines[] = '';
$lines[] = 'For a production server you will also want the queue worker and the scheduler:';
$lines[] = 'https://inventoros.com/docs/installation-vps';
$lines[] = '';

echo implode(PHP_EOL, $lines).PHP_EOL;
