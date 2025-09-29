<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLearningProfile extends Model
{
    protected $table = 'user_learning_profile';
    protected $fillable = ['user_id','skill_level','topics_learned','progress','next_recommended'];
    protected $casts = [
        'topics_learned'   => 'array',
        'progress'         => 'array',
        'next_recommended' => 'array',
    ];
}
