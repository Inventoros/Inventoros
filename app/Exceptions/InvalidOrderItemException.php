<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by OrderService when a line item is structurally invalid — e.g. a
 * variant that does not belong to the line's product, or a line for a
 * variant-tracked product that omits the required product_variant_id.
 *
 * Like InsufficientStockException it extends RuntimeException so the web
 * controller's catch(\Exception) keeps flashing it; REST/GraphQL translate it
 * to their validation-error contracts.
 */
final class InvalidOrderItemException extends RuntimeException {}
