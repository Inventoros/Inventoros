<?php

declare(strict_types=1);

namespace App\Services\Update;

use RuntimeException;

/**
 * Thrown when a backup could not capture the database by any available
 * method. The updater treats this as fatal: replacing the application and
 * running migrations without a way back to the old data is not allowed unless
 * the operator explicitly opts out with INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP.
 */
final class DatabaseBackupFailedException extends RuntimeException {}
