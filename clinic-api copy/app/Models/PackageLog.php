<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PackageLog extends Model
{
    use SoftDeletes;

    public const SOURCE_AUTOMATIC = 'automatic';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORTED = 'imported';

    protected $fillable = [
        'service_package_id',
        'staff_id',
        'appointment_id',
        'active_appointment_id',
        'appointment_ref',
        'usage_type',
        'quantity',
        'session_number',
        'used_sessions',
        'used_minutes',
        'used_at',
        'occurred_on',
        'source',
        'created_by_id',
        'note',
        'voided_at',
        'voided_by_id',
        'void_reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'session_number' => 'integer',
        'used_sessions' => 'integer',
        'used_minutes' => 'integer',
        'used_at' => 'datetime',
        'occurred_on' => 'date',
        'voided_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    public function scopeSessions($query)
    {
        return $query->where('usage_type', Service::USAGE_SESSION);
    }

    public function scopeMinutes($query)
    {
        return $query->where('usage_type', Service::USAGE_MINUTES);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
