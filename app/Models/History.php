<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    use HasFactory;

    protected $table = 'histories';

    protected $fillable = [
        'user_id',
        'title',
        'type',
        'model',
        'content',
        'chart_urls',
        'timestamp',
    ];

    protected $casts = [
        'chart_urls' => 'array',
        'timestamp' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }
}
