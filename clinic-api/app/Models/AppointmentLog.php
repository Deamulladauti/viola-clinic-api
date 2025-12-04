<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentLog extends Model
{
    protected $fillable = [
        'appointment_id',
        'action',
        'details',
        'user_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime', // ✅ optional
        'updated_at' => 'datetime',
    ];

    public function appointment() { return $this->belongsTo(Appointment::class); }
    public function user()        { return $this->belongsTo(User::class); }
}
