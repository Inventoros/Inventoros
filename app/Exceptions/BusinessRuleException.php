<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A refusal the user can act on: a record in the wrong state, too little
 * stock, a failed approval rule. Its message is written for the user, so
 * web controllers flash it and the API returns it with a 422.
 *
 * Request handlers catch this class, never RuntimeException: that would also
 * catch database errors (QueryException is a RuntimeException) and show raw
 * SQL to the user. Those must reach the error handler, which logs them.
 */
abstract class BusinessRuleException extends RuntimeException {}
