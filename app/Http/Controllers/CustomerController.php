<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing customers.
 *
 * Handles CRUD operations for customers including listing,
 * creating, updating, and deleting customer records.
 */
class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = Customer::withCount('orders')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->search($search);
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->latest();

        $customers = $query->paginate(config('limits.pagination.default'))->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'is_active']),
        ]);
    }

    /**
     * Show the form for creating a new customer.
     */
    public function create(): Response
    {
        return Inertia::render('Customers/Create');
    }

    /**
     * Store a newly created customer.
     *
     * @param  Request  $request  The incoming HTTP request containing customer data
     * @return RedirectResponse|JsonResponse
     */
    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $customer = Customer::create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Customer created successfully.',
                'customer' => $customer,
            ], 201);
        }

        return redirect()->route('customers.index')
            ->with('success', 'Customer created successfully.');
    }

    /**
     * Display the specified customer.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Customer  $customer  The customer to display
     */
    public function show(Request $request, Customer $customer): Response
    {
        if ($customer->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $customer->load('orders');

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
        ]);
    }

    /**
     * Show the form for editing the specified customer.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Customer  $customer  The customer to edit
     */
    public function edit(Request $request, Customer $customer): Response
    {
        if ($customer->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
        ]);
    }

    /**
     * Update the specified customer.
     *
     * @param  Request  $request  The incoming HTTP request containing updated customer data
     * @param  Customer  $customer  The customer to update
     * @return RedirectResponse|JsonResponse
     */
    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        if ($customer->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $validated = $request->validated();

        $customer->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Customer updated successfully.',
                'customer' => $customer,
            ]);
        }

        return redirect()->route('customers.index')
            ->with('success', 'Customer updated successfully.');
    }

    /**
     * Remove the specified customer.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Customer  $customer  The customer to delete
     * @return RedirectResponse|JsonResponse
     */
    public function destroy(Request $request, Customer $customer)
    {
        if ($customer->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        // Check if customer has associated orders
        if ($customer->orders()->count() > 0) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Cannot delete customer with associated orders.',
                ], 422);
            }

            return redirect()->route('customers.index')
                ->with('error', 'Cannot delete customer with associated orders.');
        }

        $customer->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Customer deleted successfully.',
            ]);
        }

        return redirect()->route('customers.index')
            ->with('success', 'Customer deleted successfully.');
    }
}
