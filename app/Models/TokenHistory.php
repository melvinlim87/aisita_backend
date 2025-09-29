<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenHistory extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'amount',
        'action',   // credited, debited
        'reason',   // description of the transaction
        'balance_after',  // token balance after this transaction
    ];
    
    /**
     * Get the user that owns the token history record.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
