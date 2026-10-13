<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value is the id of a user who can work in the organization: one of its
 * home users or a member added from another organization. For assignee and
 * user pickers; withdrawn members and users of other organizations fail.
 */
final class OrganizationMember implements ValidationRule
{
    public function __construct(private readonly ?int $organizationId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isId = is_int($value) || (is_string($value) && ctype_digit($value));

        if ($this->organizationId === null || ! $isId
            || ! User::query()->memberOf($this->organizationId)->whereKey((int) $value)->exists()) {
            $fail('The selected :attribute is not a member of this organization.');
        }
    }
}
