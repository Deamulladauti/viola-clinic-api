<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored value, not a discount. All redemption/balance mutations belong in a
 * transactional payment service (Tasks 38–39), not in model hooks.
 */
class GiftCard extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_DISABLED = 'disabled';

    protected $attributes = [
    'status' => self::STATUS_ACTIVE,
    'currency' => 'EUR',
    ];
    
    protected $fillable = [
        'initial_value', 'remaining_value', 'currency', 'status',
        'issued_at', 'purchased_at', 'recipient_user_id',
        'recipient_name', 'recipient_email', 'recipient_phone',
        'notes', 'created_by_id',
    ];

    protected $casts = [
        'initial_value' => 'decimal:2',
        'remaining_value' => 'decimal:2',
        'issued_at' => 'datetime',
        'purchased_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $card): void {
            if (! $card->code) {
                $card->code = 'VC-' . strtoupper(bin2hex(random_bytes(8)));
            }
            if (! $card->issued_at) {
                $card->issued_at = now();
            }
        });
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
