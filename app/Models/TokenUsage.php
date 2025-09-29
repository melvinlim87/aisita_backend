<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenUsage extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'token_usage';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'feature',
        'model',
        'analysis_type',
        'input_tokens',
        'output_tokens',
        'tokens_used',
        'total_tokens',
        'timestamp'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'timestamp' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'tokens_used' => 'integer',
        'total_tokens' => 'integer',
    ];

    /**
     * Get the user that owns the token usage record.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
