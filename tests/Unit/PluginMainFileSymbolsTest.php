<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Plugins\PluginMainFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A plugin main file that declares named symbols at its top level cannot be
 * required twice in one process; everything else can.
 */
final class PluginMainFileSymbolsTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function files(): array
    {
        return [
            'closures only' => ["<?php\nadd_action('x', function () {});\n\$f = fn () => 1;\n", false],
            'registration closure' => ["<?php\nreturn function (string \$slug): void { register_page('a', 'b'); };\n", false],
            'anonymous class' => ["<?php\n\$o = new class { public function run() {} };\n", false],
            'class constant' => ["<?php\n\$n = Foo::class;\n", false],
            'guarded function' => ["<?php\nif (! function_exists('p')) {\n    function p() {}\n}\n", false],
            'function inside a closure' => ["<?php\nreturn function () { class_exists('X'); };\n", false],
            'named function' => ["<?php\nfunction my_plugin_helper() {}\n", true],
            'by-reference function' => ["<?php\nfunction &my_plugin_ref() { static \$a; return \$a; }\n", true],
            'class' => ["<?php\nnamespace MyPlugin;\nclass Thing {}\n", true],
            'final class' => ["<?php\nfinal class Thing {}\n", true],
            'interface' => ["<?php\ninterface Thing {}\n", true],
            'enum' => ["<?php\nenum Colour { case Red; }\n", true],
            'braced namespace' => ["<?php\nnamespace MyPlugin {\n    function helper() {}\n}\n", true],
        ];
    }

    #[DataProvider('files')]
    public function test_it_detects_top_level_declarations(string $code, bool $declares): void
    {
        $file = tempnam(sys_get_temp_dir(), 'plugin');
        file_put_contents($file, $code);

        try {
            $this->assertSame($declares, PluginMainFile::declaresTopLevelSymbols($file));
        } finally {
            unlink($file);
        }
    }
}
