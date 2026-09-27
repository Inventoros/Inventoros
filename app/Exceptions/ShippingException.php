<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A shipping operation was refused or a carrier call failed. The message is
 * always safe to show to the user (carrier errors are translated into plain
 * sentences before they are thrown), so every surface displays it as-is: the
 * web flashes it, the REST API returns it in a 422, MCP returns a tool error.
 */
class ShippingException extends RuntimeException {}
