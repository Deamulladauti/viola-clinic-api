<?php

namespace App\Models;

use App\Services\AppointmentCompletionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const SOURCE_CLIENT_BOOKING = 'client_booking';
    public const SOURCE_ADMIN_BOOKING = 'admin_booking';
    public const SOURCE_MANUAL_IMPORT = 'manual_import';
    public const SOURCE_LEGACY = 'legacy';

    protected $fillable = [
        'service_id',
        'service_package_id',
        'staff_id',
        'user_id',
        'date',
        'starts_at',
        'duration_minutes',
        'price',
        'sale_original_price',
        'sale_discount_type',
        'sale_discount_value',
        'sale_discount_amount',
        'sale_final_price',
        'customer_name',
        'customer_phone',
        'customer_email',
        'status',
        'source',
        'notes',
        'reference_code',
        'admin_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'starts_at' => 'string',
        'duration_minutes' => 'integer',
        'price' => 'decimal:2',
        'sale_original_price' => 'decimal:2',
        'sale_discount_value' => 'decimal:2',
        'sale_discount_amount' => 'decimal:2',
        'sale_final_price' => 'decimal:2',
    ];

    protected $appends = ['amount_paid', 'remaining_to_pay'];

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->initializeSalePriceSnapshot();
        });
    }

    /**
     * Freeze the commercial terms of a single treatment at the time it is sold.
     *
     * The legacy `price` column remains synchronized with the final sale price
     * for backwards compatibility, but balances should use `sale_final_price`.
     */
    public function initializeSalePriceSnapshot(): void
    {
        $finalPrice = (float) ($this->sale_final_price ?? $this->price ?? 0);

        $servicePrice = null;
        if ($this->service_id) {
            $servicePrice = Service::query()
                ->whereKey($this->service_id)
                ->value('price');
        }

        $originalPrice = (float) ($this->sale_original_price
            ?? $servicePrice
            ?? $finalPrice);

        $discountAmount = $this->sale_discount_amount !== null
            ? (float) $this->sale_discount_amount
            : max($originalPrice - $finalPrice, 0);

        $discountType = $this->sale_discount_type;
        $discountValue = $this->sale_discount_value !== null
            ? (float) $this->sale_discount_value
            : null;

        if ($discountAmount > 0 && !$discountType) {
            // Before Task 9, any lower explicit sale price is preserved as a
            // fixed discount. Task 9 will allow Admin to choose fixed/percent.
            $discountType = 'fixed';
            $discountValue = $discountValue ?? $discountAmount;
        }

        if ($discountAmount <= 0) {
            $discountType = null;
            $discountValue = null;
            $discountAmount = 0;
        }

        $this->forceFill([
            'sale_original_price' => round($originalPrice, 2),
            'sale_discount_type' => $discountType,
            'sale_discount_value' => $discountValue !== null ? round($discountValue, 2) : null,
            'sale_discount_amount' => round($discountAmount, 2),
            'sale_final_price' => round($finalPrice, 2),
            // Compatibility with existing API/UI code.
            'price' => round($finalPrice, 2),
        ]);
    }

    public function finalSalePrice(): float
    {
        return (float) ($this->sale_final_price ?? $this->price ?? 0);
    }

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

    public function user()
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
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function packageUsage()
    {
        return $this->hasOne(PackageLog::class, 'appointment_id')->whereNull('voided_at');
    }

    public function payments()
    {
        return $this->hasMany(PackagePayment::class, 'appointment_id');
    }

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

    public static function sources(): array
    {
        return [
            self::SOURCE_CLIENT_BOOKING,
            self::SOURCE_ADMIN_BOOKING,
            self::SOURCE_MANUAL_IMPORT,
            self::SOURCE_LEGACY,
        ];
    }

    public function setStatus(string $status): void
    {
        if (! in_array($status, self::statuses(), true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $this->status = $status;
    }

    public function canComplete(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true);
    }

    public function canCancel(): bool
    {
        return $this->status !== self::STATUS_CANCELLED;
    }

    public function getStartsAtDateTimeAttribute(): ?Carbon
    {
        if (! $this->date || ! $this->starts_at) {
            return null;
        }

        $date = $this->date instanceof Carbon
            ? $this->date->toDateString()
            : Carbon::parse($this->date)->toDateString();

        return Carbon::parse("{$date} {$this->starts_at}", config('app.timezone'));
    }

    /**
     * Backwards-compatible wrapper. New code should inject AppointmentCompletionService.
     */
    public function completeWithPackageDeduction(int $sessions = 1, ?int $staffId = null, ?string $note = null): void
    {
        if ($sessions !== 1) {
            throw new \LogicException('One appointment can deduct exactly one package session.');
        }

        app(AppointmentCompletionService::class)->complete(
            appointment: $this,
            actorUserId: null,
            note: $note,
            source: PackageLog::SOURCE_AUTOMATIC,
        );

        $this->refresh();
    }

    /**
     * Backwards-compatible wrapper for correcting a completed appointment.
     */
    public function cancelWithPackageRollback(?string $note = null): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            app(AppointmentCompletionService::class)->reverseCompletion(
                appointment: $this,
                targetStatus: self::STATUS_CANCELLED,
                actorUserId: null,
                reason: $note ?? 'Appointment cancelled after completion.',
            );
        } else {
            $this->status = self::STATUS_CANCELLED;
            $this->save();
        }

        $this->refresh();
    }

    public function getAmountPaidAttribute(): float
    {
        return (float) $this->payments()->notVoided()->sum('amount');
    }

    public function getRemainingToPayAttribute(): float
    {
        if ($this->service_package_id && $this->servicePackage) {
            return 0.0;
        }

        $total = $this->finalSalePrice();

        return max($total - $this->amount_paid, 0.0);
    }
}
