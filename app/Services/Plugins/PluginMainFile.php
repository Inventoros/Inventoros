<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use PhpToken;
use WeakMap;

/**
 * Runs a plugin's main file once per application instance.
 *
 * A PHP process can boot more than one application: `route:cache` and
 * `optimize` build a fresh one to collect the routes they cache, and the test
 * suite boots one per test. Everything a plugin registers (hooks, pages, menu
 * items, widgets) lives in per-application singletons, so the main file has
 * to run again in each one. `require_once` skipped it, which left plugin
 * pages out of every route cache.
 *
 * - A main file that returns a closure is required once per process and its
 *   closure is called once per application (the recommended shape).
 * - Any other main file is required again in each application, unless it
 *   declares named functions, classes, interfaces, traits or enums at its top
 *   level: re-running those is a fatal error, so the file is skipped (with a
 *   warning) in every application after the first.
 */
final class PluginMainFile
{
    /**
     * What each main file returned the first time it ran in this process.
     *
     * @var array<string, Closure|null>
     */
    private static array $registrations = [];

    /**
     * Slugs already loaded, per application instance.
     *
     * @var WeakMap<Application, array<string, true>>|null
     */
    private static ?WeakMap $loaded = null;

    public static function isLoaded(Application $app, string $slug): bool
    {
        return isset(self::loaded()[$app][$slug]);
    }

    /**
     * Run the plugin's registrations in $app (once).
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function load(Application $app, string $slug, string $file, array $manifest): void
    {
        if (self::isLoaded($app, $slug)) {
            return;
        }

        if (! array_key_exists($file, self::$registrations)) {
            $result = self::requireFile($file, $slug, $manifest);
            self::$registrations[$file] = $result instanceof Closure ? $result : null;

            if ($result instanceof Closure) {
                $result($slug, $manifest);
            }
        } elseif (self::$registrations[$file] !== null) {
            (self::$registrations[$file])($slug, $manifest);
        } elseif (self::declaresTopLevelSymbols($file)) {
            Log::warning(
                'Plugin main file declares named functions or classes, so it cannot run again in a second application '
                .'in this PHP process (for example the one route:cache boots); its pages and hooks are missing there. '
                .'Return a registration closure from the main file instead.',
                ['slug' => $slug],
            );
        } else {
            self::requireFile($file, $slug, $manifest);
        }

        self::markLoaded($app, $slug);
    }

    /**
     * Whether the file declares a named function, class, interface, trait or
     * enum outside any block (so it would be redeclared by a second require).
     * Declarations inside `if (! function_exists(...)) { ... }` and similar
     * blocks are fine; braces of a `namespace X { }` block do not count as a
     * block.
     */
    public static function declaresTopLevelSymbols(string $file): bool
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return false;
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            fn (PhpToken $t) => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        /** @var array<int, bool> $blocks true = a namespace block's brace */
        $blocks = [];
        $namespaceBraceNext = false;

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespaceBraceNext = true;
            } elseif ($token->text === ';') {
                $namespaceBraceNext = false;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $blocks[] = $namespaceBraceNext && $token->text === '{';
                $namespaceBraceNext = false;

                continue;
            }

            if ($token->text === '}') {
                array_pop($blocks);

                continue;
            }

            if (in_array(false, $blocks, true)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if ($token->is(T_FUNCTION)) {
                $name = $next !== null && $next->text === '&' ? ($tokens[$i + 2] ?? null) : $next;
                if ($name !== null && $name->is(T_STRING)) {
                    return true;
                }

                continue;
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
                && ! ($previous !== null && $previous->is([T_DOUBLE_COLON, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR]))
                && $next !== null && $next->is(T_STRING)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Require the file with the variables plugins have always seen in scope.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function requireFile(string $__pluginFile, string $slug, array $manifest): mixed
    {
        return require $__pluginFile;
    }

    private static function markLoaded(Application $app, string $slug): void
    {
        $loaded = self::loaded();
        $loaded[$app] = ($loaded[$app] ?? []) + [$slug => true];
    }

    /**
     * @return WeakMap<Application, array<string, true>>
     */
    private static function loaded(): WeakMap
    {
        return self::$loaded ??= new WeakMap;
    }
}
