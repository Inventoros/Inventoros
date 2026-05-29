<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;

/**
 * Resolves an export type slug to its concrete FromQuery export instance.
 *
 * Shared by the controller (to count rows / stream synchronously) and the
 * queued job (to regenerate the export from a persisted DataExport record), so
 * the type -> export mapping lives in exactly one place.
 */
final class ExportFactory
{
    /**
     * The export types this application can generate.
     *
     * @var array<int, string>
     */
    public const TYPES = ['products', 'orders', 'users'];

    /**
     * Build the export instance for a given type.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function make(string $type, int $organizationId, array $filters = []): FromQuery
    {
        return match ($type) {
            'products' => new ProductsExport($organizationId, $filters),
            'orders' => new OrdersExport($organizationId, $filters),
            'users' => new UsersExport($organizationId, $filters),
            default => throw new \InvalidArgumentException("Unknown export type: {$type}"),
        };
    }
}
