<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

class Message extends Model
{
    use SoftDeletes;

    protected $appends = [
        'attachment_view_url',
        'attachment_download_url',
        'attachment_name',
        'attachment_is_image',
    ];

    protected $fillable = [
        'chat_id',
        'sender_type',
        'sender_id',
        'message',
        'message_type',
        'attachments',
    ];

    protected $casts = [
        'message' => 'array',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function getAttachmentViewUrlAttribute(): ?string
    {
        if (!$this->attachments) {
            return null;
        }

        return URL::temporarySignedRoute(
            'attachments.view',
            now()->addDays(7),
            ['message' => $this->id]
        );
    }

    public function getAttachmentDownloadUrlAttribute(): ?string
    {
        if (!$this->attachments) {
            return null;
        }

        return URL::temporarySignedRoute(
            'attachments.download',
            now()->addDays(7),
            ['message' => $this->id]
        );
    }

    public function getAttachmentNameAttribute(): ?string
    {
        if (!$this->attachments) {
            return null;
        }

        return basename($this->attachments);
    }

    public function getAttachmentIsImageAttribute(): bool
    {
        if (!$this->attachments) {
            return false;
        }

        $ext = strtolower(pathinfo($this->attachments, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'], true);
    }
}
