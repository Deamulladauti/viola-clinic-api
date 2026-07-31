<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;

class StaffServiceController extends Controller
{
    /**
     * GET /staff/services
     * List of ACTIVE services for staff, with category name included.
     */
    public function index()
    {
        $services = Service::query()
            ->where('is_active', true)
            ->with(['category:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $services->map(function ($s) {
                return [
                    'id'            => $s->id,
                    'name'          => $s->name,
                    'price'         => $s->price,
                    'category_id'   => $s->service_category_id,
                    'category_name' => optional($s->category)->name,
                ];
            }),
        ]);
    }
}
