<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceStoreRequest;
use App\Http\Requests\Admin\ServiceUpdateRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Tag;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    // GET /api/admin/services
    public function index(Request $request)
{
    $perPage = min(max((int) $request->input('per_page', 12), 1), 50);
    $q       = $request->input('q');
    $catIn   = $request->input('category'); // id or slug
    $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;
    $sort    = $request->input('sort', 'newest'); // newest|oldest|price_asc|price_desc|duration_asc|duration_desc

    // Tags: accept comma-separated string or array; tags_match: any|all (default any)
    $tagsParam = $request->input('tags');
    $tagsMatch = $request->input('tags_match', 'any'); // any|all
    $tagSlugs  = [];
    if (!empty($tagsParam)) {
        $tagSlugs = is_array($tagsParam)
            ? $tagsParam
            : array_map('trim', explode(',', $tagsParam));
        $tagSlugs = array_values(array_filter(array_map(fn($t) => \Illuminate\Support\Str::slug($t), $tagSlugs)));
    }

    $query = Service::query()->with(['category', 'tags:id,name,slug']);

    if (!empty($q)) {
        $query->where(function ($w) use ($q) {
            $w->where('name', 'like', "%{$q}%")
              ->orWhere('description', 'like', "%{$q}%")
              ->orWhere('slug', 'like', "%{$q}%");
        });
    }

    if (!empty($catIn)) {
        $catId = is_numeric($catIn)
            ? (int) $catIn
            : ServiceCategory::where('slug', $catIn)->value('id');

        if ($catId) {
            $query->where('service_category_id', $catId);
        }
    }

    if (!is_null($isActive)) {
        $query->where('is_active', $isActive);
    }

    if (!empty($tagSlugs)) {
        if ($tagsMatch === 'all') {
            // require ALL provided tags
            foreach ($tagSlugs as $slug) {
                $query->whereHas('tags', function ($q) use ($slug) {
                    $q->where('slug', $slug);
                });
            }
        } else {
            // any (default)
            $query->whereHas('tags', function ($q) use ($tagSlugs) {
                $q->whereIn('slug', $tagSlugs);
            });
        }
    }

    $query->when($sort === 'oldest', fn($q) => $q->oldest())
          ->when($sort === 'price_asc', fn($q) => $q->orderBy('price', 'asc'))
          ->when($sort === 'price_desc', fn($q) => $q->orderBy('price', 'desc'))
          ->when($sort === 'duration_asc', fn($q) => $q->orderBy('duration_minutes', 'asc'))
          ->when($sort === 'duration_desc', fn($q) => $q->orderBy('duration_minutes', 'desc'))
          ->when(!in_array($sort, ['oldest','price_asc','price_desc','duration_asc','duration_desc'], true),
                 fn($q) => $q->latest());

    return response()->json($query->paginate($perPage));
}


    // GET /api/admin/services/{service}
    public function show(Service $service)
    {
        return response()->json($service->load('category'));
    }

    // POST /api/admin/services
// POST /api/admin/services
public function store(ServiceStoreRequest $request)
{
    $data = $request->validated();

    // Pull & remove tags from payload (array of strings)
    $tagsInput = $data['tags'] ?? [];
    unset($data['tags']);

    // image upload
    if ($request->hasFile('image')) {
        $data['image_path'] = $request->file('image')->store('images/services', 'public');
    }

    // Auto-slug if missing
    if (empty($data['slug'])) {
        $base = Str::slug($data['name']);
        $slug = $base;
        $i = 1;
        while (Service::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;
    }

    $service = Service::create($data);

    // Create/find tags & sync
    if (!empty($tagsInput)) {
        $service->tags()->sync($this->resolveTags($tagsInput));
    }

    return response()->json([
        'message' => 'Service created',
        'service' => $service->load('category', 'tags'),
    ], 201);
}

// PUT/PATCH /api/admin/services/{service}
public function update(ServiceUpdateRequest $request, Service $service)
{
    $data = $request->validated();

    // Extract tags if present in request (null = don't touch; [] = clear)
    $tagsProvided = array_key_exists('tags', $data);
    $tagsInput    = $tagsProvided ? ($data['tags'] ?? []) : null;
    if ($tagsProvided) unset($data['tags']);

    // slug regen if explicitly set to empty
    if (array_key_exists('slug', $data) && $data['slug'] === '') {
        $base = Str::slug($data['name'] ?? $service->name);
        $slug = $base;
        $i = 1;
        while (Service::where('slug', $slug)->where('id', '!=', $service->id)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;
    }

    // image upload (replace old if present)
    if ($request->hasFile('image')) {
        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }
        $data['image_path'] = $request->file('image')->store('images/services', 'public');
    }

    $service->fill($data)->save();

    if (!is_null($tagsInput)) {
        // tags provided → sync (empty array clears)
        $service->tags()->sync($this->resolveTags($tagsInput));
    }

    return response()->json([
        'message' => 'Service updated',
        'service' => $service->load('category', 'tags'),
    ]);
}


/**
 * Turn an array of tag names/strings into tag IDs (upsert by slug).
 *
 * @param array $tags
 * @return array<int> tag IDs
 */
private function resolveTags(array $tags): array
{
    $ids = [];
    foreach ($tags as $raw) {
        $name = trim((string)$raw);
        if ($name === '') continue;
        $slug = Str::slug($name);
        $tag  = Tag::firstOrCreate(['slug' => $slug], ['name' => ucfirst($name)]);
        $ids[] = $tag->id;
    }
    return $ids;
}


    // DELETE /api/admin/services/{service}
    public function destroy(Service $service)
    {
        $service->delete();
        return response()->noContent(); // 204
    }
}
