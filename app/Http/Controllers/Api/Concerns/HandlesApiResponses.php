<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\InvalidStateException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared REST conventions: capped pagination, tenant ownership checks that
 * answer 404 (never leaking another organization's ids), and the 422 error
 * envelope for domain state violations.
 */
trait HandlesApiResponses
{
    /**
     * The requested page size, defaulting to 15 and capped at 100.
     */
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->input('per_page', 15), 100));
    }

    /**
     * Abort with the standard 404 envelope when the model belongs to another
     * organization. Used for tenant models without the global organization
     * scope, where route-model binding alone would resolve a foreign row.
     */
    protected function ensureOwned(Request $request, Model $model, string $label): void
    {
        if ((int) $model->getAttribute('organization_id') !== (int) $request->user()->organization_id) {
            throw new HttpResponseException(response()->json([
                'message' => "{$label} not found",
                'error' => 'not_found',
            ], 404));
        }
    }

    /**
     * Map a domain-service refusal to the 422 error envelope.
     */
    protected function stateError(\RuntimeException $e, string $fallbackCode = 'invalid_state'): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error' => $e instanceof InvalidStateException ? $e->errorCode : $fallbackCode,
        ], 422);
    }
}
