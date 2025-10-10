<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceCategoryStoreRequest;
use App\Http\Requests\Admin\ServiceCategoryUpdateRequest;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ServiceCategoryController extends Controller
{
    // GET /api/admin/categories
    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min(100, $perPage)); // clamp 1–100

        $query = ServiceCategory::query();

        // search by name/slug
        if ($q = trim((string) $request->get('q', ''))) {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%");
            });
        }

        // filter by is_active (0/1/true/false)
        if ($request->has('is_active')) {
            $isActive = filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_null($isActive)) {
                $query->where('is_active', $isActive);
            }
        }

        // sort: name | -name | created_at | -created_at (default -created_at)
        $sort = $request->get('sort', '-created_at');
        $map  = [
            'name'        => ['name','asc'],
            '-name'       => ['name','desc'],
            'created_at'  => ['created_at','asc'],
            '-created_at' => ['created_at','desc'],
        ];
        [$col,$dir] = $map[$sort] ?? ['created_at','desc'];
        $query->orderBy($col, $dir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

// POST /api/admin/categories
public function store(ServiceCategoryStoreRequest $request)
{
    $data = $request->validated();

    // image upload
    if ($request->hasFile('image')) {
        $data['image_path'] = $request->file('image')->store('images/categories', 'public');
    }

    // auto-slug if missing
    if (empty($data['slug'])) {
        $base = Str::slug($data['name']);
        $slug = $base;
        $i = 1;
        while (ServiceCategory::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;
    }

    $category = ServiceCategory::create($data);

    return response()->json([
        'message'  => 'Category created',
        'category' => $category->refresh(),
    ], 201);
}

// PUT/PATCH /api/admin/categories/{category}
public function update(ServiceCategoryUpdateRequest $request, ServiceCategory $category)
{
    $data = $request->validated();

    // slug regen if explicitly set to empty
    if (array_key_exists('slug', $data) && $data['slug'] === '') {
        $base = Str::slug($data['name'] ?? $category->name);
        $slug = $base;
        $i = 1;
        while (ServiceCategory::where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;
    }

    // image upload (replace old if present)
    if ($request->hasFile('image')) {
        if ($category->image_path) {
            Storage::disk('public')->delete($category->image_path);
        }
        $data['image_path'] = $request->file('image')->store('images/categories', 'public');
    }

    $category->update($data);

    return response()->json([
        'message'  => 'Category updated',
        'category' => $category->refresh(),
    ]);
}


    // DELETE /api/admin/categories/{category}
    // Business rule: prefer deactivate over hard delete.
    public function destroy(ServiceCategory $category)
    {
        // If you later enforce “cannot deactivate if it has services”, add that check here.
        $category->is_active = false;
        $category->save();

        // Optional: soft-delete for hiding from admin lists if desired:
        // $category->delete();

        return response()->json([
            'message'  => 'Category deactivated',
            'category' => $category,
        ]);
    }
}
