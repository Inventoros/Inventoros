<?php

/**
 * Build (and check) the cPanel release package.
 *
 *   php deploy/cpanel/build-release.php --version=2.0.0 [--out=dist]
 *   php deploy/cpanel/build-release.php --verify=dist/inventoros-cpanel-2.0.0.zip [--version=2.0.0]
 *
 * Run from the repository root after `composer install --no-dev` and
 * `npm run build`. The package holds:
 *
 *   release.json   manifest the in-app updater checks before installing
 *   INSTALL.md
 *   inventoros/    the application (vendor/ included, no dev files)
 *   public_html/   the web root (front controller pointing at ../inventoros)
 *
 * Building always ends with the same verification --verify runs, so a
 * package with dev files, secrets or a missing piece is never produced.
 * Plain PHP (no framework) so CI and a developer machine build identically.
 */

declare(strict_types=1);

const LAYOUT = 'cpanel-v1';

/**
 * Top-level entries of the repository that never go into inventoros/.
 */
const EXCLUDE_TOP = [
    '.git', '.github', '.claude', '.idea', '.vscode', '.fleet', '.nova', '.zed',
    '.phpunit.cache', '.phpunit.result.cache', '.editorconfig', '.gitattributes',
    '.gitignore', '.dockerignore', '.phpactor.json', '.worktrees',
    'node_modules', 'tests', 'e2e', 'screenshots', 'docker', 'docs', 'deploy',
    'public', 'storage', 'dist', 'cpanel-release', 'playwright-report',
    'blob-report', 'test-results',
    'Dockerfile', 'docker-compose.yml', 'docker-compose.prod.yml',
    'phpunit.xml', 'phpunit.mysql.xml', 'phpunit.pgsql.xml', 'playwright.config.ts',
    'auth.json', 'todo.txt', 'Homestead.json', 'Homestead.yaml',
    'jsconfig.json', 'postcss.config.js', 'tailwind.config.js', 'vite.config.js',
    'package.json', 'package-lock.json',
];

/**
 * Web root entries that are runtime state or dev leftovers.
 */
const EXCLUDE_PUBLIC = ['hot', 'storage', 'plugin-assets', 'plugins', 'index.php', '.htaccess'];

/**
 * Paths that must never appear in a package. Checked on every build and by
 * --verify in the release workflow.
 */
const FORBIDDEN = [
    '#(^|/)\.env$#' => '.env file',
    '#(^|/)\.env\.(?!example$)[^/]+$#' => '.env variant (only .env.example ships)',
    '#^inventoros/(tests|e2e|screenshots|docker|docs|deploy|node_modules|dist|storage/(?!(app|app/public|framework|framework/cache|framework/cache/data|framework/sessions|framework/views|logs)/\.gitkeep$))#' => 'dev or runtime directory',
    '#(^|/)node_modules/#' => 'node_modules',
    '#(^|/)\.git/#' => '.git directory',
    '#^inventoros/\.github/#' => '.github directory',
    '#^inventoros/(phpunit[^/]*\.xml|playwright\.config\.[jt]s|Dockerfile|docker-compose[^/]*\.ya?ml|\.dockerignore|auth\.json|\.phpunit\.result\.cache|todo\.txt|\.phpactor\.json|CLAUDE\.md|AGENTS\.md|package(-lock)?\.json|vite\.config\.js)$#' => 'dev file',
    '#^inventoros/bootstrap/cache/(?!\.gitkeep$)#' => 'compiled bootstrap cache',
    '#^public_html/(hot|storage|plugin-assets)(/|$)#' => 'web root runtime state',
    '#\.(sqlite|sqlite3|log)$#' => 'database or log file',
    '#^inventoros/vendor/(phpunit|mockery|fakerphp|laravel/pail|laravel/sail|laravel/boost|laravel/pint|nunomaduro/collision)/#' => 'dev dependency (build with composer install --no-dev)',
];

const REQUIRED = [
    'release.json',
    'INSTALL.md',
    'inventoros/artisan',
    'inventoros/VERSION',
    'inventoros/.env.example',
    'inventoros/bootstrap/app.php',
    'inventoros/vendor/autoload.php',
    'public_html/index.php',
    'public_html/.htaccess',
    'public_html/build/manifest.json',
];

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function option(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

function minimumPhp(string $root): string
{
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
    $constraint = (string) ($composer['require']['php'] ?? '');

    if (! preg_match('/(\d+\.\d+(\.\d+)?)/', $constraint, $m)) {
        fail("Cannot read the PHP requirement from composer.json ('{$constraint}').");
    }

    return $m[1];
}

/**
 * The newest PHP minor every locked package accepts (e.g. "8.4" when a
 * package requires "<8.5.0"), or null when nothing caps it. Override with
 * INVENTOROS_MAX_PHP=X.Y, or INVENTOROS_MAX_PHP=none for no ceiling.
 */
function maximumPhp(string $root): ?string
{
    $override = getenv('INVENTOROS_MAX_PHP');
    if (is_string($override) && $override !== '') {
        return strtolower($override) === 'none' ? null : $override;
    }

    $lock = json_decode((string) @file_get_contents($root.'/composer.lock'), true);
    $ceiling = null;

    foreach ($lock['packages'] ?? [] as $package) {
        $constraint = (string) ($package['require']['php'] ?? '');
        // Only "<X.Y" / "<X.Y.0" upper bounds translate to a whole minor.
        if (preg_match_all('/<\s*(\d+)\.(\d+)(?:\.0)?(?![\d.])/', $constraint, $matches, PREG_SET_ORDER) === 0) {
            continue;
        }
        foreach ($matches as $m) {
            [$major, $minor] = [(int) $m[1], (int) $m[2]];
            if ($minor === 0) {
                continue;
            }
            $candidate = $major.'.'.($minor - 1);
            if ($ceiling === null || version_compare($candidate, $ceiling, '<')) {
                $ceiling = $candidate;
            }
        }
    }

    return $ceiling;
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        removeTree($path.'/'.$entry);
    }
    if (! @rmdir($path)) {
        fail("Cannot remove {$path} (on Windows, use a short --out path to stay under MAX_PATH).");
    }
}

/**
 * Copy a tree, skipping symlinks, nested node_modules/.git and anything the
 * $skip callback rejects (given the path relative to $from).
 */
function copyTree(string $from, string $to, callable $skip, string $relative = ''): void
{
    if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
        fail("Cannot create {$to}");
    }

    foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
        $source = $from.'/'.$entry;
        $rel = ltrim($relative.'/'.$entry, '/');

        if (is_link($source) || in_array($entry, ['node_modules', '.git'], true) || $skip($rel)) {
            continue;
        }

        if (is_dir($source)) {
            copyTree($source, $to.'/'.$entry, $skip, $rel);
        } elseif (! copy($source, $to.'/'.$entry)) {
            fail("Cannot copy {$source}");
        }
    }
}

/**
 * @return array<int, string> Files under $dir, relative, with '/' separators
 */
function listFiles(string $dir, string $prefix = ''): array
{
    $files = [];
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        $path = $dir.'/'.$entry;
        $rel = $prefix === '' ? $entry : $prefix.'/'.$entry;
        if (is_dir($path)) {
            $files = array_merge($files, listFiles($path, $rel));
        } else {
            $files[] = $rel;
        }
    }

    return $files;
}

function build(string $root, string $version, string $out): string
{
    foreach (['vendor/autoload.php', 'public/build/manifest.json', 'deploy/cpanel/index.php', 'deploy/cpanel/.htaccess', 'deploy/cpanel/INSTALL.md'] as $needed) {
        if (! is_file($root.'/'.$needed)) {
            fail("{$needed} is missing. Run composer install --no-dev and npm run build first.");
        }
    }

    $staging = $out.'/cpanel-release';
    removeTree($staging);
    @mkdir($out, 0755, true);

    // inventoros/: the application.
    copyTree($root, $staging.'/inventoros', function (string $rel): bool {
        $top = explode('/', $rel)[0];
        if (in_array($top, EXCLUDE_TOP, true)) {
            return true;
        }
        if (! str_contains($rel, '/')) {
            // Top-level markdown (README, CHANGELOG, ...) and env files.
            if (str_ends_with($rel, '.md') || ($rel !== '.env.example' && preg_match('/^\.env(\..+)?$/', $rel))) {
                return true;
            }
        }
        if (str_starts_with($rel, 'bootstrap/cache/') && $rel !== 'bootstrap/cache/.gitignore') {
            return true;
        }

        return str_starts_with($rel, 'plugins/') && str_contains($rel, '/ui/node_modules');
    });

    foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
        @mkdir($staging.'/inventoros/storage/'.$dir, 0755, true);
    }
    foreach (['app', 'app/public', 'framework', 'framework/cache', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
        touch($staging.'/inventoros/storage/'.$dir.'/.gitkeep');
    }
    @mkdir($staging.'/inventoros/bootstrap/cache', 0755, true);
    touch($staging.'/inventoros/bootstrap/cache/.gitkeep');
    @unlink($staging.'/inventoros/bootstrap/cache/.gitignore');

    file_put_contents($staging.'/inventoros/VERSION', $version);

    // public_html/: the web root.
    copyTree($root.'/public', $staging.'/public_html', fn (string $rel): bool => in_array(explode('/', $rel)[0], EXCLUDE_PUBLIC, true));
    copy($root.'/deploy/cpanel/index.php', $staging.'/public_html/index.php');
    copy($root.'/deploy/cpanel/.htaccess', $staging.'/public_html/.htaccess');

    copy($root.'/deploy/cpanel/INSTALL.md', $staging.'/INSTALL.md');

    $commit = getenv('GITHUB_SHA') ?: trim((string) @shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));

    file_put_contents($staging.'/release.json', json_encode([
        'name' => 'inventoros',
        'version' => $version,
        'layout' => LAYOUT,
        'min_php' => minimumPhp($root),
        'max_php' => maximumPhp($root),
        'commit' => $commit !== '' ? $commit : null,
        'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $zipPath = $out.'/inventoros-cpanel-'.$version.'.zip';
    @unlink($zipPath);

    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("Cannot create {$zipPath}");
    }
    $files = listFiles($staging);
    foreach ($files as $file) {
        $zip->addFile($staging.'/'.$file, $file);
        if ($file === 'inventoros/artisan') {
            $zip->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, 0100755 << 16);
        }
    }
    if (! $zip->close()) {
        fail("Cannot write {$zipPath}");
    }

    $check = new ZipArchive;
    if ($check->open($zipPath) !== true || $check->numFiles !== count($files)) {
        fail('The package holds '.($check->numFiles ?? 0).' entries but '.count($files).' files were staged.');
    }
    $check->close();

    removeTree($staging);

    return $zipPath;
}

/**
 * @return array<int, string> Problems found (empty = the package is good)
 */
function verify(string $zipPath, ?string $version): array
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        return ["Cannot open {$zipPath}"];
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }

    $problems = [];
    foreach ($names as $name) {
        if (str_contains($name, '\\')) {
            $problems[] = "{$name}: backslash in entry name";
        }
        foreach (FORBIDDEN as $pattern => $why) {
            if (preg_match($pattern, $name)) {
                $problems[] = "{$name}: {$why}";
                break;
            }
        }
        $top = explode('/', $name)[0];
        if (! in_array($top, ['inventoros', 'public_html', 'release.json', 'INSTALL.md'], true)) {
            $problems[] = "{$name}: unexpected top-level entry";
        }
    }

    foreach (REQUIRED as $required) {
        if (! in_array($required, $names, true)) {
            $problems[] = "{$required}: missing";
        }
    }

    $manifest = json_decode((string) $zip->getFromName('release.json'), true);
    if (! is_array($manifest)) {
        $problems[] = 'release.json: not valid JSON';
    } else {
        if (($manifest['layout'] ?? null) !== LAYOUT) {
            $problems[] = 'release.json: layout must be '.LAYOUT;
        }
        if (empty($manifest['min_php'])) {
            $problems[] = 'release.json: min_php missing';
        }
        if ($version !== null && ($manifest['version'] ?? null) !== $version) {
            $problems[] = 'release.json: version is '.var_export($manifest['version'] ?? null, true).", expected {$version}";
        }
        if (($manifest['version'] ?? null) !== trim((string) $zip->getFromName('inventoros/VERSION'))) {
            $problems[] = 'inventoros/VERSION does not match release.json';
        }
        if ('inventoros-cpanel-'.($manifest['version'] ?? '').'.zip' !== basename($zipPath)) {
            $problems[] = 'file name does not match release.json version';
        }
    }

    $index = (string) $zip->getFromName('public_html/index.php');
    if (! preg_match('/^[ \t]*\$laravelPath\s*=\s*[^;]+;/m', $index)) {
        $problems[] = 'public_html/index.php: no $laravelPath line for the updater to rewrite';
    }
    if (! str_contains($index, 'version_compare(PHP_VERSION')) {
        $problems[] = 'public_html/index.php: missing the PHP version check';
    }

    $zip->close();

    return $problems;
}

$options = getopt('', ['version:', 'out:', 'verify:']);
$root = dirname(__DIR__, 2);
$version = option($options, 'version');
if ($version !== null) {
    $version = ltrim($version, 'v');
}

if (($target = option($options, 'verify')) !== null) {
    $zipPath = $target;
} else {
    // X.Y.Z (optionally -rc.1 etc.), or dev-<timestamp> for untagged CI builds.
    if ($version === null || ! preg_match('/^(\d+\.\d+\.\d+([-.+][0-9A-Za-z.-]+)?|dev-\d+)$/', $version)) {
        fail('Pass --version=X.Y.Z (e.g. --version=2.0.0).');
    }
    $out = option($options, 'out') ?? $root.'/dist';
    $zipPath = build($root, $version, $out);
    echo "Built {$zipPath}\n";
}

$problems = verify($zipPath, $version);
if ($problems !== []) {
    fwrite(STDERR, 'The package '.basename($zipPath)." failed verification:\n  - ".implode("\n  - ", $problems)."\n");
    exit(1);
}

echo 'Verified '.basename($zipPath).': layout '.LAYOUT.", no forbidden paths.\n";
