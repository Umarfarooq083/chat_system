<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewChat implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;

    public function __construct(Chat $chat)
    {
        $this->chat = $chat->load([
            'latestMessage' => function ($query) {
                $query->select(
                    'messages.id',
                    'messages.chat_id',
                    'messages.sender_type',
                    'messages.message',
                    'messages.message_type',
                    'messages.created_at'
                );
            },
        ]);
    }

    public function broadcastOn()
    {
        return new Channel('newChats');
    }
}
