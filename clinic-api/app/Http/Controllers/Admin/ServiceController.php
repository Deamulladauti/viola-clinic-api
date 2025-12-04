<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceStoreRequest;
use App\Http\Requests\Admin\ServiceUpdateRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class ServiceController extends Controller
{
    // GET /api/admin/services
    public function index(Request $request)
    {
        $perPage  = min(max((int) $request->input('per_page', 12), 1), 50);
        $q        = trim((string) $request->input('q', ''));
        $catIn    = $request->input('category'); // id or slug
        $isActive = $request->has('is_active')   ? $request->boolean('is_active')   : null;
        $bookable = $request->has('is_bookable') ? $request->boolean('is_bookable') : null;
        $sort     = $request->input('sort', 'newest'); // newest|oldest|price_asc|price_desc|duration_asc|duration_desc|popular

        // Tags filter: names/strings (csv or array)
        $tagsParam = $request->input('tags');
        $tagsMatch = $request->input('tags_match', 'any'); // any|all
        $tagSlugs  = [];
        if (!empty($tagsParam)) {
            $tagSlugs = is_array($tagsParam) ? $tagsParam : array_map('trim', explode(',', $tagsParam));
            $tagSlugs = array_values(array_filter(array_map(fn($t) => Str::slug($t), $tagSlugs)));
        }

        $query = Service::query()->with(['category:id,name,slug', 'tags:id,name,slug','staff:id,name',]);

        // Optional: include counts for admin overviews
        if ($request->boolean('include_counts')) {
            $query->withCount(['staff', 'appointments']);
        }

        // text + i18n search
        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                  ->orWhere('short_description', 'like', $like)
                  ->orWhere('description', 'like', $like)
                  ->orWhere('slug', 'like', $like)
                  ->orWhere('name_i18n', 'like', $like)
                  ->orWhere('short_description_i18n', 'like', $like)
                  ->orWhere('description_i18n', 'like', $like);
            });
        }

        // category filter (id or slug)
        if (!empty($catIn)) {
            $catId = is_numeric($catIn)
                ? (int) $catIn
                : ServiceCategory::where('slug', $catIn)->value('id');

            if ($catId) {
                $query->where('service_category_id', $catId);
            }
        }

        if (!is_null($isActive))  $query->where('is_active', $isActive);
        if (!is_null($bookable))  $query->where('is_bookable', $bookable);

        // tags filter
        if (!empty($tagSlugs)) {
            if ($tagsMatch === 'all') {
                foreach ($tagSlugs as $slug) {
                    $query->whereHas('tags', fn($q) => $q->where('slug', $slug));
                }
            } else {
                $query->whereHas('tags', fn($q) => $q->whereIn('slug', $tagSlugs));
            }
        }

        // sorting
        $query->when($sort === 'oldest', fn($q) => $q->oldest())
              ->when($sort === 'price_asc', fn($q) => $q->orderBy('price', 'asc'))
              ->when($sort === 'price_desc', fn($q) => $q->orderBy('price', 'desc'))
              ->when($sort === 'duration_asc', fn($q) => $q->orderBy('duration_minutes', 'asc'))
              ->when($sort === 'duration_desc', fn($q) => $q->orderBy('duration_minutes', 'desc'))
              ->when($sort === 'popular', function ($q) {
                    // Prefer popularity_weight if available, fallback to views_count
                    if (Schema::hasColumn('services', 'popularity_weight')) {
                        $q->orderByDesc('popularity_weight')->orderBy('name');
                    } else {
                        $q->orderByDesc('views_count')->orderBy('name');
                    }
              })
              ->when(!in_array($sort, ['oldest','price_asc','price_desc','duration_asc','duration_desc','popular'], true),
                     fn($q) => $q->latest());

        return response()->json($query->paginate($perPage));
    }

    // GET /api/admin/services/{service}
    public function show(Service $service)
    {
        return response()->json(
            $service->load(['category:id,name,slug', 'tags:id,name,slug', 'staff:id,name,email'])
        );
    }

    // POST /api/admin/services
    public function store(ServiceStoreRequest $request)
    {
        $data = $request->validated();

        // Extract relations
        $tagsByName = $data['tags'] ?? null;      unset($data['tags']);
        $tagIds     = $data['tag_ids'] ?? null;   unset($data['tag_ids']);
        $staffIds   = $data['staff_ids'] ?? null; unset($data['staff_ids']);

        // Normalize
        if (isset($data['name'])) $data['name'] = trim($data['name']);
        if (isset($data['slug'])) $data['slug'] = trim($data['slug']);

        // image upload
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('images/services', 'public');
        }

        // ensure booleans
        $data['is_active']   = (bool) ($data['is_active']   ?? true);
        $data['is_bookable'] = (bool) ($data['is_bookable'] ?? true);

        // slug
        $base = Str::slug($data['slug'] ?? $data['name']);
        if ($base === '') $base = Str::slug($data['name']);
        $slug = $base;
        $i = 1;
        while (Service::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;

        $service = Service::create($data);

        // Sync relations
        if (is_array($tagIds))      $service->tags()->sync($tagIds);
        if (is_array($tagsByName))  $service->tags()->sync($this->resolveTags($tagsByName));
        if (is_array($staffIds))    $service->staff()->sync($staffIds);

        return response()->json([
            'message' => 'Service created',
            'service' => $service->load('category:id,name,slug', 'tags:id,name,slug', 'staff:id,name,email'),
        ], 201);
    }


// PUT/PATCH /api/v1/admin/services/{id}
// PUT/PATCH /api/v1/admin/services/{id}
public function update(ServiceUpdateRequest $request, int $id)
{
    \Log::info('Service update debug', [
        'all'      => $request->all(),
        'has_file' => $request->hasFile('image'),
        'file'     => $request->file('image') ? $request->file('image')->getClientOriginalName() : null,
    ]);
    // Explicitly bind by ID
    $service = Service::findOrFail($id);

    // All validated fields (partial updates allowed via 'sometimes')
    $data = $request->validated();

    /*
     * ----- Extract relation fields so they don't go into fill() -----
     */
    $tagsProvidedByName = array_key_exists('tags', $data);
    $tagsByName         = $tagsProvidedByName ? ($data['tags'] ?? []) : null;
    if ($tagsProvidedByName) {
        unset($data['tags']);
    }

    $tagIdsProvided = array_key_exists('tag_ids', $data);
    $tagIds         = $tagIdsProvided ? ($data['tag_ids'] ?? []) : null;
    if ($tagIdsProvided) {
        unset($data['tag_ids']);
    }

    $staffIdsProvided = array_key_exists('staff_ids', $data);
    $staffIds         = $staffIdsProvided ? ($data['staff_ids'] ?? []) : null;
    if ($staffIdsProvided) {
        unset($data['staff_ids']);
    }

    /*
     * ----- Normalize simple scalar fields -----
     */
    if (array_key_exists('name', $data)) {
        $data['name'] = trim((string) $data['name']);
    }

    if (array_key_exists('slug', $data)) {
        $data['slug'] = trim((string) $data['slug']);
    }

    /*
     * ----- Slug regeneration / uniqueness -----
     *
     * If 'slug' is present:
     *  - empty string → regenerate from name (or current name)
     *  - non-empty → normalize and ensure unique (except current service)
     */
    if (array_key_exists('slug', $data)) {
        $base = $data['slug'] === ''
            ? Str::slug($data['name'] ?? $service->name)
            : Str::slug($data['slug']);

        if ($base === '') {
            $base = Str::slug($data['name'] ?? $service->name);
        }

        $slug = $base;
        $i    = 1;

        while (
            Service::where('slug', $slug)
                ->where('id', '!=', $service->id)
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        $data['slug'] = $slug;
    }

    /*
     * ----- Image upload -----
     * If a new image is provided, delete the old one (if any)
     * and store the new path into image_path.
     */
    if ($request->hasFile('image')) {
        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }

        $data['image_path'] = $request->file('image')
            ->store('images/services', 'public');
    }

    /*
     * ----- Update base attributes -----
     * This will also handle i18n arrays because they are in $fillable
     * (name_i18n, short_description_i18n, description_i18n, prep_instructions, etc.).
     */
    $service->fill($data)->save();

    /*
     * ----- Sync relations -----
     */
    if ($tagIdsProvided) {
        $service->tags()->sync($tagIds);
    }

    if ($tagsProvidedByName) {
        // expects resolveTags(array $names): array of tag IDs
        $service->tags()->sync($this->resolveTags($tagsByName));
    }

    if ($staffIdsProvided) {
        $service->staff()->sync($staffIds);
    }

    /*
     * ----- Response -----
     */
    return response()->json([
        'message' => 'Service updated',
        'service' => $service->load(
            'category:id,name,slug',
            'tags:id,name,slug',
            'staff:id,name,email'
        ),
    ]);
}



    // DELETE /api/admin/services/{service}
    public function destroy(Service $service)
    {
        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }

        $service->delete();
        return response()->noContent();
    }

    /**
     * Resolve an array of tag names/strings into tag IDs (upsert by slug)
     */
    private function resolveTags(array $tags): array
    {
        $ids = [];
        foreach ($tags as $raw) {
            $name = trim((string) $raw);
            if ($name === '') continue;
            $slug = Str::slug($name);
            $tag  = Tag::firstOrCreate(['slug' => $slug], ['name' => ucfirst($name)]);
            $ids[] = $tag->id;
        }
        return $ids;
    }
}
