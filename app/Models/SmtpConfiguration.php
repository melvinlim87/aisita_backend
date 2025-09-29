<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

class SmtpConfiguration extends Model
{
    use HasFactory;

    protected $table = 'smtp_configurations';

    protected $fillable = [
        'name',
        'driver',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
        'provider_details',
        'is_default',
    ];

    protected $casts = [
        'provider_details' => 'array', // Casts JSON to array and vice-versa
        'is_default' => 'boolean',
        'port' => 'integer',
    ];

    /**
     * Encrypt the password attribute before saving.
     *
     * @param  string|null  $value
     * @return void
     */
    public function setPasswordAttribute($value)
    {
        if ($value) {
            $this->attributes['password'] = encrypt($value);
        } else {
            $this->attributes['password'] = null;
        }
    }

    /**
     * Get the decrypted password attribute.
     * Note: Use with caution. Typically, decrypt just before use, not broadly.
     *
     * @param  string|null  $value
     * @return string|null
     */
    public function getPasswordAttribute($value)
    {
        if ($value) {
            try {
                return decrypt($value);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Log error or handle appropriately if decryption fails
                // For now, return null or the encrypted value to avoid breaking things
                return null; 
            }
        }
        return null;
    }
    //
}
