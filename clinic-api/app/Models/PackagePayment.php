<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackagePayment extends Model
{
    protected $fillable = [
        'service_package_id','appointment_id','user_id','staff_id','admin_id',
        'method','amount','currency','notes','voided_at'
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    public function package(): BelongsTo    { return $this->belongsTo(ServicePackage::class, 'service_package_id'); }
    public function appointment(): BelongsTo{ return $this->belongsTo(Appointment::class); }

    // ✅ optional helpers
    public function user(): BelongsTo  { return $this->belongsTo(User::class, 'user_id'); }
    public function staff(): BelongsTo { return $this->belongsTo(User::class, 'staff_id'); }
    public function admin(): BelongsTo { return $this->belongsTo(User::class, 'admin_id'); }

    public function scopeNotVoided($q) { return $q->whereNull('voided_at'); }
}
