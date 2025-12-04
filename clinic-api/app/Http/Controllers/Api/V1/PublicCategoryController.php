<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ServiceCategory;
use App\Models\Service;

class PublicCategoryController extends Controller
{
    /**
     * GET /api/v1/categories
     * Public list of ACTIVE categories with optional search & sorting.
     * - sort = name_asc | name_desc | services_desc
     * Counts reflect only ACTIVE services.
     */
    public function index(Request $request)
    {
        $q    = trim((string) $request->query('q', ''));
        $sort = $request->query('sort', 'name_asc'); // name_asc | name_desc | services_desc

        $query = ServiceCategory::query()
            ->where('is_active', true)
            ->withCount([
                // show only active services in the public-facing count
                'services as services_count' => fn ($s) => $s->where('is_active', true),
            ]);

        // search by name (+ i18n if you store it)
        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                  ->orWhere('description', 'like', $like);
                // If you keep i18n JSON: add orWhere('name_i18n','like',$like) as needed
            });
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

        $data = $categories->map(function (ServiceCategory $c) {
            return [
                'id'             => $c->id,
                'name'           => $c->name,         // or $c->name_localized if you add an accessor
                'slug'           => $c->slug,
                'description'    => $c->description,  // or *_localized
                'image_url'      => $c->image_url,    // accessor on the model
                'services_count' => (int) $c->services_count,
            ];
        });

        return response()->json([
            'filters' => [
                'q'    => $q ?: null,
                'sort' => $sort,
            ],
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/categories/{id}/services
     * Public services list by CATEGORY ID (active-only).
     * Mirrors the /services filters to keep the FE simple.
     */
    public function servicesByCategory(Request $request, int $id)
    {
        $category = ServiceCategory::query()
            ->where('is_active', true)
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return $this->servicesForCategory($request, $category);
    }

    /**
     * GET /api/v1/categories/slug/{slug}/services
     * Same as above but with CATEGORY SLUG (recommended).
     */
    public function servicesByCategorySlug(Request $request, string $slug)
    {
        $category = ServiceCategory::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return $this->servicesForCategory($request, $category);
    }

    // ===================== Helpers ===================== //

    /**
     * Shared implementation for services listing inside a category.
     */
    protected function servicesForCategory(Request $request, ServiceCategory $category)
    {
        // Params
        $q            = trim((string) $request->query('q', ''));
        $tagParam     = trim((string) $request->query('tag', '')); // single or CSV
        $minPrice     = $request->query('min_price');
        $maxPrice     = $request->query('max_price');
        $minDuration  = $request->query('min_duration');
        $maxDuration  = $request->query('max_duration');
        $sort         = $request->query('sort', 'newest'); // newest|price_asc|price_desc|duration_asc|duration_desc|popular|name_asc|name_desc
        $perPage      = (int) $request->query('per_page', 12);
        $perPage      = max(1, min(50, $perPage));

        // Build query (ACTIVE services only for public)
        $query = Service::query()
            ->where('service_category_id', $category->id)
            ->where('is_active', true)
            ->with(['category:id,name,slug', 'tags:id,name,slug'])
            ->select([
                'id',
                'service_category_id',
                'name',
                'slug',
                'short_description',
                'description',
                'duration_minutes',
                'price',
                'is_active',
                'is_bookable',
                'image_path',
                'created_at',
            ]);

        // text search
        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($qq) use ($like) {
                $qq->where('name', 'like', $like)
                   ->orWhere('short_description', 'like', $like)
                   ->orWhere('description', 'like', $like);
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
                    $tq->where(function ($x) use ($tags) {
                        $x->whereIn('slug', $tags)
                          ->orWhereIn('name', $tags);
                    });
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
                // No views_count column → fallback to newest or name
                $query->orderByDesc('created_at');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('name', 'desc');
                break;
            default: // newest
                $query->orderByDesc('created_at');
        }

        // paginate
        $paginator = $query->paginate($perPage)->appends($request->query());

        // shape items (use localized accessors if present on Service)
        $items = collect($paginator->items())->map(function (Service $s) {
            return [
                'id'                => $s->id,
                'name'              => method_exists($s, 'getNameLocalizedAttribute')
                    ? $s->name_localized
                    : $s->name,
                'slug'              => $s->slug,
                'price'             => (float) $s->price,
                'duration_minutes'  => (int) $s->duration_minutes,
                'short_description' => method_exists($s, 'getShortDescriptionLocalizedAttribute')
                    ? $s->short_description_localized
                    : $s->short_description,
                'image_url'         => $s->image_url, // accessor from model
                'tags'              => $s->tags->pluck('name')->values()->all(),
                'category'          => [
                    'name' => $s->category?->name,
                    'slug' => $s->category?->slug,
                ],
                // Not backed by DB anymore → always 0 or remove if unused in FE
                'views_count'       => 0,
                'is_bookable'       => (bool) $s->is_bookable,
            ];
        });

        return response()->json([
            'category' => [
                'id'          => $category->id,
                'name'        => $category->name,         // or $category->name_localized
                'slug'        => $category->slug,
                'description' => $category->description,  // or *_localized
                'image_url'   => $category->image_url,
            ],
            'filters' => [
                'q'            => $q ?: null,
                'tag'          => $tagParam ?: null,
                'min_price'    => is_numeric($minPrice) ? (float) $minPrice : null,
                'max_price'    => is_numeric($maxPrice) ? (float) $maxPrice : null,
                'min_duration' => is_numeric($minDuration) ? (int) $minDuration : null,
                'max_duration' => is_numeric($maxDuration) ? (int) $maxDuration : null,
                'sort'         => $sort,
                'per_page'     => $perPage,
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
 * GET /api/v1/categories/{id}
 * Public category detail (ACTIVE only).
 */
public function show(int $id)
{
    $category = ServiceCategory::query()
        ->where('is_active', true)
        ->withCount([
            // only active services in the count, same as index()
            'services as services_count' => fn ($s) => $s->where('is_active', true),
        ])
        ->find($id);

    if (!$category) {
        return response()->json(['message' => 'Category not found'], 404);
    }

    return response()->json([
        'data' => [
            'id'             => $category->id,
            'name'           => $category->name,        // or $category->name_localized
            'slug'           => $category->slug,
            'description'    => $category->description, // or *_localized
            'image_url'      => $category->image_url,   // accessor on the model
            'services_count' => (int) $category->services_count,
        ],
    ]);
}

}
