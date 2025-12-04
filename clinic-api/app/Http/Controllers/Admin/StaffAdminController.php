<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\User;

class StaffAdminController extends Controller
{
    /**
     * GET /api/admin/staff
     */
    public function index(Request $request)
    {
        $query = Staff::query()->with('services:id,name');

        // Filter by service
        if ($request->filled('service_id')) {
            $serviceId = (int) $request->input('service_id');
            $query->whereHas('services', fn($q) => $q->where('services.id', $serviceId));
        }

        // Filter by is_active
        if ($request->filled('active')) {
            $isActive = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_null($isActive)) {
                $query->where('is_active', $isActive);
            }
        }

        // Search by name or email
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('email', 'like', "%{$q}%")
                   ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        return response()->json(
            $query->orderBy('name')->paginate($perPage)
        );
    }

    /**
     * POST /api/admin/staff
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255', 'unique:staff,email'],
            'phone'      => ['nullable', 'string', 'max:255'],
            'is_active'  => ['boolean'],
        ]);

        $staff = Staff::create($data);

        return response()->json([
            'message' => 'Staff member created successfully',
            'staff'   => $staff->fresh('services'),
        ], 201);
    }

    /**
     * PUT/PATCH /api/admin/staff/{staff}
     */
    public function update(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255', Rule::unique('staff', 'email')->ignore($staff->id)],
            'phone'      => ['nullable', 'string', 'max:255'],
            'is_active'  => ['boolean'],
        ]);

        $staff->fill($data)->save();

        return response()->json([
            'message' => 'Staff member updated successfully',
            'staff'   => $staff->fresh('services'),
        ]);
    }

    /**
     * DELETE /api/admin/staff/{staff}
     */
    public function destroy(Staff $staff)
    {
        $staff->delete();

        return response()->json(['message' => 'Staff member deleted']);
    }

    // -----------------------------
    // 📅 SCHEDULES
    // -----------------------------

    /**
     * GET /api/admin/staff/{staff}/schedules
     */
    public function listSchedules(Staff $staff)
    {
        $schedules = $staff->schedules()->orderBy('weekday')->get();

        return response()->json(['schedules' => $schedules]);
    }

    /**
     * POST /api/admin/staff/{staff}/schedules
     */
    public function addSchedule(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'weekday'    => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time'   => ['required', 'date_format:H:i:s', 'after:start_time'],
            'is_active'  => ['boolean'],
        ]);

        $schedule = $staff->schedules()->create($data);

        return response()->json([
            'message'  => 'Schedule added',
            'schedule' => $schedule,
        ], 201);
    }

    /**
     * DELETE /api/admin/staff/{staff}/schedules/{schedule}
     */
    public function removeSchedule(Staff $staff, StaffSchedule $schedule)
    {
        abort_if($schedule->staff_id !== $staff->id, 404, 'Schedule not found for this staff member.');

        $schedule->delete();

        return response()->json(['message' => 'Schedule removed']);
    }

    // -----------------------------
    // 🔗 SERVICE LINKS
    // -----------------------------

    /**
     * POST /api/admin/staff/{staff}/sync-services
     */
    public function syncServices(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'service_ids'   => ['array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $ids = $data['service_ids'] ?? [];
        $staff->services()->sync($ids);

        return response()->json([
            'message' => 'Services synced successfully',
            'staff'   => $staff->fresh('services'),
        ]);
    }

    // -----------------------------
    // 💤 TIME OFF
    // -----------------------------

    /**
     * GET /api/admin/staff/{staff}/time-off
     */
    public function listTimeOff(Staff $staff)
    {
        $timeOff = $staff->timeOff()->orderBy('date')->get();

        return response()->json(['time_off' => $timeOff]);
    }

    /**
     * POST /api/admin/staff/{staff}/time-off
     */
    public function addTimeOff(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'date'       => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i:s'],
            'end_time'   => ['nullable', 'date_format:H:i:s', 'after:start_time'],
            'reason'     => ['nullable', 'string', 'max:255'],
        ]);

        $timeOff = $staff->timeOff()->create($data);

        return response()->json([
            'message'  => 'Time off added',
            'time_off' => $timeOff,
        ], 201);
    }

    /**
     * DELETE /api/admin/staff/{staff}/time-off/{timeOff}
     */
    public function removeTimeOff(Staff $staff, StaffTimeOff $timeOff)
    {
        abort_if($timeOff->staff_id !== $staff->id, 404, 'Time off not found for this staff member.');

        $timeOff->delete();

        return response()->json(['message' => 'Time off removed']);
    }

        /**
     * POST /api/admin/users/{user}/make-staff
     *
     * Admin: promote a user to staff.
     * - assigns 'staff' role (Spatie)
     * - creates Staff record if missing
     */
    public function makeStaff(Request $request, User $user)
{
    $data = $request->validate([
        'is_active' => ['sometimes', 'boolean'],
    ]);

    // ❗ Replace all existing roles with ONLY 'staff'
    $user->syncRoles(['staff']);

    // Ensure Staff record exists for this user
    $staff = Staff::firstOrCreate(
        ['user_id' => $user->id],
        [
            'name'      => $user->name,
            'email'     => $user->email,
            'phone'     => $user->phone ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]
    );

    // If is_active explicitly sent, update it
    if (array_key_exists('is_active', $data)) {
        $staff->is_active = $data['is_active'];
        $staff->save();
    }

    return response()->json([
        'message' => 'User promoted to staff.',
        'user'    => [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ],
        'staff'   => [
            'id'        => $staff->id,
            'user_id'   => $staff->user_id,
            'name'      => $staff->name,
            'email'     => $staff->email,
            'phone'     => $staff->phone,
            'is_active' => (bool) $staff->is_active,
        ],
    ], 201);
}

public function listUsers(Request $request)
{
    $query = User::query()->with('roles:id,name');

    // Optional search (name, email, phone)
    if ($q = trim($request->input('q', ''))) {
        $query->where(function ($qq) use ($q) {
            $qq->where('name', 'like', "%{$q}%")
               ->orWhere('email', 'like', "%{$q}%")
               ->orWhere('phone', 'like', "%{$q}%");
        });
    }

    // Filter by active
    if ($request->filled('active')) {
        $isActive = filter_var(
            $request->input('active'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if (!is_null($isActive)) {
            $query->where('is_active', $isActive);
        }
    }

    // Filter by role (client, staff, admin)
    if ($request->filled('role')) {
        $role = $request->input('role');
        $query->whereHas('roles', fn($q) => $q->where('name', $role));
    }

    // Pagination (default 20)
    $perPage = min(100, max(1, (int)$request->input('per_page', 20)));

    return response()->json(
        $query->orderBy('name')->paginate($perPage)
    );
}

}
