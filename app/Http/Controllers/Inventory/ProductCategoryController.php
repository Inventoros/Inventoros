<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\ProductCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing product categories.
 *
 * Handles CRUD operations for product categories
 * with plugin integration support.
 */
class ProductCategoryController extends Controller
{
    /**
     * Display a listing of categories.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->withCount('products')
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn($category) => $category);

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
            'filters' => [
                'search' => $request->input('search', ''),
            ],
            'pluginComponents' => [
                'header' => get_page_components('categories.index', 'header'),
                'footer' => get_page_components('categories.index', 'footer'),
            ],
        ]);
    }

    /**
     * Store a newly created category.
     *
     * @param Request $request The incoming HTTP request containing category data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = ProductCategory::create($validated);

        // If this is an AJAX request (from inline form), return JSON
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'category' => $category,
                'message' => 'Category created successfully.',
            ]);
        }

        return redirect()->route('categories.index')
            ->with('success', 'Category created successfully.');
    }

    /**
     * Update the specified category.
     *
     * @param Request $request The incoming HTTP request containing updated category data
     * @param ProductCategory $category The category to update
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, ProductCategory $category)
    {
        // Ensure user can only update categories from their organization
        if ($category->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $category->update($validated);

        return redirect()->route('categories.index')
            ->with('success', 'Category updated successfully.');
    }

    /**
     * Remove the specified category.
     *
     * @param Request $request The incoming HTTP request
     * @param ProductCategory $category The category to delete
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, ProductCategory $category)
    {
        // Ensure user can only delete categories from their organization
        if ($category->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return redirect()->back()
                ->withErrors(['category' => 'Cannot delete category with associated products.']);
        }

        $category->delete();

        return redirect()->route('categories.index')
            ->with('success', 'Category deleted successfully.');
    }
}
