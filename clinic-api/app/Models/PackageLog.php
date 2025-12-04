<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PackageLog extends Model
{
    protected $fillable = [
        'service_package_id',
        'staff_id',
        'appointment_id',
        'appointment_ref',
        'used_sessions',
        'used_minutes',
        'used_at',
        'note',
    ];

    protected $casts = [
        'used_sessions' => 'integer',
        'used_minutes'  => 'integer',
        'used_at'       => 'datetime',
    ];

    public function package(): BelongsTo { return $this->belongsTo(ServicePackage::class, 'service_package_id'); }
    public function staff(): BelongsTo   { return $this->belongsTo(Staff::class, 'staff_id'); }

    // ✅ add missing inverse
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
}
