<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the HookManager service.
 *
 * Provides static access to the WordPress-style hook system for actions and filters.
 *
 * @see \App\Support\HookManager
 */
class Hook extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'hooks';
    }
}
