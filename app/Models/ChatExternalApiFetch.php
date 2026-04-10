<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatExternalApiFetch extends Model
{
    protected $fillable = [
        'chat_id',
        'registration_no',
        'status',
        'error',
        'response',
        'fetched_at',
        'pdf_path',
        'pdf_sent_at',
    ];

    protected $casts = [
        'response' => 'array',
        'fetched_at' => 'datetime',
        'pdf_sent_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}

