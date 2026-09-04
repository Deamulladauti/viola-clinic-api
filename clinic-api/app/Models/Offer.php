<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Offer extends Model
{
    use SoftDeletes;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED_DISCOUNT = 'fixed_discount';
    public const TYPE_FIXED_PRICE = 'fixed_price';

    protected $fillable = [
        'name',
        'description',
        'pricing_type',
        'value',
        'starts_on',
        'ends_on',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public static function pricingTypes(): array
    {
        return [
            self::TYPE_PERCENT,
            self::TYPE_FIXED_DISCOUNT,
            self::TYPE_FIXED_PRICE,
        ];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'offer_service')->withTimestamps();
    }

    public function isCurrentlyActive(?Carbon $date = null): bool
    {
        $date ??= Carbon::today(config('clinic.timezone', config('app.timezone')));
        $date = $date->copy()->startOfDay();

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_on && $date->lt($this->starts_on->copy()->startOfDay())) {
            return false;
        }

        if ($this->ends_on && $date->gt($this->ends_on->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    public function scopeCurrentlyActive($query, ?Carbon $date = null)
    {
        $date ??= Carbon::today(config('clinic.timezone', config('app.timezone')));
        $dateString = $date->toDateString();

        return $query
            ->where('is_active', true)
            ->where(function ($q) use ($dateString) {
                $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $dateString);
            })
            ->where(function ($q) use ($dateString) {
                $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dateString);
            });
    }
}
