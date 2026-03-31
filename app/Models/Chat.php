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
        'current_url',
        'country',
        'status',
        'last_activity',
        'last_message_at',
        'agent_last_read_at',
    ];

    protected $casts = [
        'last_activity' => 'datetime',
        'last_message_at' => 'datetime',
        'agent_last_read_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
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
}
