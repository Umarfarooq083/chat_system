<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\NewChat;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;

class ChatWidgetController extends Controller
{
    private const VISITOR_ID_PATTERN = '/^[a-zA-Z0-9._-]{8,100}$/';

    public function page(Request $request)
    {
        $title = $request->query('title');
        $title = is_string($title) ? trim($title) : null;
        if ($title === '' || $title === null || mb_strlen($title) > 50) {
            $title = (string) (config('chat.widget_title') ?? 'Chat');
        }

        $brandColor = $request->query('color');
        $brandColor = is_string($brandColor) ? trim($brandColor) : null;
        if (!$brandColor || !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $brandColor)) {
            $brandColor = (string) (config('chat.widget_brand_color') ?? '#111827');
        }

        return view('chat-widget', [
            'title' => $title,
            'brandColor' => $brandColor,
            'visitorId' => is_string($request->query('vid')) ? $request->query('vid') : null,
        ]);
    }

    public function createChat(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'current_url' => 'nullable|string|max:2048',
            'referrer_url' => 'nullable|string|max:2048',
        ]);

        $visitorId = trim($validated['visitor_id']);
        if ($visitorId === '' || preg_match(self::VISITOR_ID_PATTERN, $visitorId) !== 1) {
            return response()->json(['message' => 'Invalid visitor_id'], 422);
        }

        $chat = Chat::firstOrCreate(
            ['visitor_id' => $visitorId],
            [
                'status' => 'open',
                'last_message_at' => now(),
                'agent_last_read_at' => now(),
            ]
        );

        $currentUrl = $validated['current_url'] ?? null;
        $chat->last_activity = now();
        if (!$chat->ip) {
            $chat->ip = $request->ip();
        }
        if ($currentUrl) {
            $chat->current_url = $currentUrl;
        } elseif (!empty($validated['referrer_url'])) {
            $chat->current_url = $validated['referrer_url'];
        }
        $chat->save();

        $messages = $chat->messages()->orderByDesc('id')->limit(20)->get()->reverse()->values();

        try {
            broadcast(new NewChat($chat));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'chat' => [
                'id' => $chat->id,
                'visitor_id' => $chat->visitor_id,
            ],
            'messages' => $messages->map(fn (Message $m) => $this->serializeMessage($m))->values(),
        ]);
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'chat_id' => 'required|integer|exists:chats,id',
            'message' => 'required|string|max:4000',
            'current_url' => 'nullable|string|max:2048',
            'referrer_url' => 'nullable|string|max:2048',
        ]);

        if (preg_match(self::VISITOR_ID_PATTERN, (string) $validated['visitor_id']) !== 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = Chat::findOrFail($validated['chat_id']);
        if ((string) $chat->visitor_id !== (string) $validated['visitor_id']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $messageText = trim($validated['message']);
        if ($messageText === '') {
            return response()->json(['message' => 'Message is required'], 422);
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'visitor',
            'message' => $messageText,
            'message_type' => null,
            'attachments' => null,
        ]);

        $chat->last_message_at = $message->created_at;
        $chat->last_activity = now();
        if (!$chat->ip) {
            $chat->ip = $request->ip();
        }
        $currentUrl = $validated['current_url'] ?? null;
        if ($currentUrl) {
            $chat->current_url = $currentUrl;
        } elseif (!empty($validated['referrer_url'])) {
            $chat->current_url = $validated['referrer_url'];
        }
        $chat->save();

        broadcast(new MessageSent($message));

        return response()->json([
            'message' => $this->serializeMessage($message),
        ]);
    }

    public function messages(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'chat_id' => 'required|integer|exists:chats,id',
            'after_id' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if (preg_match(self::VISITOR_ID_PATTERN, (string) $validated['visitor_id']) !== 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = Chat::findOrFail($validated['chat_id']);
        if ((string) $chat->visitor_id !== (string) $validated['visitor_id']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $afterId = (int) ($validated['after_id'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 30);

        $messages = $chat
            ->messages()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'messages' => $messages->map(fn (Message $m) => $this->serializeMessage($m))->values(),
        ]);
    }

    private function serializeMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'sender_type' => $message->sender_type,
            'message_type' => $message->message_type,
            'message' => $message->message,
            'created_at' => optional($message->created_at)->toIso8601String(),
            'attachment_view_url' => $message->attachment_view_url,
            'attachment_download_url' => $message->attachment_download_url,
            'attachment_name' => $message->attachment_name,
            'attachment_is_image' => $message->attachment_is_image,
        ];
    }
}
