<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\ServicePackage;
use App\Models\PackageLog;           // ✅ use the correct log model
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff area — package utilities while working with clients.
 *
 * Typical use:
 *  - see client balances
 *  - attach/detach a package to an appointment
 *  - manually consume sessions/minutes (walk-ins, extra usage)
 *
 * Routes to match:
 *  GET   /api/v1/staff/packages                         -> index
 *  PATCH /api/v1/staff/appointments/{id}/attach-package -> attachToAppointment
 *  PATCH /api/v1/staff/appointments/{id}/detach-package -> detachFromAppointment
 *  POST  /api/v1/staff/packages/{package}/use           -> usePackage
 *  POST  /api/v1/staff/packages/{package}/payments      -> addPayment (if routed)
 */
class StaffPackageController extends Controller
{
    /**
     * GET /api/v1/staff/packages
     * Filters: client_id (required), service_id? (optional), status? (active|used|expired|frozen)
     */
    public function index(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'client_id'  => ['required','integer','min:1'],
            'service_id' => ['sometimes','integer','min:1'],
            'status'     => ['sometimes', Rule::in(['active','used','expired','frozen'])],
        ]);

        $q = ServicePackage::query()
            ->where('user_id', $data['client_id']);

        if (!empty($data['service_id'])) {
            $q->where('service_id', (int) $data['service_id']);
        }
        if (!empty($data['status'])) {
            $q->where('status', $data['status']);
        }

        $packages = $q->orderBy('status')->orderBy('expires_on')->get([
            'id','user_id','service_id','remaining_sessions','remaining_minutes',
            'starts_on','expires_on','status',
        ]);

        return response()->json([
            'data' => $packages->map(function (ServicePackage $p) {
                return [
                    'id'                 => $p->id,
                    'service_id'         => $p->service_id,
                    'remaining_sessions' => $p->remaining_sessions,
                    'remaining_minutes'  => $p->remaining_minutes,
                    'starts_on'          => $p->starts_on?->toDateString(),
                    'expires_on'         => $p->expires_on?->toDateString(),
                    'status'             => $p->status,
                ];
            }),
        ]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/attach-package
     * Body: { package_id }
     * Attaches an ACTIVE and eligible package owned by the client to this appointment.
     */
    public function attachToAppointment(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['client','service'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }
        if (!$a->client) {
            return response()->json(['message' => 'Only appointments with registered clients can attach a package'], 422);
        }

        $v = $request->validate([
            'package_id' => ['required','integer','min:1'],
        ]);

        DB::transaction(function () use ($a, $v) {
            $pkg = ServicePackage::lockForUpdate()->find($v['package_id']);
            if (!$pkg || $pkg->user_id !== $a->client->id) {
                abort(422, 'Package does not belong to this client.');
            }
            if ($pkg->service_id !== $a->service_id) {
                abort(422, 'Package is for a different service.');
            }
            if ($pkg->status !== 'active') {
                abort(422, 'Package is not active.');
            }
            if ($pkg->starts_on && Carbon::today()->lt(Carbon::parse($pkg->starts_on))) {
                abort(422, 'Package not started yet.');
            }
            if ($pkg->expires_on && Carbon::today()->gt(Carbon::parse($pkg->expires_on))) {
                abort(422, 'Package expired.');
            }

            // Ensure there is balance for this appointment
            $ok = false;
            if (!is_null($pkg->remaining_sessions) && $pkg->remaining_sessions > 0) {
                $ok = true;
            }
            if (!is_null($pkg->remaining_minutes)  && $pkg->remaining_minutes >= (int)$a->duration_minutes) {
                $ok = true;
            }
            if (!$ok) {
                abort(422, 'Package has insufficient balance.');
            }

            $a->service_package_id = $pkg->id;
            $a->save();

            AppointmentLog::create([
                'appointment_id' => $a->id,
                'action'         => 'package_attached',
                'meta'           => json_encode(['package_id' => $pkg->id]),
            ]);
        });

        $a->refresh()->loadMissing('package');

        return response()->json([
            'message' => 'Package attached',
            'data'    => [
                'appointment_id' => $a->id,
                'package_id'     => $a->service_package_id,
            ],
        ], 200);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/detach-package
     * Detach any linked package from the appointment (if not completed).
     */
    public function detachFromAppointment(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }
        if ($a->status === 'completed') {
            return response()->json(['message' => 'Cannot detach package from a completed appointment'], 422);
        }

        if ($a->service_package_id) {
            $pkgId = $a->service_package_id;
            $a->service_package_id = null;
            $a->save();

            AppointmentLog::create([
                'appointment_id' => $a->id,
                'action'         => 'package_detached',
                'meta'           => json_encode(['package_id' => $pkgId]),
            ]);
        }

        return response()->json([
            'message' => 'Package detached',
            'data'    => ['appointment_id' => $a->id],
        ]);
    }

    /**
     * POST /api/v1/staff/packages/{package}/use
     * Body: {
     *   "type": "session"|"minutes",
     *   "amount": int >= 1,
     *   "appointment_id"?: int (optional link to appointment),
     *   "note"?: string
     * }
     *
     * Used for manual consumption of package balance (walk-ins, extra usage).
     */
    public function usePackage(Request $request, ServicePackage $package)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'type'           => ['required','in:session,minutes'],
            'amount'         => ['required','integer','min:1'],
            'appointment_id' => ['sometimes','nullable','integer','exists:appointments,id'],
            'note'           => ['sometimes','nullable','string','max:2000'],
        ]);

        DB::transaction(function () use ($package, $data, $staff) {
            // Basic guards
            if ($package->status !== 'active') {
                abort(422, 'Package is not active.');
            }
            if ($package->starts_on && Carbon::today()->lt(Carbon::parse($package->starts_on))) {
                abort(422, 'Package not started yet.');
            }
            if ($package->expires_on && Carbon::today()->gt(Carbon::parse($package->expires_on))) {
                abort(422, 'Package expired.');
            }

            $amount = (int) $data['amount'];

            if ($data['type'] === 'session') {
                if (is_null($package->remaining_sessions)) {
                    abort(422, 'This package does not track sessions.');
                }
                if ($package->remaining_sessions < $amount) {
                    abort(422, 'Not enough remaining sessions.');
                }
                $package->remaining_sessions -= $amount;
            } else {
                if (is_null($package->remaining_minutes)) {
                    abort(422, 'This package does not track minutes.');
                }
                if ($package->remaining_minutes < $amount) {
                    abort(422, 'Not enough remaining minutes.');
                }
                $package->remaining_minutes -= $amount;
            }

            // Update status if depleted
            if (
                (!is_null($package->remaining_sessions) && $package->remaining_sessions <= 0) ||
                (!is_null($package->remaining_minutes)  && $package->remaining_minutes <= 0)
            ) {
                $package->status = 'used';
            }

            $package->save();

            // 🔎 Decide what to log
            $usedSessions   = null;
            $usedMinutes    = null;

            if ($data['type'] === 'session') {
                $usedSessions = $amount;
            } else {
                $usedMinutes = $amount;
            }

            $appointmentId  = $data['appointment_id'] ?? null;
            $appointmentRef = null;

            if ($appointmentId) {
                $appointment   = Appointment::find($appointmentId);
                $appointmentRef = $appointment?->reference_code;
            }

            // ✅ Log usage into PackageLog
            PackageLog::create([
                'service_package_id' => $package->id,
                'staff_id'           => $staff->id,
                'appointment_id'     => $appointmentId,
                'appointment_ref'    => $appointmentRef,
                'used_sessions'      => $usedSessions,
                'used_minutes'       => $usedMinutes,
                'used_at'            => now(),
                'note'               => $data['note'] ?? null,
            ]);
        });

        $package->refresh();

        return response()->json([
            'message' => 'Package usage recorded',
            'data'    => [
                'id'                 => $package->id,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes'  => $package->remaining_minutes,
                'status'             => $package->status,
            ],
        ]);
    }

    /**
     * POST /api/v1/staff/packages/{package}/payments
     * Staff adds a payment toward this package.
     */
    public function addPayment(Request $request, ServicePackage $package)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'method'         => ['required', 'in:cash,card,bank,other'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        // Total price for this package
        $priceTotal = (float) ($package->price_total ?? $package->price_paid ?? 0);
        $alreadyPaid = (float) $package->amount_paid;   // accessor on ServicePackage
        $remaining   = max(0, $priceTotal - $alreadyPaid);

        if ($priceTotal <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'Package has no total price set.',
            ], 422);
        }

        if ($data['amount'] > $remaining + 0.01) {
            return response()->json([
                'ok'               => false,
                'message'          => 'Amount exceeds remaining balance.',
                'remaining_before' => $remaining,
            ], 422);
        }

        DB::transaction(function () use ($package, $data, $staff) {
            $package->payments()->create([
                'service_package_id' => $package->id,
                'appointment_id'     => $data['appointment_id'] ?? null,
                'user_id'            => $package->user_id,           // owner of the package
                'staff_id'           => $staff->id,
                'admin_id'           => null,                        // or set if admin endpoint
                'method'             => $data['method'],
                'amount'             => $data['amount'],
                'currency'           => $package->currency ?? 'EUR',
                'notes'              => $data['note'] ?? null,
            ]);

            $package->refresh();
        });

        $package->refresh();

        return response()->json([
            'ok'                => true,
            'message'           => 'Payment recorded (staff).',
            'package_id'        => $package->id,
            'price_total'       => (float) ($package->price_total ?? 0),
            'amount_paid'       => (float) $package->amount_paid,
            'remaining_balance' => (float) $package->remaining_to_pay,
        ]);
    }
}
