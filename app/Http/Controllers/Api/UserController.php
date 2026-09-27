<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\User\StoreUserRequest;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserManagementService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Organization users. Create and update run through UserManagementService,
 * so a non-admin can only assign the `member` base role and roles within
 * their own permissions, exactly as on the web.
 *
 * @tags Users
 */
class UserController extends Controller
{
    use HandlesApiResponses;

    public function __construct(private readonly UserManagementService $users) {}

    /**
     * List users in the organization.
     */
    #[QueryParameter('search', description: 'Search by name or email', type: 'string')]
    #[QueryParameter('role', description: 'Filter by base role', type: 'string', enum: ['admin', 'manager', 'member'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::with('roles')
            ->forOrganization($request->user()->organization_id)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->input('role'), fn ($query, $role) => $query->where('role', $role))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return UserResource::collection($users);
    }

    /**
     * Create a user in the organization.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'User created successfully',
            'data' => new UserResource($user->load('roles')),
        ], 201);
    }

    /**
     * Show a user.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->ensureOwned($request, $user, 'User');

        return response()->json(['data' => new UserResource($user->load('roles'))]);
    }

    /**
     * Update a user.
     *
     * Demoting the last administrator is a 422 on `role`.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->ensureOwned($request, $user, 'User');

        $updated = $this->users->update($request->user(), $user, $request->validated());

        return response()->json([
            'message' => 'User updated successfully',
            'data' => new UserResource($updated->fresh('roles')),
        ]);
    }
}
