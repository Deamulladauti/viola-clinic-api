<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoShowPolicySetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'misses_before_penalty',
        'sessions_to_deduct',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'misses_before_penalty' => 'integer',
        'sessions_to_deduct' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'is_enabled' => false,
                'misses_before_penalty' => 2,
                'sessions_to_deduct' => 1,
            ],
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
