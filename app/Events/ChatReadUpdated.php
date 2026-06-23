<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $chatId;
    public string $readerType;
    public ?string $readAt;
    public ?string $agentLastReadAt;
    public ?string $visitorLastReadAt;
    public ?int $assignedAgentId;
    public ?string $status;

    public function __construct(Chat $chat, string $readerType)
    {
        $this->chatId = $chat->id;
        $this->readerType = $readerType;
        $this->agentLastReadAt = $chat->agent_last_read_at?->toIso8601String();
        $this->visitorLastReadAt = $chat->visitor_last_read_at?->toIso8601String();
        $this->readAt = $readerType === 'agent' ? $this->agentLastReadAt : $this->visitorLastReadAt;
        $this->assignedAgentId = $chat->assigned_agent_id;
        $this->status = $chat->status;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('chat.' . $this->chatId);
    }
}
