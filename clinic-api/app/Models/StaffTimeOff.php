<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffTimeOff extends Model
{
    protected $table = 'staff_time_off';

    protected $fillable = ['staff_id','date','start_time','end_time','reason'];

    protected $casts = [
        'date' => 'date',
        // keep start_time/end_time as strings (TIME columns)
    ];

    public function staff() { return $this->belongsTo(Staff::class); }
}
