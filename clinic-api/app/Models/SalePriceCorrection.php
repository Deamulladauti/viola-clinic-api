<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePriceCorrection extends Model
{
    public const SUBJECT_APPOINTMENT = 'appointment';
    public const SUBJECT_PACKAGE = 'package';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'client_id',
        'corrected_by',
        'reason',
        'before_terms',
        'after_terms',
        'amount_paid_at_correction',
        'currency',
    ];

    protected $casts = [
        'before_terms' => 'array',
        'after_terms' => 'array',
        'amount_paid_at_correction' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
