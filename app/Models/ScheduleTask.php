<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleTask extends Model
{
    protected $table = "schedule_tasks";
    protected $guarded = [];
    protected $casts = [
        'parameter' => 'array',
    ];

    /**
     * Get the user that owns the schedule tasks.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
