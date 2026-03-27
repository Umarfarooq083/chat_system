<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\NewChat;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
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
            'message' => 'required|string',
            'sender_type' => 'required|string',
            'chat_id' => 'required|exists:chats,id',
            'message_type' => 'nullable|string'
        ]);
        $chat = Chat::find($request->chat_id);

        // update last activity whenever visitor interacts
        if ($request->sender_type === 'visitor') {
            $chat->last_activity = now();
            broadcast(new \App\Events\ChatPing($chat));
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => $request->sender_type,
            'message' => $request->message,
            'message_type' => $request->message_type,
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

        // return response()->json(['success' => true, 'message' => $message]);
        return response()->noContent();
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
            'chat_id' => 'required|exists:chats,id',
            'message' => 'required|string',
            'sender_type' => 'required|string',
            'message_type' => 'nullable|string',
            'current_url' => 'nullable|string|max:2048',
        ]);
    
        $chat = Chat::find($request->chat_id);
        if ($request->sender_type === 'visitor') {
            $chat->last_activity = now();
            broadcast(new \App\Events\ChatPing($chat));
        }
        $message = Message::create([
            'chat_id'     => $chat->id,
            'sender_type' => $request->sender_type,
            'message'     => $request->message,
            'message_type' => $request->message_type,
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
