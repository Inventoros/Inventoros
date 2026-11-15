<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * PublicHostGuard found no A or AAAA record for the host, so it could not be
 * checked (as opposed to a host that resolves to a non-public address).
 */
final class UnresolvedHostException extends RuntimeException {}
