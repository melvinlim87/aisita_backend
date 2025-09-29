<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Verification extends Model
{
    protected $table = 'verifications';

    protected $fillable = [
        'uid',
        'email',
        'username',
        'verification_code',
        'app',
        'type',
        'verified_at',
        'expires_at'
    ];
}
