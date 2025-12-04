<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = ['name','email','phone','is_active','user_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function services()
    {
        return $this->belongsToMany(Service::class, 'service_staff')->withTimestamps();
    }

    public function schedules()  { return $this->hasMany(StaffSchedule::class); }
    public function timeOff()    { return $this->hasMany(StaffTimeOff::class); }
    public function appointments(){ return $this->hasMany(Appointment::class); }
    public function user()       { return $this->belongsTo(User::class); }
}
