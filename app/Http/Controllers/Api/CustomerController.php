<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Customers
 */
class CustomerController extends Controller
{
    use HandlesApiResponses;

    /**
     * List customers.
     */
    #[QueryParameter('search', description: 'Search by name, code, email, company or contact', type: 'string')]
    #[QueryParameter('is_active', description: 'Filter by active status', type: 'boolean')]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string', enum: ['created_at', 'updated_at', 'name', 'code', 'email'])]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Customer::withCount('orders')
            ->forOrganization($request->user()->organization_id)
            ->when($request->input('search'), fn ($query, $search) => $query->search($search))
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        $allowedSortColumns = ['created_at', 'updated_at', 'name', 'code', 'email'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        return CustomerResource::collection($query->orderBy($sortBy, $sortDir)->paginate($this->perPage($request)));
    }

    /**
     * Create a customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $customer = Customer::create($validated);

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => new CustomerResource($customer),
        ], 201);
    }

    /**
     * Show a customer.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureOwned($request, $customer, 'Customer');

        return response()->json([
            'data' => new CustomerResource($customer->loadCount('orders')),
        ]);
    }

    /**
     * Update a customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->ensureOwned($request, $customer, 'Customer');

        $customer->update($request->validated());

        return response()->json([
            'message' => 'Customer updated successfully',
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Delete a customer. Customers with orders cannot be deleted.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureOwned($request, $customer, 'Customer');

        if ($customer->orders()->exists()) {
            return response()->json([
                'message' => 'Cannot delete customer with associated orders.',
                'error' => 'has_orders',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully',
        ]);
    }

    /**
     * List a customer's orders.
     */
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function orders(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->ensureOwned($request, $customer, 'Customer');

        $orders = $customer->orders()
            ->withCount('items')
            ->latest('order_date')
            ->latest('id')
            ->paginate($this->perPage($request));

        return OrderResource::collection($orders);
    }
}
