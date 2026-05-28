<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by OrderService when a line item requests more stock than is
 * available. Each surface translates it to its own error contract: the web
 * controller flashes the message, the REST API maps it to a 422 `items`
 * validation error, GraphQL/MCP surface it as a tool/mutation error.
 *
 * Extends RuntimeException (so existing `catch (\Exception)` blocks on the
 * Inertia path keep working) and carries the original message verbatim.
 */
final class InsufficientStockException extends RuntimeException {}
