<?php

namespace App\Http\Controllers;

use App\Events\ChatReadUpdated;
use App\Events\MessageSent;
use App\Events\NewChat;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ChatWidgetController extends Controller
{
    private const VISITOR_ID_PATTERN = '/^[a-zA-Z0-9._-]{8,100}$/';

    private function createWelcomeMessageIfNeeded(Chat $chat): void
    {
        if ($chat->messages()->exists()) {
            return;
        }

        $text = trim((string) config('chat.widget_welcome_message'));
        if ($text === '') {
            return;
        }

        $welcomeMessage = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'agent',
            'message' => $text,
            'message_type' => 'welcome',
        ]);

        $chat->last_message_at = $welcomeMessage->created_at;
        $chat->save();

        broadcast(new MessageSent($welcomeMessage));
    }

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

        $companyId = $request->query('companyId');

        return view('chat-widget', [
            'title' => $title,
            'brandColor' => $brandColor,
            'visitorId' => is_string($request->query('vid')) ? $request->query('vid') : null,
            'companyId' => $companyId,
        ]);
    }

    public function createChat(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'company_id' => 'required|string|max:36',
            'current_url' => 'nullable|string|max:2048',
            'referrer_url' => 'nullable|string|max:2048',
        ]);

        $visitorId = trim($validated['visitor_id']);
        if ($visitorId === '' || preg_match(self::VISITOR_ID_PATTERN, $visitorId) !== 1) {
            return response()->json(['message' => 'Invalid visitor_id'], 422);
        }

        $companyId = $validated['company_id'];

        $chat = Chat::firstOrCreate(
            ['visitor_id' => $visitorId, 'company_id' => $companyId ?: null],
            [
                'status' => 'open',
                'last_message_at' => now(),
                'agent_last_read_at' => now(),
                'visitor_last_read_at' => now(),
                'company_id' => $companyId ?: null,
            ]
        );
        $this->createWelcomeMessageIfNeeded($chat);

        $currentUrl = $validated['current_url'] ?? null;
        $chat->last_activity = now();
        $chat->visitor_last_read_at = now();
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
                'agent_last_read_at' => optional($chat->agent_last_read_at)->toIso8601String(),
                'visitor_last_read_at' => optional($chat->visitor_last_read_at)->toIso8601String(),
            ],
            'messages' => $messages->map(fn (Message $m) => $this->serializeMessage($m))->values(),
        ]);
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'chat_id' => 'required|integer|exists:chats,id',
            'company_id' => 'nullable|string|max:36',
            'message' => 'required_without:attachments|nullable|string|max:4000',
            'message_type' => 'nullable|string',
            'attachments' => 'nullable|file|max:20480',
            'phone' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'registration_no' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:255',
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

        $companyId = trim($validated['company_id'] ?? '');
        if ($companyId !== '' && (string) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Apply user info if it's a user_info_response
        if ($request->input('message_type') === 'user_info_response' && $request->input('sender_type') === 'visitor') {
            $phone = $request->input('phone');
            $customerName = $request->input('customer_name');
            $registrationNo = $request->input('registration_no');
            $email = $request->input('email');

            $phone = is_string($phone) ? trim($phone) : null;
            $customerName = is_string($customerName) ? trim($customerName) : null;
            $registrationNo = is_string($registrationNo) ? trim($registrationNo) : null;
            $email = is_string($email) ? trim($email) : null;
            if ($email === '') $email = null;

            if ($phone && $customerName && $registrationNo) {
                $chat->phone = $phone;
                $chat->customer_name = $customerName;
                $chat->registration_no = $registrationNo;
                $chat->email = $email;
                $chat->user_info_submitted_at = now();
            }
        }

        $messageText = trim($validated['message'] ?? '');
        if ($messageText === '' && !$request->hasFile('attachments')) {
            return response()->json(['message' => 'Message or attachment is required'], 422);
        }

        $filePath = null;
        if ($request->hasFile('attachments')) {
            $uploaded = $request->file('attachments');
            if (is_array($uploaded)) {
                $uploaded = $uploaded[0] ?? null;
            }

            if ($uploaded) {
                $ext = $uploaded->guessExtension() ?: $uploaded->getClientOriginalExtension() ?: 'bin';
                $ext = strtolower(preg_replace('/[^a-z0-9]+/i', '', $ext)) ?: 'bin';

                $fileName = (string) Str::uuid() . '.' . $ext;
                $dir = 'chat-attachments/' . $chat->id;
                $filePath = $uploaded->storeAs($dir, $fileName, 'public');
            }
        }

        $chat_message = [];

        if ($request->message_type == 'user_info_response') {
            $chat_message = [
                'type' => 'user_info_response',
                'name' => $request->customer_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'registration_no' => $request->registration_no,
            ];
        } else {
            $chat_message = $messageText;
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'visitor',
            'message' => is_array($chat_message) ? json_encode($chat_message) : $chat_message,
            'message_type' => $validated['message_type'] ?? null,
            'attachments' => $filePath,
        ]);

        $chat->last_message_at = $message->created_at;
        $chat->last_activity = now();
        $chat->visitor_last_read_at = now();
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

    public function markRead(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'chat_id' => 'required|integer|exists:chats,id',
        ]);

        if (preg_match(self::VISITOR_ID_PATTERN, (string) $validated['visitor_id']) !== 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = Chat::findOrFail($validated['chat_id']);
        if ((string) $chat->visitor_id !== (string) $validated['visitor_id']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat->visitor_last_read_at = now();
        $chat->save();

        broadcast(new ChatReadUpdated($chat, 'visitor'));

        return response()->noContent();
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

    public function ping(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'required|string|max:100',
            'chat_id' => 'required|integer|exists:chats,id',
            'current_url' => 'nullable|string|max:2048',
        ]);

        if (preg_match(self::VISITOR_ID_PATTERN, (string) $validated['visitor_id']) !== 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = Chat::findOrFail($validated['chat_id']);
        if ((string) $chat->visitor_id !== (string) $validated['visitor_id']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $updates = [
            'last_activity' => now(),
            'ip' => DB::raw("COALESCE(ip, '{$request->ip()}')"),
        ];

        if (!empty($validated['current_url'])) {
            $updates['current_url'] = $validated['current_url'];
        }

        Chat::where('id', $validated['chat_id'])->update($updates);
        $chat = Chat::find($validated['chat_id']);
        
        try {
            broadcast(new \App\Events\ChatPing($chat));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['status' => 'ok']);
    }
}
