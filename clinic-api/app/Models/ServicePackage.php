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

    public const EUR_TO_MKD = 61.6;

    protected $fillable = [
        'user_id',
        'service_id',
        'service_name',
        'snapshot_total_sessions',
        'snapshot_total_minutes',
        'price_paid',
        'price_total',
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
        'price_total'             => 'decimal:2',
        'remaining_sessions'      => 'integer',
        'remaining_minutes'       => 'integer',
        'snapshot_total_sessions' => 'integer',
        'snapshot_total_minutes'  => 'integer',
        'starts_on'               => 'date',
        'expires_on'              => 'date',
    ];

    protected $appends = [
        'amount_paid',
        'amount_paid_mkd',
        'remaining_to_pay',
        'remaining_to_pay_mkd',
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

    public function isExhausted(): bool
    {
        if ($this->isSessionsType()) {
            return (int) $this->remaining_sessions <= 0;
        }

        if ($this->isMinutesType()) {
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

        return \DB::transaction(function () use ($minutes, $staffId, $note, $when, $appointmentId, $appointmentRef) {
            $this->refresh();

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

            $this->markExhaustedIfNeeded();

            return $this;
        });
    }

    // ───── PAYMENTS ─────

    public function getAmountPaidAttribute(): float
    {
        return round($this->convertMkdToPackageCurrency($this->amount_paid_mkd), 2);
    }

    public function getAmountPaidMkdAttribute(): float
    {
        return round(
            $this->payments()
                ->notVoided()
                ->get()
                ->sum(fn (PackagePayment $payment) => $this->paymentAmountToMkd($payment)),
            2
        );
    }

    public function getRemainingToPayAttribute(): float
    {
        return round($this->convertMkdToPackageCurrency($this->remaining_to_pay_mkd), 2);
    }

    public function getRemainingToPayMkdAttribute(): float
    {
        return round(max($this->priceTotalMkd() - $this->amount_paid_mkd, 0), 2);
    }

    public function priceTotalMkd(): float
    {
        $total = (float) ($this->price_total ?? $this->price_paid ?? 0);

        if ($this->packageCurrency() === 'EUR') {
            return round($total * self::EUR_TO_MKD, 2);
        }

        return round($total, 2);
    }

    public function packageCurrency(): string
    {
        return strtoupper($this->currency ?: 'EUR');
    }

    public function paymentAmountToMkd(PackagePayment $payment): float
    {
        if ($payment->amount_mkd !== null) {
            return (float) $payment->amount_mkd;
        }

        $amount = (float) $payment->amount;
        $currency = strtoupper($payment->currency ?: $this->packageCurrency());

        if ($currency === 'EUR') {
            $rate = (float) ($payment->exchange_rate ?: self::EUR_TO_MKD);

            return round($amount * $rate, 2);
        }

        return round($amount, 2);
    }

    public function convertMkdToPackageCurrency(float $amountMkd): float
    {
        if ($this->packageCurrency() === 'EUR') {
            return $amountMkd / self::EUR_TO_MKD;
        }

        return $amountMkd;
    }

    public function assertOwnershipForAppointment(Appointment $appointment): void
    {
        if ($appointment->user_id && $appointment->user_id !== $this->user_id) {
            throw new \LogicException('Ownership mismatch: appointment does not belong to package owner.');
        }
    }

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