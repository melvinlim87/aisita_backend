<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\User;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailTemplate; // For the emailTemplate relationship

class EmailCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subject',
        'from_email',
        'from_name',
        'email_template_id',
        'html_content',
        'status',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'successful_sends',
        'failed_sends',
        'opens_count',
        'clicks_count',
        'user_id',
        'all_users',
        'user_ids',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'html_content' => 'string', // Ensure it's treated as string
        'total_recipients' => 'integer',
        'successful_sends' => 'integer',
        'failed_sends' => 'integer',
        'opens_count' => 'integer',
        'clicks_count' => 'integer',
        'all_users' => 'boolean',
        'user_ids' => 'array',
    ];

    /**
     * Get the user who created the email campaign.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the email template associated with the campaign (if any).
     */
    public function emailTemplate()
    {
        return $this->belongsTo(EmailTemplate::class);
    }
    
    /**
     * Get the contacts directly associated with this campaign.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'email_campaign_contacts');
    }
    
    /**
     * Get the contact lists associated with this campaign.
     */
    public function contactLists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'email_campaign_contact_lists');
    }
    
    /**
     * Get all recipient emails for this campaign.
     * This includes emails from selected users or all users if all_users is true.
     * 
     * @return array
     */
    public function getRecipientEmails(): array
    {
        $emails = [];
        
        if ($this->all_users) {
        // Get all users
        $emails = User::pluck('email')->toArray();
        } else {
            // Get emails from selected individual users
            if (!empty($this->user_ids)) {
                $userEmails = User::whereIn('id', $this->user_ids)
                    ->pluck('email')
                    ->toArray();
                    
                $emails = array_merge($emails, $userEmails);
            }
        }
        
        // Remove duplicates and return
        return array_unique($emails);
    }
    
    /**
     * Get all recipients with their data for this campaign.
     * This includes full user objects for personalization.
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecipients()
    {
        if ($this->all_users) {
            // Get all users
            return User::select('id', 'name', 'email')->get();
        } else {
            // Get selected individual users
            if (!empty($this->user_ids)) {
                return User::whereIn('id', $this->user_ids)
                    ->select('id', 'name', 'email')
                    ->get();
            }
        }
        
        return collect([]);
    }
}
