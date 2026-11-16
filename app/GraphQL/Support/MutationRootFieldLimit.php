<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use GraphQL\Error\Error;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * Caps the number of root fields in a mutation operation. Each HTTP request
 * counts once against the API rate limiter, so without a cap one request
 * could carry dozens of aliased writes. Fields reached through inline
 * fragments and fragment spreads count too. Queries are not affected; their
 * cost is bounded by the complexity budgets.
 */
final class MutationRootFieldLimit extends ValidationRule
{
    public function __construct(private readonly int $maxRootFields) {}

    public function getVisitor(QueryValidationContext $context): array
    {
        return [
            NodeKind::OPERATION_DEFINITION => function (OperationDefinitionNode $operation) use ($context): void {
                if ($operation->operation !== 'mutation') {
                    return;
                }

                $count = $this->countRootFields($operation->selectionSet, $context, []);

                if ($count > $this->maxRootFields) {
                    $context->reportError(new Error(
                        "A mutation may contain at most {$this->maxRootFields} root fields; this one has {$count}.",
                        [$operation]
                    ));
                }
            },
        ];
    }

    /**
     * @param  array<string, true>  $visitedFragments
     */
    private function countRootFields(SelectionSetNode $selectionSet, QueryValidationContext $context, array $visitedFragments): int
    {
        $count = 0;

        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                $count += $selection->name->value === '__typename' ? 0 : 1;
            } elseif ($selection instanceof InlineFragmentNode) {
                $count += $this->countRootFields($selection->selectionSet, $context, $visitedFragments);
            } elseif ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                $fragment = $context->getFragment($name);

                // Unknown or cyclic fragments are reported by the standard rules.
                if ($fragment !== null && ! isset($visitedFragments[$name])) {
                    $count += $this->countRootFields($fragment->selectionSet, $context, $visitedFragments + [$name => true]);
                }
            }
        }

        return $count;
    }
}
