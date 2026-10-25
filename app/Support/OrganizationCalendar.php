<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Auth\Organization;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class OrganizationCalendar
{
    public static function today(?Organization $organization): CarbonImmutable
    {
        $name = $organization?->timezone ?: 'UTC';

        try {
            $timezone = new DateTimeZone($name);
        } catch (Exception) {
            $timezone = new DateTimeZone('UTC');
        }

        return CarbonImmutable::now($timezone)->startOfDay();
    }
}
