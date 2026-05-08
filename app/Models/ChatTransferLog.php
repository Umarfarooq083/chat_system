<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatTransferLog extends Model
{
    protected $table = "transfer_logs";
    protected $fillable = [
        'chat_id',
        'assign_from_agent_id',
        'assign_to_agent_id',
        'assign_by_user_id',
    ];
}
