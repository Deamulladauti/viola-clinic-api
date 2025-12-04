<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'short_description',      // ✅ add this
        'description',
        'duration_minutes',
        'price',
        'is_active',
        'image_path',

        // Phase 1 fields
        'is_bookable',
        'name_i18n',
        'short_description_i18n',
        'description_i18n',
        'prep_instructions',

        'is_package',
        'total_sessions',
        'total_minutes',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'is_bookable'            => 'boolean',
        'duration_minutes'       => 'integer',
        'price'                  => 'decimal:2',
        'name_i18n'              => 'array',
        'short_description_i18n' => 'array',
        'description_i18n'       => 'array',
        'prep_instructions'      => 'array',

        'is_package'    => 'boolean',
        'total_sessions'=> 'integer',
        'total_minutes' => 'integer',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    // Locale helpers + localized getters
    public function getLocale(): string
    {
        $locale = app()->getLocale();
        return in_array($locale, ['en','sq','mk'], true) ? $locale : 'en';
    }

    protected function pickI18n(?array $bag, ?string $fallback = null): ?string
    {
        if (!$bag) return $fallback;
        $loc = $this->getLocale();
        return $bag[$loc] ?? $bag['en'] ?? $fallback;
    }

    public function getNameLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->name_i18n, $this->name);
    }

    public function getShortDescriptionLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->short_description_i18n, $this->short_description ?? null);
    }

    public function getDescriptionLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->description_i18n, $this->description);
    }

    public function getPrepInstructionsLocalizedAttribute(): ?string
    {
        return $this->pickI18n($this->prep_instructions);
    }

    // Relationships
    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function staff()
    {
        return $this->belongsToMany(\App\Models\Staff::class, 'service_staff')->withTimestamps();
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    // Scopes
    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeBookable($query) { return $query->where('is_bookable', true); }

    // Slug auto-fill
    protected static function booted()
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $base = $service->name ?: Str::random(6);
                $service->slug = Str::slug($base) . '-' . Str::lower(Str::random(6));
            }
        });
    }

    public function packages()
    {
        return $this->hasMany(\App\Models\ServicePackage::class);
    }

    public function isBundleSku(): bool
{
    return (bool) ($this->is_package && ($this->total_sessions ?? 0) > 1);
}

}