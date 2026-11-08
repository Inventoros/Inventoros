<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Validator\Rules\QueryComplexity;

/**
 * A second budget alongside the existing 200-field complexity limit. Count
 * possible returned nodes, multiplying child cost by each list's bounded
 * cardinality, so product/supplier cycles cannot amplify a small document.
 */
final class ListQueryComplexity extends QueryComplexity
{
    protected function nodeComplexity(SelectionNode $node): int
    {
        $complexity = parent::nodeComplexity($node);
        if ($complexity === 0 || ! $node instanceof FieldNode) {
            return $complexity;
        }

        $field = $this->fieldDefinition($node);
        $type = $field?->getType();
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }
        if (! $type instanceof ListOfType) {
            return $complexity;
        }

        $count = $field->getArg('limit') !== null
            ? max(1, min($this->buildFieldArguments($node)['limit'] ?? 50, ListResourceLimits::MAX_RESULTS))
            : ListResourceLimits::MAX_RESULTS;

        // Saturation keeps hostile nested documents from overflowing PHP's
        // integer range. Costs above the budget are equivalent refusals.
        return min(ListResourceLimits::MAX_RESPONSE_NODES + 1, 1 + $count * max(1, $complexity - 1));
    }

    public static function maxQueryComplexityErrorMessage(int $max, int $count): string
    {
        return "GraphQL response node budget of {$max} exceeded.";
    }
}
