<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExpenseCategoryRequest;
use App\Http\Requests\Admin\UpdateExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseCategory::query()
            ->withCount('expenses');

        if ($request->filled('active')) {
            $query->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOL));
        }

        $categories = $query
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(StoreExpenseCategoryRequest $request)
    {
        $data = $request->validated();

        $category = ExpenseCategory::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($category, 201);
    }

    public function show(ExpenseCategory $category)
    {
        $category->loadCount('expenses');

        return response()->json($category);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $category)
    {
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }

        if (array_key_exists('slug', $data)) {
            $category->slug = $data['slug'] ?: Str::slug($category->name);
        } elseif (array_key_exists('name', $data) && blank($category->slug)) {
            $category->slug = Str::slug($category->name);
        }

        if (array_key_exists('is_active', $data)) {
            $category->is_active = $data['is_active'];
        }

        $category->save();

        return response()->json($category->fresh()->loadCount('expenses'));
    }

    public function destroy(ExpenseCategory $category)
    {
        if ($category->expenses()->exists()) {
            $category->update(['is_active' => false]);

            return response()->json([
                'message' => 'Category has expenses and was deactivated instead of deleted.',
                'category' => $category->fresh()->loadCount('expenses'),
            ]);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }

    public function activate(ExpenseCategory $category)
    {
        $category->update(['is_active' => true]);

        return response()->json($category->fresh()->loadCount('expenses'));
    }

    public function deactivate(ExpenseCategory $category)
    {
        $category->update(['is_active' => false]);

        return response()->json($category->fresh()->loadCount('expenses'));
    }
}