<?php

namespace App\Http\Controllers;

use App\Events\ChatReadUpdated;
use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\ChatExternalApiFetch;
use App\Models\ChatFeedback;
use App\Models\Company;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AgentController extends Controller
{
    private function allowedCompanyUuids(): Collection
    {
        $companyIds = DB::table('company_user')
            ->where('user_id', auth()->id())
            ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return collect();
        }

        return Company::query()
            ->whereIn('id', $companyIds)
            ->pluck('uuid');
    }

    private function assertCanActOnChat(Chat $chat): void
    {
        if ($chat->assigned_agent_id && $chat->assigned_agent_id !== auth()->id()) {
            abort(403);
        }
    }

    public function dashboard()
    {
        return Inertia::render('Dashboard');
    }

    public function cnicLookup(Request $request)
    {
        $validated = $request->validate([
            'cnic' => ['required', 'string', 'max:32'],
        ]);

        $digits = (string) $validated['cnic'];
        if (strlen((string) $digits) !== 15) {
            return response()->json([
                'message' => 'CNIC must be 13 digits.',
            ], 422);
        }

        try {
            $response = Http::withHeaders([
                'token' => env('LEDGER_API_TOKEN'),
            ])
                ->timeout(920)
                ->get(env('CNIC_LOOKUP_API_URL'), [
                    'cnic' => $validated['cnic'],
                ]);
            $jsonResponse = $response->json();

            if ($response->failed()) {
                return response()->json([
                    'message' => 'CNIC lookup API failed.',
                    'status' => $response->status(),
                    'details' => Str::limit((string) $response->body(), 2000),
                ], 502);
            }

            return response()->json([
                'cnic' => $digits,
                'digits' => $digits,
                'data' => $jsonResponse,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'CNIC lookup failed.'.$e->getMessage(),
            ], 500);
        }
    }

    public function index()
    {
        $CompanyUUID = $this->allowedCompanyUuids();

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
                            $q->whereNull('chats.agent_last_read_at')
                                ->orWhereColumn('messages.created_at', '>', 'chats.agent_last_read_at');
                        });
                },
            ])
            ->with('companyRel')
            ->where('last_message_at', '>=', now()->subHours(24))
            ->whereIn('company_id', $CompanyUUID->toArray())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $chats->each->append('is_online');
        return Inertia::render('Agent/Chats', [
            'chats' => $chats,
            'auth_user' => auth()->user(),
            'loginUserCompniesList' => $CompanyUUID,
            'pollCursor' => now()->toIso8601String(),
        ]);
    }

    public function history(Request $request)
    {
        $CompanyUUID = $this->allowedCompanyUuids();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            // 'status' => ['nullable', 'in:open,close'],
            'company_id' => ['nullable', 'string', 'max:64'],
            'assigned' => ['nullable', 'in:any,me,assigned,unassigned'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $perPage = $validated['per_page'] ?? 25;

        $companies = Company::query()
            ->whereIn('uuid', $CompanyUUID->toArray())
            ->select('uuid', 'name')
            ->orderBy('name')
            ->get();

        $chats = Chat::query()
            ->with([
                'agent:id,name',
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
            ->whereIn('company_id', $CompanyUUID->toArray())
            ->when(! empty($validated['company_id'] ?? null), function ($q) use ($validated, $CompanyUUID) {
                $companyId = (string) $validated['company_id'];
                if (! $CompanyUUID->contains($companyId)) {
                    abort(403);
                }
                $q->where('company_id', $companyId);
            })
            ->where('status', 'close') 
            ->when(! empty($validated['assigned'] ?? null) && $validated['assigned'] !== 'any', function ($q) use ($validated) {
                if ($validated['assigned'] === 'me') {
                    $q->where('assigned_agent_id', auth()->id());
                } elseif ($validated['assigned'] === 'assigned') {
                    $q->whereNotNull('assigned_agent_id');
                } elseif ($validated['assigned'] === 'unassigned') {
                    $q->whereNull('assigned_agent_id');
                }
            })
            ->when(! empty($validated['from'] ?? null), fn ($q) => $q->whereDate('last_message_at', '>=', $validated['from']))
            ->when(! empty($validated['to'] ?? null), fn ($q) => $q->whereDate('last_message_at', '<=', $validated['to']))
            ->when(! empty($validated['search'] ?? null), function ($q) use ($validated) {
                $term = trim((string) $validated['search']);
                if ($term === '') {
                    return;
                }

                $q->where(function ($q2) use ($term) {
                    $q2
                        ->where('phone', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('registration_no', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('website', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Agent/ChatHistory', [
            'chats' => $chats,
            'filters' => [
                'search' => $validated['search'] ?? '',
                'status' => $validated['status'] ?? '',
                'company_id' => $validated['company_id'] ?? '',
                'assigned' => $validated['assigned'] ?? 'any',
                'from' => $validated['from'] ?? '',
                'to' => $validated['to'] ?? '',
                'per_page' => $perPage,
            ],
            'companies' => $companies,
        ]);
    }

    public function historyShow(Request $request, Chat $chat)
    {
        $CompanyUUID = $this->allowedCompanyUuids();
        if (! $CompanyUUID->contains((string) $chat->company_id)) {
            abort(403);
        }

        $chat->load([
            'agent:id,name',
            'companyRel:uuid,name',
        ]);

        $messages = $chat
            ->messages()
            ->orderBy('created_at')
            ->paginate(100)
            ->withQueryString();

        return Inertia::render('Agent/ChatHistoryDetail', [
            'chat' => $chat,
            'messages' => $messages,
        ]);
    }

    public function historyMessages(Request $request, Chat $chat)
    {
        $CompanyUUID = $this->allowedCompanyUuids();
        if (! $CompanyUUID->contains((string) $chat->company_id)) {
            abort(403);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);

        $limit = $validated['limit'] ?? 200;

        $chat->load([
            'agent:id,name',
            'companyRel:uuid,name',
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

        $messages = $chat
            ->messages()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'chat' => $chat,
            'messages' => $messages,
        ]);
    }

    public function poll(Request $request)
    {
        $validated = $request->validate([
            'cursor' => 'nullable|string',
        ]);

        $since = null;
        if (! empty($validated['cursor'])) {
            try {
                $since = Carbon::parse($validated['cursor']);
            } catch (\Throwable $e) {
                $since = null;
            }
        }

        // if the client doesn't provide a cursor, default to a short lookback window
        $since ??= now()->subMinutes(2);

        $CompanyUUID = $this->allowedCompanyUuids();

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
            ->whereIn('company_id', $CompanyUUID->toArray())
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
            'chat' => $chat->load('messages'),
        ]);
    }

    public function markRead(Chat $chat)
    {
        if ($chat->assigned_agent_id) {
            if ($chat->assigned_agent_id !== auth()->id()) {
                return response()->noContent();
            } else {
                $chat->agent_last_read_at = now();
                $chat->save();
                broadcast(new ChatReadUpdated($chat, 'agent'));
            }
        } else {
            $chat->assigned_agent_id = auth()->id();
            $chat->agent_last_read_at = now();
            $chat->save();
            broadcast(new ChatReadUpdated($chat, 'agent'));
        }

        return response()->noContent();
    }

    public function destroy(Chat $chat)
    {
        $chat->messages()->delete();
        $chat->delete();
        // return response()->json(['message' => 'Chat and all its messages deleted successfully.']);
    }

    public function feedbacks(Chat $chat)
    {
        $this->assertCanActOnChat($chat);
        $feedbacks = ChatFeedback::query()
            ->where('chat_id', $chat->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $response = Http::withHeaders([
            'accept' => 'text/plain',
            'Authorization' => env('ENQUIRY_TYPE_API_TOKEN'),
        ])->get(env('ENQUIRY_TYPE_API_URL'), [
            'AppDateTime' => date('Y-m-d'),
        ]);
        $apiData = $response->json();

        return response()->json([
            'feedbacks' => $feedbacks,
            'inquiries' => $apiData,
        ]);
    }

    public function storeFeedback(Request $request, Chat $chat)
    {
        $request->validate([
            'inquiries' => ['required', 'array', function ($attribute, $value, $fail) {
                $hasAtLeastOne = false;

                foreach ($value as $group) {
                    if (! empty($group)) {
                        $hasAtLeastOne = true;
                        break;
                    }
                }
                if (! $hasAtLeastOne) {
                    $fail('At least one inquiry must contain data.');
                }
            }],
        ]);

        $inquiryFeedBack = [];
        foreach ($request->inquiries as $key => $inquiryId) {
            foreach ($inquiryId as $list) {
                $inquiryFeedBack[] = [
                    'chat_id' => $chat->id,
                    'chat_type' => 'web_chat',
                    'registration' => $request->registration_no,
                    'inquiry_type' => $key,
                    'inquiry_id' => $list['id'],
                    'inquiry_name' => $list['name'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        ChatFeedback::insert($inquiryFeedBack);
        $payload = [
            'registration_no' => $request->registration_no,
            'inquiries' => $request->inquiries,
        ];

        $url = env('ENQUIRY_TYPE_POST_API_URL');

        Http::withHeaders([
            'accept' => '*/*',
            'Authorization' => '6XesrAM2Nu',
            'Content-Type' => 'application/json',
        ])->post(
            $url.'?AppDateTime='.now()->toDateString(),
            $payload
        );

        return response()->json([
            'message' => 'Feedback stored successfully',
        ], 201);
    }

    public function close(Chat $chat)
    {
        if ($chat->assigned_agent_id && $chat->assigned_agent_id !== auth()->id()) {
            return response()->noContent();
        }

        if (! $chat->assigned_agent_id) {
            $chat->assigned_agent_id = auth()->id();
        }

        // Only send thank you message if chat is not already closed
        if ($chat->status !== 'close') {
            $thankYouMessage = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'agent',
                'message' => 'Thank you for contacting us. We appreciate your time and hope we were able to assist you. If you have any further questions, please don\'t hesitate to reach out again.',
                'message_type' => 'system',
            ]);

            $chat->last_message_at = $thankYouMessage->created_at;
            broadcast(new MessageSent($thankYouMessage));
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

    public function transfer(Request $request, Chat $chat)
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'exists:users,id'],
        ]);

        $targetAgentId = $validated['agent_id'];

        // Don't allow transfer to self
        if ($targetAgentId == auth()->id()) {
            return response()->json([
                'message' => 'You cannot transfer chat to yourself.',
            ], 422);
        }

        // Check if the chat belongs to the current agent's company
        $companyIds = DB::table('company_user')->where('user_id', auth()->id())->pluck('company_id');
        $targetAgentCompanyIds = DB::table('company_user')->where('user_id', $targetAgentId)->pluck('company_id');

        if ($companyIds->intersect($targetAgentCompanyIds)->isEmpty()) {
            return response()->json([
                'message' => 'Target agent is not in your company.',
            ], 422);
        }

        $previousAgentId = $chat->assigned_agent_id;
        $chat->assigned_agent_id = $targetAgentId;
        $chat->save();

        // Create a system message about the transfer
        $transferMessage = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'system',
            'message' => 'Chat transferred from Agent ID ' . $previousAgentId . ' to Agent ID ' . $targetAgentId . ' by ' . auth()->user()->name,
            'message_type' => 'system',
        ]);

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
            'agent',
        ]);
        $chat->append('is_online');

        // Broadcast the transfer message
        broadcast(new MessageSent($transferMessage));

        return response()->json([
            'chat' => $chat,
            'message' => 'Chat transferred successfully.',
        ]);
    }

    public function transferUsers(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'string', 'exists:companies,uuid'],
        ]);
        $company = Company::query()
        ->where('uuid', $validated['company_id'])
        ->firstOrFail();
        
        $hasAccess = DB::table('company_user')
        ->where('company_id', $company->id)
        ->pluck('user_id');
        
        $users = DB::table('users')
        ->where('users.id', '!=', auth()->id())
        ->whereIn('users.id', $hasAccess)
        ->select('users.id', 'users.name', 'users.email')
        ->distinct()
        ->orderBy('users.name')
        ->get();
      
        return response()->json([
            'users' => $users->toArray(),
        ]);
    }

    public function fetchExternalData(Request $request, Chat $chat)
    {
        $this->assertCanActOnChat($chat);
        $chat->loadMissing('messages');
        $registrationNo = $request->input('registration_no');
        if (! $registrationNo) {
            return response()->json([
                'message' => 'Registration No is missing for this chat.',
            ], 422);
        }
        try {
            $response = Http::withHeaders([
                'token' => env('LEDGER_API_TOKEN'),
            ])
                ->timeout(920)
                ->get(env('LEDGER_API_URL'), [
                    'file' => $registrationNo,
                ]);
            if ($response->failed()) {
                return response()->json([
                    'error' => 'External API failed',
                    'details' => $response->body(),
                ], 500);
            }
            $data = $response->json();
            if (isset($data['meta']['data']) && is_string($data['meta']['data'])) {
                $decodedHtml = urldecode($data['meta']['data']);
                $decodedHtml = $this->addBootstrap4($decodedHtml);
                if (! $chat->registration_no || trim((string) $chat->registration_no) !== $registrationNo) {
                    $chat->registration_no = $registrationNo;
                }
                $reg = (string) $registrationNo;
                $safeReg = preg_replace('/[^a-zA-Z0-9-_]+/', '-', $reg);
                $safeReg = trim((string) $safeReg, '-');
                if ($safeReg === '') {
                    $safeReg = 'registration';
                }
                $fileName = 'external-html-'.$safeReg.'-'.(string) Str::uuid().'.html';
                $dir = 'external-api/'.$chat->id;
                $htmlPath = $dir.'/'.$fileName;
                Storage::disk('public')->put($htmlPath, $decodedHtml);
                $payload = [
                    'html_path' => $htmlPath,
                    'status' => $data['meta']['status'] ?? null,
                    'phone' => $data['meta']['phone'] ?? null,
                    'message' => $data['data']['errors']['message'] ?? null,
                ];
                $chat->external_api_status = 'success';
                $chat->external_api_error = null;
                $chat->external_api_response = $payload;
                $chat->external_api_fetched_at = now();
                $chat->save();
                ChatExternalApiFetch::create([
                    'chat_id' => $chat->id,
                    'registration_no' => $registrationNo,
                    'status' => 'success',
                    'error' => null,
                    'response' => $payload,
                    'fetched_at' => $chat->external_api_fetched_at,
                ]);

                return response()->json([
                    'chat' => $chat,
                    'external_data' => $payload,
                ]);
            }
            $chat->external_api_status = 'error';
            $chat->external_api_error = 'No Registration no found.';
            $chat->external_api_response = is_array($data) ? $data : ['value' => $data];
            $chat->external_api_fetched_at = now();
            $chat->save();
            ChatExternalApiFetch::create([
                'chat_id' => $chat->id,
                'registration_no' => $registrationNo,
                'status' => 'error',
                'error' => $chat->external_api_error,
                'response' => $chat->external_api_response,
                'fetched_at' => $chat->external_api_fetched_at,
            ]);

            return response()->json([
                'chat' => $chat,
                'external_data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Something went wrong',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendExternalHtml(Request $request, Chat $chat)
    {
        $this->assertCanActOnChat($chat);
        $registrationNo = $request->input('registration_no');
        $fetch = ChatExternalApiFetch::query()
            ->where('chat_id', $chat->id)
            ->where('registration_no', $registrationNo)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->first();

        $data = $fetch?->response;
        $html = '';
        if (is_array($data)) {
            if (! empty($data['html_path']) && is_string($data['html_path'])) {
                $disk = Storage::disk('public');
                if ($disk->exists($data['html_path'])) {
                    $html = (string) $disk->get($data['html_path']);
                }
            }
            if ($html === '' && isset($data['html_content']) && is_string($data['html_content'])) {
                $html = (string) $data['html_content'];
            }
        }
        $html = trim((string) $html);
        if (! $fetch || $html === '') {
            return response()->json([
                'message' => 'No external HTML found for this registration. Please fetch data first.',
            ], 422);
        }
        try {
            $reg = (string) $registrationNo;
            $safeReg = preg_replace('/[^a-zA-Z0-9-_]+/', '-', $reg);
            $safeReg = trim((string) $safeReg, '-');
            if ($safeReg === '') {
                $safeReg = 'registration';
            }

            $fileName = 'external-html-'.$safeReg.'-'.(string) Str::uuid().'.html';
            $dir = 'chat-attachments/'.$chat->id;
            $path = $dir.'/'.$fileName;
            Storage::disk('public')->put($path, $html);

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'agent',
                'sender_id' => auth()->id(),
                'message' => null,
                'message_type' => 'external_data_html',
                'attachments' => $path,
            ]);

            $chat->last_message_at = $message->created_at;
            $chat->agent_last_read_at = now();
            if (! $chat->assigned_agent_id) {
                $chat->assigned_agent_id = auth()->id();
            }
            $chat->save();
            broadcast(new MessageSent($message));
            broadcast(new ChatReadUpdated($chat, 'agent'));

            return response()->json([
                'chat' => $chat,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to send external HTML.',
            ], 500);
        }
    }

    private function addBootstrap4($html)
    {
        $bootstrapCss = '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
        $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">';

        if (strpos($html, '<head>') !== false) {
            $html = str_replace('<head>', '<head>'.$viewport.$bootstrapCss, $html);
        } else {
            $html = '<head>'.$viewport.$bootstrapCss.'</head>'.$html;
        }
        if (strpos($html, '<div class="container') === false) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
                $bodyContent = $matches[1];
                $wrappedContent = '<div class="container mt-3">'.$bodyContent.'</div>';
                $html = str_replace($bodyContent, $wrappedContent, $html);
            } else {
                $html = '<div class="container mt-3">'.$html.'</div>';
            }
        }

        return $html;
    }

    /**
     * Display chat system reports and analytics.
     */
    public function reports(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $selectedCompany = $request->input('selectedCompany');

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : Carbon::today()->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::today()->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $company = Company::get();

        $stats = [
            'total_visits' => Chat::byCompanyUuid($selectedCompany)->whereBetween('created_at', [$from, $to])->count(),
            'users_who_messaged' => Chat::byCompanyUuid($selectedCompany)->whereBetween('created_at', [$from, $to])->whereHas('messages', function ($q) {
                $q->where('sender_type', 'visitor');
            })->count(),

            'active_chats_count' => Chat::byCompanyUuid($selectedCompany)->whereBetween('created_at', [$from, $to])->where('status', 'open')->where('last_activity', '>=', Carbon::now()->subMinutes(15))->count(),
            'unassigned_chats_count' => Chat::byCompanyUuid($selectedCompany)->whereBetween('created_at', [$from, $to])->whereNull('assigned_agent_id')->count(),
            'chats_by_status' => Chat::byCompanyUuid($selectedCompany)->select('status', DB::raw('count(*) as count'))
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('status')
                ->get(),
            'agent_concurrency' => DB::table('chats')
                ->join('users as agents', 'chats.assigned_agent_id', '=', 'agents.id')
                ->join('companies', 'chats.company_id', '=', 'companies.uuid')
                ->leftJoin('messages', 'messages.chat_id', '=', 'chats.id')
                ->when($selectedCompany, function ($query) use ($selectedCompany) {
                    $query->where('companies.uuid', $selectedCompany);
                })
                ->whereBetween('chats.created_at', [$from, $to])
                ->whereNotNull('chats.assigned_agent_id')
                ->select(
                    'chats.assigned_agent_id',
                    'agents.name',
                    DB::raw("
                    COUNT(DISTINCT CASE 
                        WHEN messages.sender_type = 'agent' 
                        THEN chats.id 
                    END) as agent_sent_users
                "),
                    DB::raw("
                    COUNT(DISTINCT CASE 
                        WHEN messages.sender_type = 'visitor' 
                        THEN chats.id 
                    END) as user_replied_users
                ")
                )
                ->groupBy('chats.assigned_agent_id', 'agents.name')
                ->get(),
        ];

        return Inertia::render('Agent/Reports', [
            'stats' => $stats,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'selectedCompany' => $request->input('selectedCompany', ''),
            ],
            'company' => $company,
        ]);
    }

    /**
     * Export chat reports to a CSV file.
     */
    public function exportReports()
    {
        $dailyStats = Chat::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as visits'),
            DB::raw('count(CASE WHEN user_info_submitted_at IS NOT NULL THEN 1 END) as info_submissions'),
            DB::raw('(SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, msg_v.created_at, msg_a.created_at)), 0) 
                       FROM messages msg_v 
                       JOIN messages msg_a ON msg_v.chat_id = msg_a.chat_id 
                       WHERE msg_v.sender_type = "visitor" AND msg_a.sender_type = "agent" 
                       AND msg_a.created_at > msg_v.created_at 
                       AND DATE(msg_v.created_at) = DATE(ANY_VALUE(chats.created_at))) as avg_response_time')
        )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $fileName = 'chat_reports_'.date('Y-m-d').'.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = ['Date', 'Visits', 'Leads (Info Submissions)', 'Avg Response Time (seconds)'];

        $callback = function () use ($dailyStats, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($dailyStats as $row) {
                fputcsv($file, [$row->date, $row->visits, $row->info_submissions, $row->avg_response_time ?? 0]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Display SLA report showing average first response time.
     */
    public function slaReport(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'selectedCompany' => 'nullable|string',
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : Carbon::today()->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::today()->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $selectedCompany = $request->input('selectedCompany');
        // dd($selectedCompany);
        $company = Company::get();
        $chatsQuery = Chat::whereBetween('created_at', [$from, $to]);
        if ($selectedCompany) {
            $chatsQuery->where('company_id', $selectedCompany);
        }
        $chats = $chatsQuery->get();
        $chatIds = $chats->pluck('id')->toArray();
        // dd($chats);
        if (empty($chatIds)) {
            // dd($chatIds,'fdsfsdfsd');
            $stats = [
                'avg_response_time' => 0,
                'total_chats' => 0,
                'chats_with_response' => 0,
                'delayed_chats' => [],
                'unanswered_chats' => [],
            ];
        } else {
            // dd($chatIds,'12');
            $messages = Message::whereIn('chat_id', $chatIds)
                ->with('chat.agent')
                ->orderBy('chat_id')
                ->orderBy('created_at', 'asc')
                ->get();

            $chatData = [];
            foreach ($messages as $message) {
                $chatData[$message->chat_id][] = $message;
            }
            $responseTimes = [];
            $delayedChats = [];
            $unansweredChats = [];

            foreach ($chatData as $chatId => $msgs) {
                $firstVisitor = null;
                foreach ($msgs as $msg) {
                    if ($msg->sender_type === 'visitor') {
                        $firstVisitor = $msg;
                        break;
                    }
                }
                if (! $firstVisitor) {
                    continue;
                }

                $firstAgent = null;
                foreach ($msgs as $msg) {
                    if ($msg->sender_type === 'agent' && $msg->created_at > $firstVisitor->created_at) {
                        $firstAgent = $msg;
                        break;
                    }
                }

                if (! $firstAgent) {
                    $unansweredChats[] = [
                        'chat_id' => $chatId,
                        'created_at' => $msgs[0]->created_at->toDateTimeString(),
                        // 'first_message' => $msgs[1]->message ?? '',
                        'first_message' => json_decode($msgs[1]->message, true) ?: $msgs[1]->message,
                    ];

                    continue;
                }
                
                $responseTime = $firstAgent->created_at->getTimestamp() - $firstVisitor->created_at->getTimestamp();
                if ($responseTime >= 120) {
                    $delayedChats[] = [
                        'chat_id' => $chatId,
                        'response_time_seconds' => $responseTime,
                        'customer_name' => $firstAgent->chat->customer_name ?? 'N/A',
                        'agent_name' => $firstAgent->chat->agent->name ?? 'N/A',
                        // 'visitor_message' => $firstVisitor->message ?? '',
                        // 'first_visitor_message_type' => $firstVisitor->message_type,
                        // 'first_visitor_message_at' => $firstVisitor->created_at->toDateTimeString(),
                        // 'first_agent_response_at' => $firstAgent->created_at->toDateTimeString(),
                    ];
                }

                $responseTimes[] = $responseTime;
            }

            $avgResponseTime = empty($responseTimes) ? 0 : array_sum($responseTimes) / count($responseTimes);

            $delayedChatsPaginated = $this->paginateArray($delayedChats, 25);
            $unansweredChatsPaginated = $this->paginateArray($unansweredChats, 25);

            $stats = [
                'avg_response_time' => round($avgResponseTime / 60, 2),
                'total_chats' => count($chatIds),
                'chats_with_response' => count($responseTimes),
                'delayed_chats' => $delayedChatsPaginated,
                'unanswered_chats' => $unansweredChatsPaginated,
            ];
        }
        // dd($stats);
        return Inertia::render('Agent/SlaReport', [
            'stats' => $stats,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'selectedCompany' => $selectedCompany,
            ],
            'company' => $company,
        ]);
    }


    private function paginateArray($items, $perPage = 25, $page = null, $options = [])
    {
        $page = $page ?: LengthAwarePaginator::resolveCurrentPage();
        $items = $items instanceof Collection ? $items : collect($items);
        $options = [
            'path' => request()->url(),
            'query' => request()->query(), 
        ];
        return new LengthAwarePaginator(
            $items->forPage($page, $perPage),
            $items->count(),
            $perPage,
            $page,
            $options
        );
    }



}
