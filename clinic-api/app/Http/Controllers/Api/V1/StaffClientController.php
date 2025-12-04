<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class StaffClientController extends Controller
{
    /**
     * GET /api/v1/staff/clients/lookup?phone=+38970111222
     *
     * Returns:
     *  - 200 with client data if found
     *  - 404 with {found:false} if not
     */
    public function lookupByPhone(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $raw = trim($validated['phone']);           // e.g. "+38970111222"
        $digits = preg_replace('/\D+/', '', $raw);  // e.g. "38970111222"

        // Base query on User model
        $query = User::query()
            ->where(function ($q) use ($raw, $digits) {
                // exact stored value
                $q->where('phone', $raw);

                // allow flexible matching too
                if ($digits !== '') {
                    $q->orWhere('phone', $digits)
                      ->orWhere('phone', '+' . $digits);
                }
            });

        // 👉 If you are sure clients have role "client", keep this:
        $query->whereHas('roles', function ($q) {
            $q->where('name', 'client');
        });

        // (SoftDeletes is already handled by the trait, so trashed users are excluded)

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
