<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW   = 'no_show';

    protected $fillable = [
        'service_id',
        'service_package_id',
        'staff_id',
        'user_id',
        'date',
        'starts_at',
        'duration_minutes',
        'price',
        'customer_name',
        'customer_phone',
        'customer_email',
        'status',
        'notes',
        'reference_code',
        'admin_notes',
    ];

    protected $casts = [
        'date'             => 'date',
        'starts_at'        => 'string',     // TIME column kept as string
        'duration_minutes' => 'integer',
        'price'            => 'decimal:2',
    ];

    // We want JSON to automatically include payment info per appointment
    protected $appends = [
        'amount_paid',
        'remaining_to_pay',
    ];

    // ───── RELATIONSHIPS ─────

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logs()
    {
        return $this->hasMany(AppointmentLog::class)->latest();
    }

    public function servicePackage()
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function package()
    {
        // Alias so controllers can use $appointment->package
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    /**
     * Payments linked directly to this appointment.
     *
     * Usage:
     * - One-time services: payments will be stored with appointment_id set and service_package_id = null.
     * - Session packages: normally payments are stored on ServicePackage instead,
     *   so this relation will usually be empty in that case.
     */
    public function payments()
    {
        return $this->hasMany(PackagePayment::class, 'appointment_id');
    }

    public function user()
    {
        // Alias to the same relation as client()
        return $this->belongsTo(User::class, 'user_id');
    }

    // ───── STATUS HELPERS ─────

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ];
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::statuses(), true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
    }

    public function canComplete(): bool
    {
        // allow completing from pending/confirmed only
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true);
    }

    public function canCancel(): bool
    {
        // you can cancel unless already cancelled
        return $this->status !== self::STATUS_CANCELLED;
    }

    // Optional convenience: normalized start DateTime in app timezone
    public function getStartsAtDateTimeAttribute(): ?Carbon
    {
        if (!$this->date || !$this->starts_at) return null;

        $dateStr = $this->getAttribute('date');
        return Carbon::parse("{$dateStr} {$this->starts_at}", config('app.timezone'));
    }

    // ───── COMPLETION / CANCEL WITH PACKAGE ─────

    public function completeWithPackageDeduction(int $sessions = 1, ?int $staffId = null, ?string $note = null): void
    {
        // idempotency: if already completed, do nothing
        if ($this->status === self::STATUS_COMPLETED) {
            return;
        }

        if (!$this->canComplete()) {
            throw new \LogicException("Cannot complete appointment from status '{$this->status}'.");
        }

        if (!$this->service_package_id) {
            // no package → just mark completed
            $this->setStatus(self::STATUS_COMPLETED);
            $this->save();
            return;
        }

        $pkg = $this->servicePackage()->lockForUpdate()->firstOrFail();

        // ownership guard (only if appointment has a user)
        $pkg->assertOwnershipForAppointment($this);

        $pkg->deductSessions(
            $sessions,
            staffId: $staffId,
            note: $note ?? 'Auto-deduct on completion',
            when: now()->toDateTimeString(),
            appointmentId: $this->id,
            appointmentRef: $this->reference_code
        );

        $this->setStatus(self::STATUS_COMPLETED);
        $this->save();
    }

    public function cancelWithPackageRollback(?string $note = null): void
    {
        // idempotency: already cancelled → no-op
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        // If it was completed and has a package, restore the deduction
        if ($this->status === self::STATUS_COMPLETED && $this->service_package_id) {
            $pkg = $this->servicePackage()->lockForUpdate()->firstOrFail();
            $pkg->assertOwnershipForAppointment($this);

            $pkg->restorePackageDeduction(
                appointmentId: $this->id,
                appointmentRef: $this->reference_code,
                note: $note ?? 'Auto-rollback due to appointment cancellation'
            );
        }

        // Finally mark as cancelled
        if (!$this->canCancel()) {
            return; // or throw if you prefer strictness
        }
        $this->setStatus(self::STATUS_CANCELLED);
        $this->save();
    }

    // ───── PAYMENT ACCESSORS (ONE-TIME SERVICES) ─────

    /**
     * Total amount paid directly against this appointment.
     *
     * For one-time services:
     *   - You will store payments with appointment_id = this id and service_package_id = null.
     *   - Then amount_paid = sum of those payment amounts.
     *
     * For package sessions:
     *   - Payments should normally go on the ServicePackage instead,
     *     so this will usually be 0, and UI should read from $appointment->package->amount_paid.
     */
    public function getAmountPaidAttribute(): float
    {
        return (float) $this->payments()->notVoided()->sum('amount');
    }

    /**
     * Remaining to pay for this appointment.
     *
     * - For one-time services (no package):
     *     remaining_to_pay = max(price - amount_paid, 0).
     * - For appointments linked to a package:
     *     we consider the package as the billing unit, so this returns 0 and
     *     remaining balance is shown from $appointment->package->remaining_to_pay.
     */
    public function getRemainingToPayAttribute(): float
    {
        // If this appointment belongs to a package, billing is tracked at package level
        if ($this->service_package_id && $this->servicePackage) {
            return 0.0;
        }

        $total = (float) ($this->price ?? 0);
        return max($total - $this->amount_paid, 0.0);
    }
}
