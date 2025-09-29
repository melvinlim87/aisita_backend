<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\EmailCampaign; // For the emailCampaigns relationship

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subject',
        'html_content',
        'category',
    ];

    /**
     * Get the tags for the email template.
     */
    public function tags()
    {
        return $this->hasMany(EmailTemplateTag::class);
    }

    /**
     * Get the email campaigns that use this template.
     */
    public function emailCampaigns()
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
