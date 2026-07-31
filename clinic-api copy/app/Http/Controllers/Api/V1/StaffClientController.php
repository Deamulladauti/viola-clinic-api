<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Str;

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

    public function search(Request $request)
    {
        $validated = $request->validate([
            's'        => ['required', 'string', 'min:1', 'max:80'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $s = trim($validated['s']);
        $perPage = (int) ($validated['per_page'] ?? 20);

        // Digits for phone search
        $digits = preg_replace('/\D+/', '', $s);

        $q = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'client');
            })
            ->where(function ($q) use ($s, $digits) {
                // name/email search
                $q->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%");

                // phone search (raw + digits variants)
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%")
                    ->orWhere('phone', 'like', "%+{$digits}%")
                    ->orWhere('phone', 'like', "%{$s}%");
                } else {
                    $q->orWhere('phone', 'like', "%{$s}%");
                }
            })
            ->orderBy('name')
            ->limit($perPage)
            ->get(['id','name','email','phone']);

        return response()->json([
            'data' => $q->map(fn($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
            ]),
        ]);
    }
}
