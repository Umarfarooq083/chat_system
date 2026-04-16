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

    public function broadcastWith(): array
    {
        $latest = $this->chat->latestMessage;

        return [
            'chat' => [
                'id' => $this->chat->id,
                'visitor_id' => $this->chat->visitor_id,
                'assigned_agent_id' => $this->chat->assigned_agent_id,
                'status' => $this->chat->status,
                'country' => $this->chat->country,
                'website' => $this->chat->website,
                'website_slug' => $this->chat->website_slug,
                'current_url' => $this->chat->current_url,
                'ip' => $this->chat->ip,
                'last_activity' => $this->chat->last_activity?->toIso8601String(),
                'last_message_at' => $this->chat->last_message_at?->toIso8601String(),
                'agent_last_read_at' => $this->chat->agent_last_read_at?->toIso8601String(),
                'visitor_last_read_at' => $this->chat->visitor_last_read_at?->toIso8601String(),
                'created_at' => $this->chat->created_at?->toIso8601String(),
                'updated_at' => $this->chat->updated_at?->toIso8601String(),
                'latest_message' => $latest ? [
                    'id' => $latest->id,
                    'chat_id' => $latest->chat_id,
                    'sender_type' => $latest->sender_type,
                    'message' => $latest->message,
                    'message_type' => $latest->message_type,
                    'created_at' => $latest->created_at?->toIso8601String(),
                ] : null,
            ],
        ];
    }
}
