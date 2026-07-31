<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientNote extends Model
{
    protected $fillable = [
        'client_id',
        'appointment_id',
        'author_id',
        'staff_id',
        'author_role',
        'type',
        'note',
        'pinned',
    ];

    protected $casts = [
        'pinned' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}