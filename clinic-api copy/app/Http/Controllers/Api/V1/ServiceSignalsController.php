<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;

class ServiceSignalsController extends Controller
{
    // POST /api/v1/signals/services/{id}/view
    public function view(int $id)
{
    $service =  Service::find($id);
    if (!$service) {
        return response()->json(['ok' => false, 'message' => 'Service not found'], 404);
    }
    $service->increment('views_count');
    return response()->json(['ok' => true, 'id' => $service->id, 'views' => (int) $service->views_count]);
}
}
