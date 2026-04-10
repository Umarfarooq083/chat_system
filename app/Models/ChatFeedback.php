<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatFeedback extends Model
{
    protected $table = 'chat_feedbacks';
    protected $fillable = [
        'chat_id',
        'chat_type',
        'description',
        'inquiry_type',
        'inquiry_id',
        'inquiry_name',
        'registration',
        'status',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}
