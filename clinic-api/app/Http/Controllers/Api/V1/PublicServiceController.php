<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Service;
use Illuminate\Support\Collection;

class PublicServiceController extends Controller
{
    /**
     * GET /api/v1/services
     * Public listing with filters, sorts, and pagination.
     */
    public function index(Request $request)
    {
        // Normalize inputs
        $q            = trim((string) $request->query('q', ''));
        $categoryIn   = $request->query('category');          // slug or id
        $tagParam     = trim((string) $request->query('tag', '')); // CSV or single
        $minPrice     = $request->query('min_price');
        $maxPrice     = $request->query('max_price');
        $minDuration  = $request->query('min_duration');
        $maxDuration  = $request->query('max_duration');
        $sort         = $request->query('sort', 'newest');    // newest|oldest|price_asc|price_desc|duration_asc|duration_desc|popular|name_asc|name_desc
        $bookable     = $request->query('bookable');          // '1'|'0'|null
        $active       = $request->query('active', '1');       // default active only
        $perPage      = (int) $request->query('per_page', 12);
        $perPage      = max(1, min(50, $perPage));

        // Base query (public-facing → guard visibility by default)
        $query = Service::query()
            ->with([
                'category:id,name,slug',
                'tags:id,name,slug',
            ])
            ->select([
                'id',
                'service_category_id',
                'name',
                'slug',
                'short_description',
                'description',
                'name_i18n',
                'short_description_i18n',
                'description_i18n',
                'prep_instructions',
                'duration_minutes',
                'price',
                'is_active',
                'is_bookable',
                'image_path',
                'created_at',
            ]);

        // Active filter (default true)
        if ($active !== null) {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        // Bookable filter (optional)
        if ($bookable !== null && $bookable !== '') {
            $query->where('is_bookable', filter_var($bookable, FILTER_VALIDATE_BOOLEAN));
        }

        // Text search (base + i18n JSON)
        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($qq) use ($like) {
                $qq->where('name', 'like', $like)
                   ->orWhere('short_description', 'like', $like)
                   ->orWhere('description', 'like', $like)
                   ->orWhere('name_i18n', 'like', $like)
                   ->orWhere('short_description_i18n', 'like', $like)
                   ->orWhere('description_i18n', 'like', $like);
            });
        }

        // Category (id or slug)
        if (!is_null($categoryIn) && $categoryIn !== '') {
            if (is_numeric($categoryIn)) {
                $query->where('service_category_id', (int) $categoryIn);
            } else {
                $slug = (string) $categoryIn;
                $query->whereHas('category', function ($cq) use ($slug) {
                    $cq->where('slug', $slug);
                });
            }
        }

        // Tags (CSV allowed)
        if ($tagParam !== '') {
            $tags = collect(explode(',', $tagParam))
                ->map(fn($t) => trim($t))
                ->filter()
                ->values()
                ->all();

            if (!empty($tags)) {
                $query->whereHas('tags', function ($tq) use ($tags) {
                    $tq->where(function ($x) use ($tags) {
                        $x->whereIn('slug', $tags)
                          ->orWhereIn('name', $tags);
                    });
                });
            }
        }

        // Numeric ranges
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

        // Sorting
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
                // No views_count column → fallback to newest
                $query->orderByDesc('created_at');
                break;
            case 'name_desc':
                $query->orderBy('name', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            default: // newest
                $query->orderByDesc('created_at');
        }

        // Optional tiny cache (disable or tune via config)
        $cacheTtl = (int) config('clinic.cache_ttl.services_index', 0); // seconds; 0 disables
        $cacheKey = null;

        if ($cacheTtl > 0) {
            $cacheKey = 'svc:index:' . md5(json_encode($request->query()));
            $paginator = Cache::remember(
                $cacheKey,
                $cacheTtl,
                fn () => $query->paginate($perPage)->appends($request->query())
            );
        } else {
            $paginator = $query->paginate($perPage)->appends($request->query());
        }

        // Map to localized output via model accessors
        $items = collect($paginator->items())->map(function (Service $s) {
            return [
                'id'                => $s->id,
                'slug'              => $s->slug,
                'price'             => (float) $s->price,
                'duration_minutes'  => (int) $s->duration_minutes,
                'is_active'         => (bool) $s->is_active,
                'is_bookable'       => (bool) $s->is_bookable,
                'name'              => $s->name_localized,
                'short_description' => $s->short_description_localized,
                'image_url'         => $s->image_url,
                'tags'              => $s->tags->pluck('name')->values()->all(),
                'category'          => [
                    'name' => $s->category?->name,
                    'slug' => $s->category?->slug,
                ],
                // We are no longer tracking views_count in DB → always 0
                'views_count'       => 0,
            ];
        });

        return response()->json([
            'filters' => [
                'q'             => $q ?: null,
                'category'      => $categoryIn ?: null,
                'tag'           => $tagParam ?: null,
                'min_price'     => is_numeric($minPrice) ? (float) $minPrice : null,
                'max_price'     => is_numeric($maxPrice) ? (float) $maxPrice : null,
                'min_duration'  => is_numeric($minDuration) ? (int) $minDuration : null,
                'max_duration'  => is_numeric($maxDuration) ? (int) $maxDuration : null,
                'sort'          => $sort,
                'bookable'      => $bookable !== null ? (bool) filter_var($bookable, FILTER_VALIDATE_BOOLEAN) : null,
                'active'        => $active !== null ? (bool) filter_var($active, FILTER_VALIDATE_BOOLEAN) : null,
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

    /**
     * GET /api/v1/services/{id}
     * Keep ID variant for backward compatibility.
     */

public function show(Request $request, int $id)
{
    // Optional: override locale with ?lang=en|sq|mk
    if ($lang = $request->query('lang')) {
        app()->setLocale($lang);
    }

    $active  = $request->query('active', '1'); // default only active
    $include = collect(explode(',', (string) $request->query('include', '')))
        ->map(fn ($s) => trim($s))
        ->filter()
        ->values();

    $with = [];
    if ($include->contains('category')) $with[] = 'category:id,name,slug';
    if ($include->contains('tags'))     $with[] = 'tags:id,name,slug';
    if ($include->contains('staff'))    $with[] = 'staff:id,name,email';

    $service = Service::query()
        ->when($active !== null, fn ($q) =>
            $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN))
        )
        ->where('id', $id)
        ->with($with)
        ->first();

    if (!$service) {
        return response()->json(['message' => 'Service not found'], 404);
    }

    return $this->presentService($service, $include);
}

    /**
     * GET /api/v1/services/slug/{slug}
     * Public-facing slug variant (recommended for apps).
     */
    public function showBySlug(Request $request, string $slug)
    {
        $active  = $request->query('active', '1'); // default only active
        $include = collect(explode(',', (string) $request->query('include', '')))
            ->map(fn ($s) => trim($s))->filter()->values();

        $with = [];
        if ($include->contains('category')) $with[] = 'category:id,name,slug';
        if ($include->contains('tags'))     $with[] = 'tags:id,name,slug';
        if ($include->contains('staff'))    $with[] = 'staff:id,name,email';

        $service = Service::query()
            ->when($active !== null, fn($q) =>
                $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN))
            )
            ->where('slug', $slug)
            ->with($with)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        return $this->presentService($service, $include);
    }

    /**
     * GET /api/v1/services/suggest?q=
     * Lightweight suggestions for search/autocomplete.
     */
    public function suggest(Request $request)
    {
        $q       = trim((string) $request->query('q', ''));
        $limit   = (int) $request->query('limit', 8);
        $limit   = max(1, min(20, $limit));

        $query = Service::query()
            ->where('is_active', true)
            ->select(['id','name','slug','name_i18n']);

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($qq) use ($like) {
                $qq->where('name', 'like', $like)
                   ->orWhere('name_i18n', 'like', $like);
            });
        }

        $items = $query->orderBy('name')->limit($limit)->get()
            ->map(fn(Service $s) => [
                'id'   => $s->id,
                'name' => $s->name_localized,
                'slug' => $s->slug,
            ]);

        return response()->json(['data' => $items]);
    }

    // ================= Helpers ================= //

    protected function presentService(Service $s, Collection $include)
{
    $data = [
        'id'               => $s->id,
        'slug'             => $s->slug,
        'price'            => (float) $s->price,
        'duration_minutes' => (int) $s->duration_minutes,
        'is_active'        => (bool) $s->is_active,
        'is_bookable'      => (bool) $s->is_bookable,

        // 🔹 Localized (based on app locale / ?lang)
        'name'              => $s->name_localized,
        'short_description' => $s->short_description_localized,
        'description'       => $s->description_localized,
        'prep_instructions' => $s->prep_instructions_localized,

        // 🔹 Full i18n bags (en + sq + mk)
        'name_i18n'              => $s->name_i18n,
        'short_description_i18n' => $s->short_description_i18n,
        'description_i18n'       => $s->description_i18n,
        'prep_instructions_i18n' => $s->prep_instructions,

        'image_url'  => $s->image_url,
        'views_count'=> 0,
    ];

    if ($include->contains('tags')) {
        $data['tags'] = $s->tags->map(fn ($t) => [
            'id'   => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
        ]);
    } else {
        $data['tags'] = null;
    }

    if ($include->contains('category')) {
        $data['category'] = $s->category ? [
            'id'   => $s->category->id,
            'name' => $s->category->name,
            'slug' => $s->category->slug,
        ] : null;
    } else {
        $data['category'] = null;
    }

    if ($include->contains('staff')) {
        $data['staff'] = $s->staff->map(fn ($st) => [
            'id'    => $st->id,
            'name'  => $st->name,
            'email' => $st->email,
        ]);
    }

    return response()->json($data);
}
}
