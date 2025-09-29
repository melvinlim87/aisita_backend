<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplateTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_template_id',
        'tag',
    ];

    /**
     * Get the template that this tag belongs to.
     */
    public function template()
    {
        return $this->belongsTo(EmailTemplate::class);
    }
}
