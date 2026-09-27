<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use RuntimeException;

/**
 * A marketplace request or install that cannot go ahead. The message is
 * written for the admin who clicked the button and is safe to flash.
 */
final class MarketplaceException extends RuntimeException {}
