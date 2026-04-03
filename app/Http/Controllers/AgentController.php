<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\Chat;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function index()
    {
        $chats = Chat::query()
            ->with('agent')
            ->with([
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
            ])
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
            'chats' => $chats,
            'auth_user' => auth()->user(),
            // used by the agent UI to poll for updates without needing a full reload
            'pollCursor' => now()->toIso8601String(),
        ]);
    }

    public function poll(Request $request)
    {
        $validated = $request->validate([
            'cursor' => 'nullable|string',
        ]);

        $since = null;
        if (!empty($validated['cursor'])) {
            try {
                $since = Carbon::parse($validated['cursor']);
            } catch (\Throwable $e) {
                $since = null;
            }
        }

        // if the client doesn't provide a cursor, default to a short lookback window
        $since ??= now()->subMinutes(2);

        $chats = Chat::query()
            ->where('updated_at', '>', $since)
            ->with([
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
            ])
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

        $chats->each->append('is_online');

        return response()->json([
            'cursor' => now()->toIso8601String(),
            'chats' => $chats,
        ]);
    }

    public function messages(Request $request, Chat $chat)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $validated['limit'] ?? 10;

        // fetch latest N, then reverse for chronological display
        $messages = $chat
            ->messages()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $chat->load([
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
        $chat->append('is_online');

        return response()->json([
            'chat' => $chat,
            'messages' => $messages,
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
        if($chat->assigned_agent_id){
            if($chat->assigned_agent_id !== auth()->id()){
                  return response()->noContent();
            }else{
                $chat->agent_last_read_at = now();
                $chat->save();
            }
        }else{
            $chat->assigned_agent_id = auth()->id();
            $chat->agent_last_read_at = now();
            $chat->save();
        }
        return response()->noContent();
    }

    public function destroy(Chat $chat)
    {
        $chat->messages()->delete();
        $chat->delete();
        // return response()->json(['message' => 'Chat and all its messages deleted successfully.']);
    }

    public function close(Chat $chat)
    {
        if ($chat->assigned_agent_id && $chat->assigned_agent_id !== auth()->id()) {
            return response()->noContent();
        }

        if (!$chat->assigned_agent_id) {
            $chat->assigned_agent_id = auth()->id();
        }

        $chat->status = 'close';
        $chat->save();

        $chat->load([
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
        $chat->append('is_online');

        return response()->json([
            'chat' => $chat,
        ]);
    }
}
