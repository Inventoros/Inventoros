<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards templates and front-end sources against mojibake: text that was
 * decoded with the wrong charset at some point and saved back, leaving
 * replacement characters, stray control bytes, or UTF-8 read as Latin-1
 * (for example the "=\u{FFFD}" that replaced the printer emoji on the barcode
 * print page).
 */
class SourceEncodingTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['resources/views', 'resources/js'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(php|vue|js|ts|json)$/', $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_templates_and_front_end_sources_contain_no_mojibake(): void
    {
        $problems = [];

        foreach ($this->files() as $path) {
            $contents = (string) file_get_contents($path);

            if (! mb_check_encoding($contents, 'UTF-8')) {
                $problems[] = "{$path}: not valid UTF-8";

                continue;
            }

            // U+FFFD replacement char, C0 control bytes other than tab/LF/CR,
            // and the usual UTF-8-read-as-Latin-1 lead sequences.
            if (preg_match('/\x{FFFD}|[\x00-\x08\x0B\x0C\x0E-\x1F]|Ã[\x{80}-\x{BF}]|â€|ðŸ/u', $contents, $m, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($contents, 0, $m[0][1]), "\n") + 1;
                $problems[] = "{$path}:{$line}";
            }
        }

        $this->assertSame([], $problems, "Mojibake found in:\n".implode("\n", $problems));
    }
}
