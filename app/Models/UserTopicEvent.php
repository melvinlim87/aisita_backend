<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTopicEvent extends Model
{
    protected $table = "user_topic_events";
    protected $fillable = ['user_id','topic','event_type','confidence','meta'];
    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * Get the user that owns the topic events.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
