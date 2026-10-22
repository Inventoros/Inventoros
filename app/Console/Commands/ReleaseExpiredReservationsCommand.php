<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StockReservationService;
use Illuminate\Console\Command;

/**
 * Release stock reservations whose expiry has passed. An expired hold stops
 * counting against available stock the moment it expires; this records the
 * release and fires stock_reservation_released so its owner hears about it.
 * Only active, expired holds are touched. Scheduled every five minutes.
 */
class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'reservations:release-expired';

    protected $description = 'Release stock reservations whose expiry has passed';

    public function handle(StockReservationService $reservations): int
    {
        $released = $reservations->releaseExpired();

        $this->info("Released {$released} expired reservation(s).");

        return Command::SUCCESS;
    }
}
