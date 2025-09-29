<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeBase extends Model
{
    
    protected $table = 'knowledge_bases';
    protected $fillable = ['source','topic','title','skill_level','related_keywords','content'];
    protected $casts = [
        'related_keywords' => 'array',
    ];
}
