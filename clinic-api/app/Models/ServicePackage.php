<?php

namespace App\Models;

use App\Services\PackageUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ServicePackage extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const EUR_TO_MKD = 61.6;

    protected $fillable = [
        'user_id',
        'service_id',
        'assigned_staff_id',
        'service_name',
        'snapshot_total_sessions',
        'snapshot_total_minutes',
        'snapshot_usage_type',
        'snapshot_minimum_interval_days',
        'snapshot_deduction_method',
        'snapshot_staff_policy',
        'snapshot_duration_minutes',
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
        'price_paid' => 'decimal:2',
        'price_total' => 'decimal:2',
        'remaining_sessions' => 'integer',
        'remaining_minutes' => 'integer',
        'snapshot_total_sessions' => 'integer',
        'snapshot_total_minutes' => 'integer',
        'snapshot_minimum_interval_days' => 'integer',
        'snapshot_duration_minutes' => 'integer',
        'starts_on' => 'date',
        'expires_on' => 'date',
    ];

    protected $appends = [
        'amount_paid',
        'amount_paid_mkd',
        'remaining_to_pay',
        'remaining_to_pay_mkd',
        'next_allowed_date',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_PAUSED,
            self::STATUS_EXHAUSTED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PackageLog::class);
    }

    public function activeUsageLogs(): HasMany
    {
        return $this->hasMany(PackageLog::class)->whereNull('voided_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PackagePayment::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'service_package_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function usageType(): string
    {
        if ($this->snapshot_usage_type) {
            return $this->snapshot_usage_type;
        }

        if ($this->remaining_minutes !== null || $this->snapshot_total_minutes !== null) {
            return Service::USAGE_MINUTES;
        }

        return Service::USAGE_SESSION;
    }

    public function deductionMethod(): string
    {
        return $this->snapshot_deduction_method
            ?: ($this->usageType() === Service::USAGE_MINUTES
                ? Service::DEDUCTION_MANUAL
                : Service::DEDUCTION_AUTOMATIC);
    }

    public function staffPolicy(): string
    {
        return $this->snapshot_staff_policy
            ?: $this->service?->staff_policy
            ?: ($this->usageType() === Service::USAGE_SESSION
                ? Service::STAFF_ANY_QUALIFIED
                : Service::STAFF_PER_APPOINTMENT);
    }

    public function isSessionsType(): bool
    {
        return $this->usageType() === Service::USAGE_SESSION;
    }

    public function isMinutesType(): bool
    {
        return $this->usageType() === Service::USAGE_MINUTES;
    }

    public function totalUnits(): int
    {
        return $this->isMinutesType()
            ? (int) ($this->snapshot_total_minutes ?? 0)
            : (int) ($this->snapshot_total_sessions ?? 0);
    }

    public function remainingUnits(): int
    {
        return $this->isMinutesType()
            ? (int) ($this->remaining_minutes ?? 0)
            : (int) ($this->remaining_sessions ?? 0);
    }

    public function isExhausted(): bool
    {
        return $this->remainingUnits() <= 0;
    }

    public function markExhaustedIfNeeded(): void
    {
        if ($this->status === self::STATUS_ACTIVE && $this->isExhausted()) {
            $this->status = self::STATUS_EXHAUSTED;
            $this->save();
        }
    }

    public function assertUsableOn(
        Carbon|string|null $date = null,
        bool $allowBeforeStart = false,
    ): void
    {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date ?? now())->startOfDay();

        if ($this->status !== self::STATUS_ACTIVE) {
            throw new \LogicException('Package is not active.');
        }

        if (
            ! $allowBeforeStart
            && $this->starts_on
            && $date->lt($this->starts_on->copy()->startOfDay())
        ) {
            throw new \LogicException('Package has not started yet.');
        }
    }

    public function assertMatchesAppointment(Appointment $appointment): void
    {
        if (! $appointment->service_package_id || (int) $appointment->service_package_id !== (int) $this->id) {
            throw new \LogicException('Appointment is not linked to this package.');
        }

        if (! $appointment->user_id || (int) $appointment->user_id !== (int) $this->user_id) {
            throw new \LogicException('Appointment client does not match the package owner.');
        }

        if ((int) $appointment->service_id !== (int) $this->service_id) {
            throw new \LogicException('Appointment service does not match the package service.');
        }
    }

    public function assertOwnershipForAppointment(Appointment $appointment): void
    {
        $this->assertMatchesAppointment($appointment);
    }

    public function getNextAllowedDateAttribute(): ?string
    {
        if (! $this->isSessionsType()) {
            return null;
        }

        $interval = (int) ($this->snapshot_minimum_interval_days ?? 0);
        if ($interval <= 0) {
            return null;
        }

        $lastUse = $this->activeUsageLogs()
            ->where('usage_type', Service::USAGE_SESSION)
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->first();

        if (! $lastUse?->occurred_on) {
            return null;
        }

        return $lastUse->occurred_on->copy()->addDays($interval)->toDateString();
    }

    /**
     * @deprecated Session deductions must be tied to a concrete appointment.
     */
    public function deductSessions(
        int $count = 1,
        ?int $staffId = null,
        ?string $note = null,
        ?string $when = null,
        ?int $appointmentId = null,
        ?string $appointmentRef = null,
    ): self {
        if ($count !== 1 || ! $appointmentId) {
            throw new \LogicException('Session packages can only deduct one session from a linked completed appointment.');
        }

        $appointment = Appointment::findOrFail($appointmentId);

        app(PackageUsageService::class)->recordSessionForAppointment(
            appointment: $appointment,
            actorUserId: null,
            note: $note,
            source: PackageLog::SOURCE_AUTOMATIC,
        );

        return $this->refresh();
    }

    /**
     * @deprecated Use PackageUsageService::recordManualQuantityUsage().
     */
    public function deductMinutes(
        int $minutes,
        ?int $staffId = null,
        ?string $note = null,
        ?string $when = null,
        ?int $appointmentId = null,
        ?string $appointmentRef = null,
    ): self {
        if ($appointmentId) {
            throw new \LogicException('Minute packages are walk-in usage and cannot be deducted by an appointment.');
        }

        app(PackageUsageService::class)->recordManualQuantityUsage(
            package: $this,
            quantity: $minutes,
            occurredOn: $when ? Carbon::parse($when)->toDateString() : now()->toDateString(),
            staffId: $staffId,
            actorUserId: null,
            note: $note,
            source: PackageLog::SOURCE_MANUAL,
        );

        return $this->refresh();
    }

    public function restorePackageDeduction(
        ?int $appointmentId = null,
        ?string $appointmentRef = null,
        ?string $note = null,
    ): self {
        $appointment = $appointmentId
            ? Appointment::find($appointmentId)
            : Appointment::where('reference_code', $appointmentRef)->first();

        if (! $appointment) {
            throw new \InvalidArgumentException('A valid appointment is required for usage rollback.');
        }

        app(PackageUsageService::class)->voidAppointmentUsage(
            appointment: $appointment,
            actorUserId: null,
            reason: $note ?? 'Package usage reversed.',
        );

        return $this->refresh();
    }

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
            2,
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
}
