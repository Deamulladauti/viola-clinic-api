<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminClientController extends Controller
{
    public function lookupByPhone(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $raw = trim($validated['phone']);            // e.g. "+38970111222"
        $digits = preg_replace('/\D+/', '', $raw);  // e.g. "38970111222"

        $query = User::query()
            ->where(function ($q) use ($raw, $digits) {
                $q->where('phone', $raw);

                if ($digits !== '') {
                    $q->orWhere('phone', $digits)
                      ->orWhere('phone', '+' . $digits);
                }
            });

        $query->whereHas('roles', function ($q) {
            $q->where('name', 'client');
        });

        $user = $query->first();

        if (! $user) {
            return response()->json([
                'found'   => false,
                'message' => 'Client not found',
            ], 404);
        }

        return response()->json([
            'found'  => true,
            'client' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }
}