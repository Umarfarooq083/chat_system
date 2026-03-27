<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Chat;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function index()
    {
        $chats = Chat::query()
            ->with('messages')
            ->withCount([
                'messages as unread_count' => function ($query) {
                    $query
                        ->where('sender_type', 'visitor')
                        ->where(function ($q) {
                            $q
                                ->whereNull('chats.agent_last_read_at')
                                ->orWhereColumn('messages.created_at', '>', 'chats.agent_last_read_at');
                        });
                },
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
        // append online indicator
        $chats->each->append('is_online');
        return Inertia::render('Agent/Chats', [
            'chats' => $chats
        ]);
    }

    public function show(Chat $chat)
    {
        return Inertia::render('Agent/ChatDetail', [
            'chat' => $chat->load('messages')
        ]);
    }

    public function markRead(Chat $chat)
    {
        $chat->agent_last_read_at = now();
        $chat->save();

        return response()->noContent();
    }

    public function destroy(Chat $chat)
    {
        $chat->messages()->delete();
        $chat->delete();
        // return response()->json(['message' => 'Chat and all its messages deleted successfully.']);
    }
}
