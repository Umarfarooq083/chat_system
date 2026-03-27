<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatId;
    public $currentUrl;
    public $ip;

    public function __construct(Chat $chat)
    {
        $this->chatId = $chat->id;
        $this->currentUrl = $chat->current_url;
        $this->ip = $chat->ip;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('chat.' . $this->chatId);
    }
}
