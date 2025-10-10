<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ServiceCategory;

class PublicCategoryController extends Controller
{
    // GET /api/v1/categories
    public function index(Request $request)
{
    $q    = trim((string) $request->query('q', ''));
    $sort = $request->query('sort', 'name_asc'); // name_asc | name_desc | services_desc

    $query = ServiceCategory::query()
        
        ->where('is_active', true)
        ->withCount('services');

    // search by name
    if ($q !== '') {
        $query->where('name', 'like', "%{$q}%");
    }

    // sorting
    switch ($sort) {
        case 'name_desc':
            $query->orderBy('name', 'desc');
            break;
        case 'services_desc':
            $query->orderBy('services_count', 'desc')->orderBy('name');
            break;
        default: // name_asc
            $query->orderBy('name');
    }

    $categories = $query->get();

    // shape response
    $data = $categories->map(function ($c) {
        return [
            'name'           => $c->name,
            'slug'           => $c->slug,
            'description'    => $c->description,
            'image_url'      => $c->image_url,   // accessor from model
            'services_count' => (int) $c->services_count,
        ];
    });

    return response()->json([
        'data' => $data,
    ]);
}


    // GET /api/v1/categories/{slug}/services
   public function servicesByCategory(Request $request, string $slug)
{
    // Find active/visible category by slug
    $category = ServiceCategory::query()
        // ->where('is_active', true) // uncomment if you have this
        ->where('slug', $slug)
        ->first();

    if (!$category) {
        return response()->json(['message' => 'Category not found'], 404);
    }

    // Params
    $q            = trim((string) $request->query('q', ''));
    $tagParam     = trim((string) $request->query('tag', '')); // single or CSV
    $minPrice     = $request->query('min_price');
    $maxPrice     = $request->query('max_price');
    $minDuration  = $request->query('min_duration');
    $maxDuration  = $request->query('max_duration');
    $sort         = $request->query('sort', 'newest'); // newest|price_asc|price_desc|duration_asc|duration_desc|popular
    $perPage      = (int) $request->query('per_page', 12);
    $perPage      = max(1, min(50, $perPage));

    // Build query
    $query = \App\Models\Service::query()
        ->where('category_id', $category->id)
        // ->where('is_active', true) // uncomment if you have this
        ->with(['category', 'tags']);

    // text search
    if ($q !== '') {
        $query->where(function ($qq) use ($q) {
            $qq->where('name', 'like', "%{$q}%")
               ->orWhere('short_description', 'like', "%{$q}%")
               ->orWhere('description', 'like', "%{$q}%");
        });
    }

    // tags filter: comma-separated or single
    if ($tagParam !== '') {
        $tags = collect(explode(',', $tagParam))
            ->map(fn($t) => trim($t))
            ->filter()
            ->values()
            ->all();

        if (!empty($tags)) {
            $query->whereHas('tags', function ($tq) use ($tags) {
                $tq->whereIn('slug', $tags)->orWhereIn('name', $tags);
            });
        }
    }

    // numeric ranges
    if (is_numeric($minPrice)) {
        $query->where('price', '>=', (float) $minPrice);
    }
    if (is_numeric($maxPrice)) {
        $query->where('price', '<=', (float) $maxPrice);
    }
    if (is_numeric($minDuration)) {
        $query->where('duration_minutes', '>=', (int) $minDuration);
    }
    if (is_numeric($maxDuration)) {
        $query->where('duration_minutes', '<=', (int) $maxDuration);
    }

    // sorting
    switch ($sort) {
        case 'price_asc':
            $query->orderBy('price', 'asc');
            break;
        case 'price_desc':
            $query->orderBy('price', 'desc');
            break;
        case 'duration_asc':
            $query->orderBy('duration_minutes', 'asc');
            break;
        case 'duration_desc':
            $query->orderBy('duration_minutes', 'desc');
            break;
        case 'popular':
            $query->orderByDesc('views_count')->orderBy('name');
            break;
        default: // newest
            $query->orderByDesc('created_at');
    }

    // paginate
    $paginator = $query->paginate($perPage)->appends($request->query());

    // shape items
    $items = collect($paginator->items())->map(function ($s) {
        return [
            'name'             => $s->name,
            'slug'             => $s->slug,
            'price'            => (float) $s->price,
            'duration_minutes' => (int) $s->duration_minutes,
            'short_description'=> $s->short_description,
            'image_url'        => $s->image_url, // accessor from model
            'tags'             => $s->tags->pluck('name')->values()->all(),
            'category'         => [
                'name' => $s->category?->name,
                'slug' => $s->category?->slug,
            ],
        ];
    });

    return response()->json([
        'category' => [
            'name'        => $category->name,
            'slug'        => $category->slug,
            'description' => $category->description,
            'image_url'   => $category->image_url,
        ],
        'data' => $items,
        'pagination' => [
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ],
    ]);
}

}
