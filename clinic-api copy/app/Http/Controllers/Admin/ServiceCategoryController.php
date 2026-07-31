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
        $perPage = (int) $request->integer('per_page',30);
        $perPage = max(1, min(100, $perPage));

        $query = ServiceCategory::query();

        if ($request->boolean('include_counts')) {
            $query->withCount('services');
        }

        if ($q = trim((string) $request->get('q', ''))) {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%");
            });
        }

        if ($request->has('is_active')) {
            $isActive = filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_null($isActive)) {
                $query->where('is_active', $isActive);
            }
        }

        $sort = $request->get('sort', '-created_at');
        $map  = [
            'name'        => ['name', 'asc'],
            '-name'       => ['name', 'desc'],
            'created_at'  => ['created_at', 'asc'],
            '-created_at' => ['created_at', 'desc'],
        ];

        [$col, $dir] = $map[$sort] ?? ['created_at', 'desc'];
        $query->orderBy($col, $dir);

        return response()->json($query->paginate($perPage));
    }

    // POST /api/admin/categories
    public function store(ServiceCategoryStoreRequest $request)
    {
        $data = $request->validated();

        if (isset($data['name'])) $data['name'] = trim($data['name']);
        if (isset($data['slug'])) $data['slug'] = trim($data['slug']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('images/categories', 'public');
        }

        if (empty($data['slug'])) {
            $base = Str::slug($data['name']);
            $slug = $base;
            $i = 1;
            while (ServiceCategory::where('slug', $slug)->exists()) {
                $slug = $base . '-' . $i++;
            }
            $data['slug'] = $slug;
        } else {
            $base = Str::slug($data['slug']);
            $slug = $base !== '' ? $base : Str::slug($data['name']);
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

        if (array_key_exists('name', $data)) $data['name'] = trim((string) $data['name']);
        if (array_key_exists('slug', $data)) $data['slug'] = trim((string) $data['slug']);

        if (array_key_exists('slug', $data)) {
            if ($data['slug'] === '') {
                $base = Str::slug($data['name'] ?? $category->name);
                $slug = $base;
                $i = 1;
                while (
                    ServiceCategory::where('slug', $slug)
                        ->where('id', '!=', $category->id)
                        ->exists()
                ) {
                    $slug = $base . '-' . $i++;
                }
                $data['slug'] = $slug;
            } else {
                $base = Str::slug($data['slug']);
                if ($base === '') {
                    $base = Str::slug($data['name'] ?? $category->name);
                }
                $slug = $base;
                $i = 1;
                while (
                    ServiceCategory::where('slug', $slug)
                        ->where('id', '!=', $category->id)
                        ->exists()
                ) {
                    $slug = $base . '-' . $i++;
                }
                $data['slug'] = $slug;
            }
        }

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
    public function destroy(ServiceCategory $category)
    {
        if (! $category->is_active) {
            return response()->json([
                'message'  => 'Category is already inactive',
                'category' => $category,
            ]);
        }

        $category->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message'  => 'Category deactivated',
            'category' => $category->refresh(),
        ]);
    }

    // PATCH /api/admin/categories/{category}/activate
    public function activate(ServiceCategory $category)
    {
        if ($category->is_active) {
            return response()->json([
                'message'  => 'Category is already active',
                'category' => $category,
            ]);
        }

        $category->update([
            'is_active' => true,
        ]);

        return response()->json([
            'message'  => 'Category activated',
            'category' => $category->refresh(),
        ]);
    }
}