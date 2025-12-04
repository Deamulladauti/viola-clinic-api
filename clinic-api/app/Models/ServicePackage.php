<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ServicePackage extends Model
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'service_id',
        'service_name',
        'snapshot_total_sessions',
        'snapshot_total_minutes',
        'price_paid',      // legacy / historical
        'price_total',     // ✅ new: full package price
        'currency',
        'remaining_sessions',
        'remaining_minutes',
        'status',
        'starts_on',
        'expires_on',
        'notes',
    ];

    protected $casts = [
        'price_paid'              => 'decimal:2',
        'price_total'             => 'decimal:2', // ✅
        'remaining_sessions'      => 'integer',
        'remaining_minutes'       => 'integer',
        'snapshot_total_sessions' => 'integer',
        'snapshot_total_minutes'  => 'integer',
        'starts_on'               => 'date',
        'expires_on'              => 'date',
    ];

    // ───── RELATIONSHIPS ─────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PackageLog::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PackagePayment::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'service_package_id');
    }

    // ───── SCOPES ─────
    public function scopeActive($q)
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOwnedBy($q, int $userId)
    {
        return $q->where('user_id', $userId);
    }

    // ───── HELPERS ─────
    public function isSessionsType(): bool
    {
        return !is_null($this->remaining_sessions) && is_null($this->remaining_minutes);
    }

    public function isMinutesType(): bool
    {
        return !is_null($this->remaining_minutes) && is_null($this->remaining_sessions);
    }

    /**
     * Exhausted logic:
     * - For session packages → exhausted when remaining_sessions <= 0.
     * - For minutes packages (Solarium) → NEVER auto-exhaust based on minutes,
     *   so staff can keep logging usage even if it goes below 0 (we will just WARN in UI).
     */
    public function isExhausted(): bool
    {
        if ($this->isSessionsType()) {
            return (int) $this->remaining_sessions <= 0;
        }

        if ($this->isMinutesType()) {
            // For solarium: do not automatically mark as exhausted.
            return false;
        }

        return true;
    }

    public function markExhaustedIfNeeded(): void
    {
        if ($this->status === self::STATUS_ACTIVE && $this->isExhausted()) {
            $this->status = self::STATUS_EXHAUSTED;
            $this->save();
        }
    }

    // ───── DEDUCTIONS ─────

    /**
     * Deduct N sessions (Laser package).
     * - Strict: cannot go below 0.
     * - Only active packages allowed.
     */
    public function deductSessions(
        int $count = 1,
        ?int $staffId = null,
        ?string $note = null,
        ?string $when = null,
        ?int $appointmentId = null,
        ?string $appointmentRef = null
    ): self {
        if ($count <= 0) {
            throw new \InvalidArgumentException('used sessions must be > 0');
        }
        if (!$this->isSessionsType()) {
            throw new \LogicException('This package tracks minutes, not sessions.');
        }
        if ($this->status !== self::STATUS_ACTIVE) {
            throw new \LogicException('Package is not active.');
        }

        return \DB::transaction(function () use ($count, $staffId, $note, $when, $appointmentId, $appointmentRef) {
            $this->refresh();

            if ($this->remaining_sessions < $count) {
                throw new \LogicException('Not enough remaining sessions.');
            }

            $this->remaining_sessions -= $count;
            $this->save();

            $this->logs()->create([
                'staff_id'        => $staffId,
                'appointment_id'  => $appointmentId,
                'appointment_ref' => $appointmentRef,
                'used_sessions'   => $count,
                'used_minutes'    => 0,
                'used_at'         => $when ? Carbon::parse($when) : now(),
                'note'            => $note,
            ]);

            $this->markExhaustedIfNeeded();
            return $this;
        });
    }

    /**
     * Deduct X minutes (Solarium).
     *
     * Rules from clinic:
     * - Staff must ALWAYS be able to record usage.
     * - Allowed to go below 0 minutes; system will later WARN but never block.
     */
    public function deductMinutes(
        int $minutes,
        ?int $staffId = null,
        ?string $note = null,
        ?string $when = null,
        ?int $appointmentId = null,
        ?string $appointmentRef = null
    ): self {
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('used minutes must be > 0');
        }
        if (!$this->isMinutesType()) {
            throw new \LogicException('This package tracks sessions, not minutes.');
        }

        // ⚠ We do NOT block based on status or remaining minutes.
        // Staff can still log usage even if balance is negative or status not ideal.
        // UI / API layer will show warnings if remaining_minutes < 0 or status not active.

        return \DB::transaction(function () use ($minutes, $staffId, $note, $when, $appointmentId, $appointmentRef) {
            $this->refresh();

            // Allow going below zero
            $this->remaining_minutes = ($this->remaining_minutes ?? 0) - $minutes;
            $this->save();

            $this->logs()->create([
                'staff_id'        => $staffId,
                'appointment_id'  => $appointmentId,
                'appointment_ref' => $appointmentRef,
                'used_sessions'   => 0,
                'used_minutes'    => $minutes,
                'used_at'         => $when ? Carbon::parse($when) : now(),
                'note'            => $note,
            ]);

            // markExhaustedIfNeeded() won't flip minutes-type packages anymore,
            // because isExhausted() returns false for minutes.
            $this->markExhaustedIfNeeded();

            return $this;
        });
    }

    // ───── PAYMENTS ─────
    protected $appends = ['amount_paid', 'remaining_to_pay'];

    public function getAmountPaidAttribute(): float
    {
        // sum of all non-voided payments
        return (float) $this->payments()->notVoided()->sum('amount');
    }

    public function getRemainingToPayAttribute(): float
    {
        // Prefer price_total; fall back to legacy price_paid
        $total = (float) ($this->price_total ?? $this->price_paid ?? 0);

        return max($total - $this->amount_paid, 0.0);
    }

    public function assertOwnershipForAppointment(\App\Models\Appointment $appointment): void
    {
        if ($appointment->user_id && $appointment->user_id !== $this->user_id) {
            throw new \LogicException('Ownership mismatch: appointment does not belong to package owner.');
        }
    }

    /**
     * Restore previously deducted sessions/minutes for a specific appointment.
     */
    public function restorePackageDeduction(
        ?int $appointmentId = null,
        ?string $appointmentRef = null,
        ?string $note = null
    ): self {
        if (!$appointmentId && !$appointmentRef) {
            throw new \InvalidArgumentException('Appointment ID or reference is required for rollback.');
        }

        return \DB::transaction(function () use ($appointmentId, $appointmentRef, $note) {
            $this->refresh();

            // Only match real deductions (sessions/minutes > 0)
            $log = $this->logs()
                ->where(function ($q) use ($appointmentId, $appointmentRef) {
                    if ($appointmentId) {
                        $q->where('appointment_id', $appointmentId);
                    }
                    if ($appointmentRef) {
                        $q->orWhere('appointment_ref', $appointmentRef);
                    }
                })
                ->where(function ($q) {
                    $q->where('used_sessions', '>', 0)
                      ->orWhere('used_minutes', '>', 0);
                })
                ->orderByDesc('id')
                ->first();

            if (!$log) {
                // idempotent: nothing to restore
                return $this;
            }

            if ($log->used_sessions > 0) {
                $this->remaining_sessions += $log->used_sessions;
            } elseif ($log->used_minutes > 0) {
                $this->remaining_minutes += $log->used_minutes;
            }

            $this->status = self::STATUS_ACTIVE;
            $this->save();

            $this->logs()->create([
                'appointment_id'  => $appointmentId,
                'appointment_ref' => $appointmentRef,
                'staff_id'        => null,
                'used_sessions'   => 0,
                'used_minutes'    => 0,
                'note'            => $note ?? 'Rollback of previously deducted session(s)',
                'used_at'         => now(),
            ]);

            return $this;
        });
    }
}
