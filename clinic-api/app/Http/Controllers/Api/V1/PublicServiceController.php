<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;

class PublicServiceController extends Controller
{
    // GET /api/v1/services
    public function index(\Illuminate\Http\Request $request)
{
    $q            = trim((string) $request->query('q', ''));
    $categoryIn   = $request->query('category'); // slug or id
    $tagParam     = trim((string) $request->query('tag', '')); // single or CSV
    $minPrice     = $request->query('min_price');
    $maxPrice     = $request->query('max_price');
    $minDuration  = $request->query('min_duration');
    $maxDuration  = $request->query('max_duration');
    $sort         = $request->query('sort', 'newest'); // newest|oldest|price_asc|price_desc|duration_asc|duration_desc|popular
    $perPage      = (int) $request->query('per_page', 12);
    $perPage      = max(1, min(50, $perPage));

    $query = \App\Models\Service::query()
        // ->where('is_active', true) // uncomment if you have this column
        ->with(['category', 'tags']);

    // text search
    if ($q !== '') {
        $query->where(function ($qq) use ($q) {
            $qq->where('name', 'like', "%{$q}%")
               ->orWhere('short_description', 'like', "%{$q}%")
               ->orWhere('description', 'like', "%{$q}%");
        });
    }

    // category filter (slug or id)
    if (!is_null($categoryIn) && $categoryIn !== '') {
        if (is_numeric($categoryIn)) {
            $query->where('category_id', (int) $categoryIn);
        } else {
            $query->whereHas('category', function ($cq) use ($categoryIn) {
                $cq->where('slug', $categoryIn);
            });
        }
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
        case 'oldest':
            $query->orderBy('created_at', 'asc');
            break;
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

    $paginator = $query->paginate($perPage)->appends($request->query());

    $items = collect($paginator->items())->map(function ($s) {
        return [
            'name'             => $s->name,
            'slug'             => $s->slug,
            'price'            => (float) $s->price,
            'duration_minutes' => (int) $s->duration_minutes,
            'short_description'=> $s->short_description,
            'image_url'        => $s->image_url, // accessor
            'tags'             => $s->tags->pluck('name')->values()->all(),
            'category'         => [
                'name' => $s->category?->name,
                'slug' => $s->category?->slug,
            ],
        ];
    });

    return response()->json([
        'filters' => [
            'q' => $q ?: null,
            'category' => $categoryIn ?: null,
            'tag' => $tagParam ?: null,
            'min_price' => is_numeric($minPrice) ? (float) $minPrice : null,
            'max_price' => is_numeric($maxPrice) ? (float) $maxPrice : null,
            'min_duration' => is_numeric($minDuration) ? (int) $minDuration : null,
            'max_duration' => is_numeric($maxDuration) ? (int) $maxDuration : null,
            'sort' => $sort,
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


    // GET /api/v1/services/{slug}
    public function show(string $slug)
{
    $service = Service::query()
        ->where('is_active', true) 
        ->where('slug', $slug)
        ->with(['category', 'tags'])
        ->first();

    if (!$service) {
        return response()->json(['message' => 'Service not found'], 404);
    }

    return response()->json([
        'name'              => $service->name,
        'slug'              => $service->slug,
        'price'             => (float) $service->price,
        'duration_minutes'  => (int) $service->duration_minutes,
        'short_description' => $service->short_description,
        'description'       => $service->description,
        'image_url'         => $service->image_url, // accessor
        'tags'              => $service->tags->pluck('name')->values()->all(),
        'category'          => [
            'name' => $service->category?->name,
            'slug' => $service->category?->slug,
        ],
        'views_count'       => (int) $service->views_count,
    ]);
}


    // GET /api/v1/services/suggest?q=
    public function suggest(Request $request)
{
    $q = trim((string) $request->query('q', ''));
    $limit = (int) $request->query('limit', 6);
    $limit = max(1, min(10, $limit));

    if ($q === '') {
        return response()->json(['data' => []]); // empty query → empty result
    }

    $items = Service::query()
        ->where('is_active', true)
        ->where('name', 'like', "%{$q}%")
        ->orderBy('name')
        ->limit($limit)
        ->get(['name', 'slug']);

    return response()->json([
        'data' => $items->map(fn($s) => ['name' => $s->name, 'slug' => $s->slug])->all(),
    ]);
}

}
