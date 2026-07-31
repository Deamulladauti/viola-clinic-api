<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackagePayment extends Model
{
    protected $fillable = [
        'service_package_id',
        'appointment_id',
        'user_id',
        'staff_id',
        'admin_id',
        'method',
        'amount',
        'currency',
        'exchange_rate',
        'amount_mkd',
        'notes',
        'voided_at',
        'voided_by_id',
        'void_reason',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_mkd'    => 'decimal:2',
        'voided_at'     => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    // Client who paid
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Staff profile who recorded payment, if staff recorded it
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    // Admin/user account who recorded payment
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // User who voided/cancelled the payment
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    public function scopeNotVoided($query)
    {
        return $query->whereNull('voided_at');
    }

    public function scopeVoided($query)
    {
        return $query->whereNotNull('voided_at');
    }

    public function getIsVoidedAttribute(): bool
    {
        return $this->voided_at !== null;
    }
}