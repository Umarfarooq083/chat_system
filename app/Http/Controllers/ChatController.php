<?php

namespace App\Http\Controllers;

use App\Events\ChatReadUpdated;
use App\Events\MessageSent;
use App\Events\NewChat;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ChatController extends Controller
{
    private function createWelcomeMessageIfNeeded(Chat $chat, ?string $welcomeText = null): void
    {
        if ($chat->messages()->exists()) {
            return;
        }

        $text = trim((string) ($welcomeText ?? config('chat.welcome_message')));
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

    private function broadcastReadUpdate(Chat $chat, string $readerType): void
    {
        broadcast(new ChatReadUpdated($chat, $readerType));
    }

    private function canVisitorAccessChat(Chat $chat): bool
    {
        $visitorId = session('visitor_id');
        return is_string($visitorId) && $visitorId !== '' && $chat->visitor_id === $visitorId;
    }

    private function extractUserInfoFieldFromMessage(string $message, string $label): ?string
    {
        $pattern = '/^' . preg_quote($label, '/') . '\\s*:\\s*(.+)\\s*$/mi';
        if (preg_match($pattern, $message, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));
        return $value !== '' ? $value : null;
    }

    private function applyVisitorUserInfoToChat(Request $request, Chat $chat): void
    {
        if ($request->input('message_type') !== 'user_info_response' || $request->input('sender_type') !== 'visitor') {
            return;
        }

        $message = (string) ($request->input('message') ?? '');

        $existingRegistrationNo = is_string($chat->registration_no) ? trim($chat->registration_no) : null;

        $phone = $request->input('phone') ?: $this->extractUserInfoFieldFromMessage($message, 'Phone No');
        $customerName = $request->input('customer_name') ?: $this->extractUserInfoFieldFromMessage($message, 'Customer Name');
        $registrationNo = $request->input('registration_no') ?: $this->extractUserInfoFieldFromMessage($message, 'Registration No');
        $email = $request->input('email') ?: $this->extractUserInfoFieldFromMessage($message, 'Email');

        $phone = is_string($phone) ? trim($phone) : null;
        $customerName = is_string($customerName) ? trim($customerName) : null;
        $registrationNo = is_string($registrationNo) ? trim($registrationNo) : null;
        $email = is_string($email) ? trim($email) : null;
        if ($email === '') $email = null;

        if (!$phone || !$customerName || !$registrationNo) {
            return;
        }

        if (strlen($phone) > 50 || strlen($customerName) > 255 || strlen($registrationNo) > 100) {
            return;
        }

        if ($email) {
            if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $email = null;
            }
        }

        $chat->phone = $phone;
        $chat->customer_name = $customerName;
        $chat->registration_no = $registrationNo;
        $chat->email = $email;
        $chat->user_info_submitted_at = now();
        if ($chat->prechat_submitted_at === null) {
            $chat->prechat_submitted_at = now();
        }

        // When the visitor submits a different registration number, any previously fetched
        // third-party data/PDF should be considered stale (so hide "Send PDF" until re-fetch).
        $incomingRegistrationNo = is_string($registrationNo) ? trim($registrationNo) : null;
        if (($existingRegistrationNo ?? '') !== ($incomingRegistrationNo ?? '')) {
            $chat->external_api_status = null;
            $chat->external_api_error = null;
            $chat->external_api_response = null;
            $chat->external_api_fetched_at = null;
            $chat->external_api_pdf_sent_at = null;
        }
    }

    private function applyVisitorPrechatInfoToChat(Request $request, Chat $chat): void
    {
        if ($request->input('message_type') !== 'prechat_info_response' || $request->input('sender_type') !== 'visitor') {
            return;
        }

        $phone = $request->input('phone');
        $customerName = $request->input('customer_name');
        $message = (string) ($request->input('message') ?? '');

        $phone = is_string($phone) ? trim($phone) : null;
        $customerName = is_string($customerName) ? trim($customerName) : null;

        if ((!$phone || !$customerName) && $message !== '') {
            $decoded = json_decode($message, true);
            if (is_array($decoded)) {
                $phone = $phone ?: (is_string($decoded['phone'] ?? null) ? trim($decoded['phone']) : null);
                $customerName = $customerName ?: (is_string($decoded['name'] ?? null) ? trim($decoded['name']) : null);
            }
        }

        if (!$phone || !$customerName) {
            return;
        }

        if (strlen($phone) > 50 || strlen($customerName) > 255) {
            return;
        }

        $chat->phone = $phone;
        $chat->customer_name = $customerName;
        $chat->prechat_submitted_at = now();
    }

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
        // dd($request->message_type,$request->phone,$request->customer_name,$request->registration_no,$request->email,);
        $request->validate([
            'message' => 'required_without:attachments|nullable|string',
            'sender_type' => 'required|string',
            'chat_id' => 'required|exists:chats,id',
            'message_type' => 'nullable|string',
            'attachments' => 'nullable|file|max:20480',
            'phone' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'registration_no' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:255',
        ]);

        try {
            $chat = Chat::find($request->chat_id);
            $messageType = is_string($request->input('message_type')) ? trim((string) $request->input('message_type')) : null;

            $hasBasicInfo = is_string($chat->phone) && trim($chat->phone) !== '' && is_string($chat->customer_name) && trim($chat->customer_name) !== '';
            if ($chat->prechat_submitted_at === null && ($chat->user_info_submitted_at !== null || $hasBasicInfo)) {
                $chat->prechat_submitted_at = now();
            }

            if ($chat->prechat_submitted_at === null) {
                if ($request->sender_type === 'visitor' && $messageType !== 'prechat_info_response') {
                    return response()->json([
                        'message' => 'Please provide your name and phone number to start chatting.',
                    ], 409);
                }
                if ($request->sender_type === 'agent' && $messageType !== 'prechat_info_request') {
                    return response()->json([
                        'message' => 'Waiting for visitor details (name & phone) before chatting.',
                    ], 409);
                }
            }

            if ($request->sender_type === 'visitor') {
                $chat->last_activity = now();
                $chat->visitor_last_read_at = now();
                broadcast(new \App\Events\ChatPing($chat));
            }

            $this->applyVisitorPrechatInfoToChat($request, $chat);
            $this->applyVisitorUserInfoToChat($request, $chat);

            $chat_message = [];

            if ($request->message_type == 'user_info_response') {
                $chat_message = [
                    'type' => 'user_info_response',
                    'name' => $request->customer_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'registration_no' => $request->registration_no,
                ];
            }elseif ($request->message_type == 'prechat_info_response') {
                $chat_message = [
                    'type' => 'prechat_info_response',
                    'name' => $request->customer_name,
                    'phone' => $request->phone,
                ];
            }else{
                $chat_message = $request->message;
            }
            // dd($chat_message);
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
                'message' => is_array($chat_message) ? json_encode($chat_message) : $chat_message,
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
            if ($request->sender_type === 'agent') {
                $this->broadcastReadUpdate($chat, 'agent');
            }

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
        $path = $disk->path($message->attachments);
        $ext = strtolower((string) pathinfo($message->attachments, PATHINFO_EXTENSION));
        if (in_array($ext, ['html', 'htm'], true)) {
            return response()->file($path, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        return response()->file($path);
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
        // dd($request->all());
        $request->validate([
            'current_url' => 'nullable|string|max:2048',
        ]);
        $visitorId = session()->get('visitor_id', Str::uuid());
        session()->put('visitor_id', $visitorId);
        $Company = Company::where('name', 'Default')->first();
        
        $chat = Chat::firstOrCreate(
            ['visitor_id' => $visitorId],
            [
                'status' => 'open',
                'company_id' => $Company->uuid ?? null,
                'last_message_at' => now(),
                'agent_last_read_at' => now(),
                'visitor_last_read_at' => now(),
            ]
        );
        $this->createWelcomeMessageIfNeeded($chat, config('chat.welcome_message'));
        $hasBasicInfo = is_string($chat->phone) && trim($chat->phone) !== '' && is_string($chat->customer_name) && trim($chat->customer_name) !== '';
        if ($chat->prechat_submitted_at === null && ($chat->user_info_submitted_at !== null || $hasBasicInfo)) {
            $chat->prechat_submitted_at = now();
        }

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
        try {
            broadcast(new NewChat($chat));
        } catch (\Throwable $e) {
            report($e);
        }
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
                'visitor_last_read_at' => now(),
            ]
        );
        $this->createWelcomeMessageIfNeeded($chat, config('chat.welcome_message'));
        $hasBasicInfo = is_string($chat->phone) && trim($chat->phone) !== '' && is_string($chat->customer_name) && trim($chat->customer_name) !== '';
        if ($chat->prechat_submitted_at === null && ($chat->user_info_submitted_at !== null || $hasBasicInfo)) {
            $chat->prechat_submitted_at = now();
        }
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
                'visitor_last_read_at' => now(),
            ]
        );
        $this->createWelcomeMessageIfNeeded($chat, config('chat.widget_welcome_message'));
        $hasBasicInfo = is_string($chat->phone) && trim($chat->phone) !== '' && is_string($chat->customer_name) && trim($chat->customer_name) !== '';
        if ($chat->prechat_submitted_at === null && ($chat->user_info_submitted_at !== null || $hasBasicInfo)) {
            $chat->prechat_submitted_at = now();
        }
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

        try {
            broadcast(new NewChat($chat));
        } catch (\Throwable $e) {
            report($e);
        }
    
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
            'phone' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'registration_no' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:255',
        ]);

        try {
            $chat = Chat::find($request->chat_id);

            $messageType = is_string($request->input('message_type')) ? trim((string) $request->input('message_type')) : null;
            $hasBasicInfo = is_string($chat->phone) && trim($chat->phone) !== '' && is_string($chat->customer_name) && trim($chat->customer_name) !== '';
            if ($chat->prechat_submitted_at === null && ($chat->user_info_submitted_at !== null || $hasBasicInfo)) {
                $chat->prechat_submitted_at = now();
            }

            if ($chat->prechat_submitted_at === null) {
                if ($request->sender_type === 'visitor' && $messageType !== 'prechat_info_response') {
                    return response()->json([
                        'message' => 'Please provide your name and phone number to start chatting.',
                    ], 409);
                }
                if ($request->sender_type === 'agent' && $messageType !== 'prechat_info_request') {
                    return response()->json([
                        'message' => 'Waiting for visitor details (name & phone) before chatting.',
                    ], 409);
                }
            }

            if ($request->sender_type === 'visitor') {
                $chat->last_activity = now();
                $chat->visitor_last_read_at = now();
                broadcast(new \App\Events\ChatPing($chat));
            }

            $this->applyVisitorPrechatInfoToChat($request, $chat);
            $this->applyVisitorUserInfoToChat($request, $chat);

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
            if ($request->sender_type === 'agent') {
                $this->broadcastReadUpdate($chat, 'agent');
            }
            return response()->noContent();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to send message. Please try again.',
            ], 500);
        }
    }

    // Update last activity from visitor ping
    // public function ping(Request $request)
    // {
    //     $request->validate([
    //         'chat_id' => 'required|exists:chats,id',
    //         'current_url' => 'nullable|string|max:2048',
    //     ]);
    //     $chat = Chat::find($request->chat_id);
    //     $chat->last_activity = now();
    //     if (!$chat->ip) {
    //         $chat->ip = $request->ip();
    //     }
    //     $currentUrl = $this->resolveCurrentUrl($request);
    //     if ($currentUrl) {
    //         $chat->current_url = $currentUrl;
    //     }
    //     $chat->save();

    //     broadcast(new \App\Events\ChatPing($chat));

    //     return response()->json(['status' => 'ok']);
    // }


    public function ping(Request $request)
    {
        $validated = $request->validate([
            'chat_id'     => 'required|exists:chats,id',
            'current_url' => 'nullable|string|max:2048',
        ]);

        $currentUrl = $this->resolveCurrentUrl($request);

        $updates = [
            'last_activity' => now(),
            'ip'            => DB::raw("COALESCE(ip, '{$request->ip()}')"),
        ];

        if ($currentUrl) {
            $updates['current_url'] = $currentUrl;
        }
       Chat::where('id', $validated['chat_id'])->update($updates);
       $chat = Chat::find($validated['chat_id']);
        broadcast(new \App\Events\ChatPing($chat));

        return response()->json(['status' => 'ok']);
    }

    public function markVisitorRead(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => 'required|exists:chats,id',
            'visitor_id' => 'nullable|string|max:100',
        ]);

        $chat = Chat::findOrFail($validated['chat_id']);
        $sessionAllowed = $this->canVisitorAccessChat($chat);
        $payloadVisitorId = is_string($validated['visitor_id'] ?? null) ? trim((string) $validated['visitor_id']) : null;
        $payloadAllowed = $payloadVisitorId
            && preg_match('/^[a-zA-Z0-9._-]{8,100}$/', $payloadVisitorId) === 1
            && hash_equals((string) $chat->visitor_id, $payloadVisitorId);

        if (!$sessionAllowed && !$payloadAllowed) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat->visitor_last_read_at = now();
        $chat->save();

        $this->broadcastReadUpdate($chat, 'visitor');

        return response()->noContent();
    }

    public function externalMarkVisitorRead(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => 'required|exists:chats,id',
        ]);

        $chat = Chat::findOrFail($validated['chat_id']);
        $chat->visitor_last_read_at = now();
        $chat->save();

        $this->broadcastReadUpdate($chat, 'visitor');

        return response()->noContent();
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
        if ($request->sender_type === 'agent') {
            $this->broadcastReadUpdate($chat, 'agent');
        }

        return response()->json(['message' => $message]);
    }
}
