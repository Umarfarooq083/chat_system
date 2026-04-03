<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\NewChat;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ChatController extends Controller
{
    private function resolveCurrentUrl(Request $request): ?string
    {
        $url = $request->input('current_url') ?: $request->header('referer');
        if (!$url) {
            return null;
        }
        if (is_string($url) && strlen($url) > 2048) {
            return substr($url, 0, 2048);
        }
        return is_string($url) ? $url : null;
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required_without:attachments|nullable|string',
            'sender_type' => 'required|string',
            'chat_id' => 'required|exists:chats,id',
            'message_type' => 'nullable|string',
            'attachments' => 'nullable|file|max:20480',
        ]);

        try {
            $chat = Chat::find($request->chat_id);

            if ($request->sender_type === 'visitor') {
                $chat->last_activity = now();
                broadcast(new \App\Events\ChatPing($chat));
            }

            $chat_message = '';
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

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => $request->sender_type,
                'message' => ($request->message !== null && $request->message !== '') ? $request->message : $chat_message,
                'message_type' => $request->message_type,
                'attachments' => $filePath,
            ]);

            // keep chat list ordering consistent
            $chat->last_message_at = $message->created_at;
            if (!$chat->ip) {
                $chat->ip = $request->ip();
            }
            $currentUrl = $this->resolveCurrentUrl($request);
            if ($currentUrl) {
                $chat->current_url = $currentUrl;
            }
            // if the agent is sending, assume they have the chat open/read
            if ($request->sender_type === 'agent') {
                $chat->agent_last_read_at = now();
            }
            $chat->save();

            broadcast(new MessageSent($message));

            return response()->noContent();
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Failed to send message. Please try again.',
                ], 500);
            }

            return back()->withErrors([
                'message' => 'Failed to send message. Please try again.',
            ]);
        }
    }

    private function assertCanAccessAttachment(Request $request, Message $message): void
    {
        if ($request->hasValidSignature()) {
            return;
        }

        $token = config('chat.api_token');
        $providedToken = $request->header('X-CHAT-TOKEN') ?: $request->query('token');
        if ($token && is_string($providedToken) && hash_equals($token, $providedToken)) {
            return;
        }

        if (auth()->check()) {
            return;
        }

        $visitorId = session('visitor_id');
        if (!$visitorId) {
            abort(403);
        }

        $message->loadMissing('chat');
        if (!$message->chat || $message->chat->visitor_id !== $visitorId) {
            abort(403);
        }
    }

    public function viewAttachment(Request $request, Message $message)
    {
        $this->assertCanAccessAttachment($request, $message);

        if (!$message->attachments) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($message->attachments)) {
            abort(404);
        }

        return response()->file($disk->path($message->attachments));
    }

    public function downloadAttachment(Request $request, Message $message)
    {
        $this->assertCanAccessAttachment($request, $message);

        if (!$message->attachments) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($message->attachments)) {
            abort(404);
        }

        return $disk->download($message->attachments, basename($message->attachments));
    }

    public function getOrCreateChat(Request $request)
    {
        $request->validate([
            'current_url' => 'nullable|string|max:2048',
        ]);
        $visitorId = session()->get('visitor_id', Str::uuid());
        session()->put('visitor_id', $visitorId);

        $chat = Chat::firstOrCreate(
            ['visitor_id' => $visitorId],
            [
                'status' => 'open',
                'last_message_at' => now(),
                'agent_last_read_at' => now(),
            ]
        );

        if (!$chat->ip) {
            $chat->ip = $request->ip();
        }
        $currentUrl = $this->resolveCurrentUrl($request);
        if ($currentUrl) {
            $chat->current_url = $currentUrl;
        }
        if ($chat->isDirty()) {
            $chat->save();
        }

        $messages = $chat->messages()->latest()->take(5)->get()->reverse()->values();
        broadcast(new NewChat($chat));
        return response()->json([
            'chat' => $chat,
            'messages' => $messages
        ]);
    }

    public function visitorChat()
    {
        $visitorId = session('visitor_id') ?? \Str::uuid();
        session(['visitor_id' => $visitorId]);
        $chat = \App\Models\Chat::firstOrCreate(
            ['visitor_id' => $visitorId],
            [
                'status' => 'open',
                'last_message_at' => now(),
                'agent_last_read_at' => now(),
            ]
        );
        // mark visitor active
        $chat->last_activity = now();
        if (!$chat->ip) {
            $chat->ip = request()->ip();
        }
        $currentUrl = $this->resolveCurrentUrl(request());
        if ($currentUrl) {
            $chat->current_url = $currentUrl;
        }
        $chat->save();

        $messages = $chat->messages()->get();
        return Inertia::render('VisitorChat', [
            'chat' => $chat,
            'messages' => $messages,
        ]);
    }

    public function externalCreateChat(Request $request)
    {
        // identical to getOrCreateChat but returns JSON
        $request->validate([
            'current_url' => 'nullable|string|max:2048',
        ]);
        $visitorId = $request->input('visitor_id', session()->get('visitor_id', Str::uuid()));
        $chat = Chat::firstOrCreate(
            ['visitor_id' => $visitorId],
            [
                'last_message_at' => now(),
                'agent_last_read_at' => now(),
            ]
        );
        if (!$chat->ip) {
            $chat->ip = $request->ip();
        }
        $currentUrl = $this->resolveCurrentUrl($request);
        if ($currentUrl) {
            $chat->current_url = $currentUrl;
        }
        if ($chat->isDirty()) {
            $chat->save();
        }
        $messages = $chat->messages()->latest()->take(5)->get();
    
        broadcast(new NewChat($chat));
    
        return response()->json(['chat' => $chat, 'messages' => $messages]);
    }
    
    public function externalSendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required_without:attachments|nullable|string',
            'sender_type' => 'required|string',
            'chat_id' => 'required|exists:chats,id',
            'message_type' => 'nullable|string',
            'current_url' => 'nullable|string|max:2048',
            'attachments' => 'nullable|file|max:20480',
        ]);

        try {
            $chat = Chat::find($request->chat_id);
            if ($request->sender_type === 'visitor') {
                $chat->last_activity = now();
                broadcast(new \App\Events\ChatPing($chat));
            }

            $chat_message = '';
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

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => $request->sender_type,
                'message' => ($request->message !== null && $request->message !== '') ? $request->message : $chat_message,
                'message_type' => $request->message_type,
                'attachments' => $filePath,
            ]);

            $chat->last_message_at = $message->created_at;
            if (!$chat->ip) {
                $chat->ip = $request->ip();
            }
            $currentUrl = $this->resolveCurrentUrl($request);
            if ($currentUrl) {
                $chat->current_url = $currentUrl;
            }
            if ($request->sender_type === 'agent') {
                $chat->agent_last_read_at = now();
            }
            $chat->save();

            broadcast(new MessageSent($message));
            return response()->noContent();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to send message. Please try again.',
            ], 500);
        }
    }

    // Update last activity from visitor ping
    public function ping(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,id',
            'current_url' => 'nullable|string|max:2048',
        ]);
        $chat = Chat::find($request->chat_id);
        $chat->last_activity = now();
        if (!$chat->ip) {
            $chat->ip = $request->ip();
        }
        $currentUrl = $this->resolveCurrentUrl($request);
        if ($currentUrl) {
            $chat->current_url = $currentUrl;
        }
        $chat->save();

        // broadcast ping so agents can mark online
        broadcast(new \App\Events\ChatPing($chat));

        return response()->json(['status' => 'ok']);
    }

    public function sendUserInfo(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,id',
            'name' => 'required|string',
            'email' => 'required|email',
            'details' => 'required|string',
            'sender_type' => 'required|string',
            'message_type' => 'required|string'
        ]);

        $chat = Chat::find($request->chat_id);
        
        // Create a formatted message with user info
        $userInfoMessage = "User Information Request:\n" .
                          "Name: {$request->name}\n" .
                          "Email: {$request->email}\n" .
                          "Details: {$request->details}";

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => $request->sender_type,
            'message' => $userInfoMessage,
            'message_type' => $request->message_type // Store the special type
        ]);

        $chat->last_message_at = $message->created_at;
        if ($request->sender_type === 'agent') {
            $chat->agent_last_read_at = now();
        }
        $chat->save();

        broadcast(new MessageSent($message));

        return response()->json(['message' => $message]);
    }
}
