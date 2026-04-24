<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chat extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'visitor_id',
        'assigned_agent_id',
        'ip_address',
        'ip',
        'website',
        'website_slug',
        'phone',
        'customer_name',
        'prechat_submitted_at',
        'registration_no',
        'email',
        'user_info_submitted_at',
        'external_api_status',
        'external_api_error',
        'external_api_response',
        'external_api_fetched_at',
        'external_api_pdf_sent_at',
        'current_url',
        'country',
        'status',
        'last_activity',
        'last_message_at',
        'agent_last_read_at',
        'visitor_last_read_at',
        'company_id',
    ];

    protected $casts = [
        'last_activity' => 'datetime',
        'last_message_at' => 'datetime',
        'agent_last_read_at' => 'datetime',
        'visitor_last_read_at' => 'datetime',
        'prechat_submitted_at' => 'datetime',
        'user_info_submitted_at' => 'datetime',
        'external_api_fetched_at' => 'datetime',
        'external_api_pdf_sent_at' => 'datetime',
        'external_api_response' => 'array',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function externalApiFetches()
    {
        return $this->hasMany(ChatExternalApiFetch::class);
    }

    public function feedbacks()
    {
        return $this->hasMany(ChatFeedback::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function agent()
    {
        return $this->hasOne(User::class, 'id', 'assigned_agent_id');
    }
    
    public function companyRel()
    {
        return $this->hasOne(Company::class, 'uuid', 'company_id');
    }

    // Determine if visitor is currently online (active in last 2 minutes)
    public function getIsOnlineAttribute()
    {
        if (!$this->last_activity) {
            return false;
        }

        // Consider visitor online if they pinged within the last minute.
        return $this->last_activity->gt(now()->subSeconds(60));
    }

    public function scopeByCompanyUuid($query, $uuid)
    {
        return $query->when($uuid, function ($q) use ($uuid) {
            $q->whereHas('companyRel', function ($q2) use ($uuid) {
                $q2->where('uuid', $uuid);
            });
        });
    }

}
