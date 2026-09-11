<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingGroup extends Model
{
    protected $fillable = [
        'user_id',
        'created_by_user_id',
    ];

    /**
     * Client whose treatments belong to this clinic visit.
     *
     * The link is nullable so historical/legacy appointment records remain
     * compatible if their user record is later removed.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Each treatment stays an independent Appointment. The group only links
     * those records so the UI can present them as one clinic visit.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'booking_group_id')
            ->orderBy('date')
            ->orderBy('starts_at')
            ->orderBy('id');
    }
}
