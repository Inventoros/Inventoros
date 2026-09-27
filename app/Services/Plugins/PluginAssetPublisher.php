<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use Illuminate\Support\Facades\File;
use RuntimeException;
use SplFileInfo;

/**
 * Publishes a plugin's pre-built runtime UI (its `dist/` directory) to
 * `public/plugins/{slug}/` so the browser can import() it, and removes it again.
 *
 * The plugin declares its bundle in plugin.json:
 *
 *     "ui": { "entry": "plugin.js", "styles": ["plugin.css"] }
 *
 * Paths are relative to `dist/`. Only static web assets are copied (no PHP,
 * no dotfiles such as .htaccess, no symlinks), so publishing can never make
 * new server-side code reachable from the web root.
 */
final class PluginAssetPublisher
{
    public const SOURCE_DIRECTORY = 'dist';

    /**
     * Marker written next to the published files, holding the plugin version
     * they came from, so a plugin replaced in place is republished.
     */
    private const VERSION_MARKER = '.published-version';

    /**
     * File types that may be served from public/plugins.
     *
     * @var array<int, string>
     */
    private const ALLOWED_EXTENSIONS = [
        'js', 'mjs', 'css', 'map', 'json',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'txt',
    ];

    public function __construct(
        private readonly string $pluginsPath,
        private readonly string $publicRoot,
    ) {}

    public static function make(): self
    {
        return new self(base_path('plugins'), public_path('plugins'));
    }

    /**
     * Validate the manifest's `ui` block. Returns null when the plugin has no
     * runtime UI, otherwise the entry and style paths relative to dist/.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{entry: string, styles: array<int, string>}|null
     *
     * @throws RuntimeException When the block is malformed or points outside dist/.
     */
    public function uiFor(string $slug, array $manifest): ?array
    {
        if (! array_key_exists('ui', $manifest) || $manifest['ui'] === null) {
            return null;
        }

        $ui = $manifest['ui'];

        if (! is_array($ui) || ! is_string($ui['entry'] ?? null)) {
            throw new RuntimeException('Invalid plugin ui manifest: "ui.entry" must name a JavaScript file inside dist/.');
        }

        $styles = $ui['styles'] ?? [];
        if (! is_array($styles)) {
            throw new RuntimeException('Invalid plugin ui manifest: "ui.styles" must be a list of CSS files inside dist/.');
        }

        $entry = $this->assertInsideDist($slug, $ui['entry'], ['js', 'mjs'], 'ui.entry');

        $checkedStyles = [];
        foreach ($styles as $style) {
            if (! is_string($style)) {
                throw new RuntimeException('Invalid plugin ui manifest: "ui.styles" must be a list of CSS files inside dist/.');
            }
            $checkedStyles[] = $this->assertInsideDist($slug, $style, ['css'], 'ui.styles');
        }

        return ['entry' => $entry, 'styles' => $checkedStyles];
    }

    /**
     * Copy dist/ to public/plugins/{slug}/, replacing any previous copy.
     */
    public function publish(string $slug, string $version = ''): void
    {
        $source = realpath($this->pluginsPath.'/'.$slug.'/'.self::SOURCE_DIRECTORY);
        if ($source === false || ! is_dir($source)) {
            throw new RuntimeException('Plugin ui bundle is missing: expected a dist/ directory.');
        }

        $this->remove($slug);

        $destination = $this->destination($slug);
        File::ensureDirectoryExists($destination);

        $this->copyDirectory($source, $destination);

        File::put($destination.DIRECTORY_SEPARATOR.self::VERSION_MARKER, $version);
    }

    /**
     * Remove public/plugins/{slug}/ if present.
     */
    public function remove(string $slug): void
    {
        $destination = $this->destination($slug);

        if (is_dir($destination)) {
            File::deleteDirectory($destination);
        }
    }

    /**
     * Whether the entry is published and came from this plugin version.
     */
    public function isPublished(string $slug, string $entry, string $version = ''): bool
    {
        $destination = $this->destination($slug);
        $marker = $destination.DIRECTORY_SEPARATOR.self::VERSION_MARKER;

        return is_file($destination.'/'.$entry)
            && is_file($marker)
            && File::get($marker) === $version;
    }

    private function destination(string $slug): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug) !== 1 || str_contains($slug, '..')) {
            throw new RuntimeException('Invalid plugin slug.');
        }

        File::ensureDirectoryExists($this->publicRoot);

        return rtrim($this->publicRoot, '/\\').DIRECTORY_SEPARATOR.$slug;
    }

    /**
     * @param  array<int, string>  $extensions
     */
    private function assertInsideDist(string $slug, string $path, array $extensions, string $field): string
    {
        $invalid = fn () => new RuntimeException(
            "Invalid plugin ui manifest: \"{$field}\" ({$path}) must be a "
            .implode('/', array_map(fn ($e) => '.'.$e, $extensions)).' file inside dist/.'
        );

        $path = trim($path);
        $segments = explode('/', $path);

        if ($path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || in_array('..', $segments, true)
            || in_array('', $segments, true)
            || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
            throw $invalid();
        }

        $dist = realpath($this->pluginsPath.'/'.$slug.'/'.self::SOURCE_DIRECTORY);
        $resolved = realpath($this->pluginsPath.'/'.$slug.'/'.self::SOURCE_DIRECTORY.'/'.$path);

        if ($dist === false || $resolved === false || ! is_file($resolved)
            || ! str_starts_with($resolved, $dist.DIRECTORY_SEPARATOR)) {
            throw $invalid();
        }

        return $path;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        /** @var SplFileInfo $item */
        foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $item) {
            $name = $item->getFilename();

            if (str_starts_with($name, '.') || $item->isLink()) {
                continue;
            }

            $target = $destination.DIRECTORY_SEPARATOR.$name;

            if ($item->isDir()) {
                File::ensureDirectoryExists($target);
                $this->copyDirectory($item->getPathname(), $target);

                continue;
            }

            if (in_array(strtolower($item->getExtension()), self::ALLOWED_EXTENSIONS, true)) {
                File::copy($item->getPathname(), $target);
            }
        }
    }
}
