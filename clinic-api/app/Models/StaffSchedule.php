<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffSchedule extends Model
{
    protected $fillable = ['staff_id','weekday','start_time','end_time','is_active'];

    protected $casts = [
        'weekday'   => 'integer',
        'is_active' => 'boolean',
        // keep start_time/end_time as strings (TIME columns)
    ];

    public function staff() { return $this->belongsTo(Staff::class); }
}
