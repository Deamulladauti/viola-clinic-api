<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StaffProfileController extends Controller
{
    /**
     * GET /api/v1/staff/me
     * Returns logged-in staff profile (user + staff record + services).
     */
    public function show(Request $request)
    {
        $user = $request->user()->loadMissing([
            'roles',
            'staff.services:id,name,slug',
        ]);

        $staff = $user->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        return response()->json([
            'user' => [
                'id'                 => $user->id,
                'name'               => $user->name,
                'email'              => $user->email,
                'phone'              => $user->phone,
                'preferred_language' => $user->preferred_language,
                'avatar_url'         => $user->avatar_url,
            ],
            'staff' => [
                'id'        => $staff->id,
                'name'      => $staff->name,
                'email'     => $staff->email,
                'phone'     => $staff->phone,
                'is_active' => (bool) $staff->is_active,
                'services'  => $staff->services->map(fn ($s) => [
                    'id'   => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                ]),
            ],
        ]);
    }
}
