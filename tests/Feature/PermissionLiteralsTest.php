<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every permission string checked by a GraphQL resolver or an MCP tool must
 * be a real Permission enum case. A misspelt or invented name can never be
 * granted (roles and token abilities are validated against the enum), so a
 * check on one silently denies everyone, or, when OR-ed with a real name,
 * quietly collapses to the other permission. The REST routes have the same
 * guard in Api\ApiRoutePermissionNamesTest.
 */
class PermissionLiteralsTest extends TestCase
{
    /**
     * A string literal, or an array made only of string literals. Computed
     * arguments (e.g. ApprovalService::permissionFor()) return an enum case
     * and cannot name a missing permission.
     */
    private const LITERALS = "(\[\s*'[a-z_]+'(?:\s*,\s*'[a-z_]+')*\s*,?\s*\]|'[a-z_]+')";

    /**
     * Permission checks as they appear in resolvers and tools: an authorize()
     * or has*Permission() call, or a GraphQL field's permissions() list.
     *
     * @return array<int, string>
     */
    private static function patterns(): array
    {
        return [
            '/(?:authorize|hasPermission|hasAnyPermission|hasAllPermissions)\(\s*'.self::LITERALS.'/',
            '/function permissions\(\): array\s*\{\s*return\s*'.self::LITERALS.'/',
        ];
    }

    public function test_graphql_and_mcp_check_only_real_permissions(): void
    {
        $valid = array_column(Permission::cases(), 'value');
        $found = [];

        foreach (['app/GraphQL', 'app/Mcp'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = $file->getContents();
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                foreach (self::patterns() as $pattern) {
                    preg_match_all($pattern, $source, $calls);

                    foreach ($calls[1] as $argument) {
                        preg_match_all("/'([a-z_]+)'/", $argument, $names);

                        foreach ($names[1] as $name) {
                            $found[] = [$relative, $name];
                        }
                    }
                }
            }
        }

        // Guard the scanner itself: if the patterns stop matching, the test
        // must fail rather than pass on an empty list.
        $this->assertGreaterThanOrEqual(50, count($found), 'Expected to find the permission checks in app/GraphQL and app/Mcp.');

        $unknown = array_values(array_filter($found, fn (array $hit) => ! in_array($hit[1], $valid, true)));

        $this->assertSame([], array_map(fn (array $hit) => "{$hit[0]}: {$hit[1]}", $unknown), 'Permission checks naming a permission that does not exist.');
    }
}
