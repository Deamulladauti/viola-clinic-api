<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeNotificationsController extends Controller
{
    // GET /api/v1/me/notifications
    public function index(Request $request)
    {
        $user = $request->user();
        $items = $user->notifications()->latest()->paginate(20);

        return response()->json([
            'data' => $items->through(function ($n) {
                return [
                    'id'         => $n->id,
                    'type'       => $n->data['type'] ?? $n->type,
                    'title'      => $n->data['title'] ?? null,
                    'body'       => $n->data['body'] ?? null,
                    'read_at'    => $n->read_at?->toISOString(),
                    'created_at' => $n->created_at->toISOString(),
                    'payload'    => $n->data,
                ];
            }),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    // PATCH /api/v1/me/notifications/{id}/read
    public function markRead(Request $request, string $id)
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        if (!$n->read_at) {
            $n->markAsRead();
        }
        return response()->json(['ok' => true]);
    }

    // DELETE /api/v1/me/notifications/{id}
    public function destroy(Request $request, string $id)
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->delete();
        return response()->json(['ok' => true]);
    }
}
