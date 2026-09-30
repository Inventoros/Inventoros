<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by OrderService when a line item requests more stock than is
 * available. Each surface translates it to its own error contract: the web
 * controller flashes the message, the REST API maps it to a 422 `items`
 * validation error, GraphQL/MCP surface it as a tool/mutation error.
 *
 * Extends BusinessRuleException (so request handlers show it to the user on the
 * Inertia path keep working) and carries the original message verbatim.
 */
final class InsufficientStockException extends BusinessRuleException {}
