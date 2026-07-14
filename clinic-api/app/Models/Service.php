<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;

    public const USAGE_SINGLE = 'single';
    public const USAGE_SESSION = 'session';
    public const USAGE_MINUTES = 'minutes';

    public const DEDUCTION_AUTOMATIC = 'automatic_on_completion';
    public const DEDUCTION_MANUAL = 'manual';

    public const STAFF_PER_APPOINTMENT = 'per_appointment';
    public const STAFF_SAME = 'same_staff';
    public const STAFF_ANY_QUALIFIED = 'any_qualified_staff';

    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'short_description',
        'description',
        'duration_minutes',
        'price',
        'is_active',
        'image_path',
        'is_bookable',
        'name_i18n',
        'short_description_i18n',
        'description_i18n',
        'prep_instructions',
        'is_package',
        'total_sessions',
        'total_minutes',
        'usage_type',
        'minimum_interval_days',
        'deduction_method',
        'staff_policy',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_bookable' => 'boolean',
        'duration_minutes' => 'integer',
        'price' => 'decimal:2',
        'name_i18n' => 'array',
        'short_description_i18n' => 'array',
        'description_i18n' => 'array',
        'prep_instructions' => 'array',
        'is_package' => 'boolean',
        'total_sessions' => 'integer',
        'total_minutes' => 'integer',
        'minimum_interval_days' => 'integer',
    ];

    protected $appends = ['image_url', 'requires_appointment'];

    public static function usageTypes(): array
    {
        return [self::USAGE_SINGLE, self::USAGE_SESSION, self::USAGE_MINUTES];
    }

    public static function deductionMethods(): array
    {
        return [self::DEDUCTION_AUTOMATIC, self::DEDUCTION_MANUAL];
    }

    public static function staffPolicies(): array
    {
        return [self::STAFF_PER_APPOINTMENT, self::STAFF_SAME, self::STAFF_ANY_QUALIFIED];
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }

    public function getRequiresAppointmentAttribute(): bool
    {
        return (bool) $this->is_bookable;
    }

    public function getLocale(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, ['en', 'sq', 'mk'], true) ? $locale : 'en';
    }

    protected function pickI18n(?array $bag, ?string $fallback = null): ?string
    {
        if (! $bag) {
            return $fallback;
        }

        $locale = $this->getLocale();

        return $bag[$locale] ?? $bag['en'] ?? $fallback;
    }

    public function getNameLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->name_i18n, $this->name);
    }

    public function getShortDescriptionLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->short_description_i18n, $this->short_description);
    }

    public function getDescriptionLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->description_i18n, $this->description);
    }

    public function getPrepInstructionsLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->prep_instructions);
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function staff()
    {
        return $this->belongsToMany(Staff::class, 'service_staff')->withTimestamps();
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function packages()
    {
        return $this->hasMany(ServicePackage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBookable($query)
    {
        return $query->where('is_bookable', true);
    }

    public function isSingleAppointment(): bool
    {
        return $this->usage_type === self::USAGE_SINGLE;
    }

    public function isSessionPackage(): bool
    {
        return $this->usage_type === self::USAGE_SESSION;
    }

    public function isQuantityPackage(): bool
    {
        return $this->usage_type === self::USAGE_MINUTES;
    }

    public function isBundleSku(): bool
    {
        return $this->isSessionPackage() && (int) $this->total_sessions > 1;
    }

    protected static function booted()
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $base = $service->name ?: Str::random(6);
                $service->slug = Str::slug($base).'-'.Str::lower(Str::random(6));
            }
        });
    }
}
