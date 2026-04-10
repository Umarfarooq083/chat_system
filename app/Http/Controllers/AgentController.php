<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\Chat;
use App\Models\ChatExternalApiFetch;
use App\Models\ChatFeedback;
use App\Models\Message;
use App\Events\MessageSent;
use App\Services\RegistrationApiClient;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class AgentController extends Controller
{
    private function extractFieldFromMessage(?string $message, string $label): ?string
    {
        if (!$message) return null;
        $pattern = '/^' . preg_quote($label, '/') . '\\s*:\\s*(.+)\\s*$/mi';
        if (preg_match($pattern, $message, $matches) !== 1) {
            return null;
        }
        $value = trim((string) ($matches[1] ?? ''));
        return $value !== '' ? $value : null;
    }

    private function assertCanActOnChat(Chat $chat): void
    {
        if ($chat->assigned_agent_id && $chat->assigned_agent_id !== auth()->id()) {
            abort(403);
        }
    }

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
        if ($chat->assigned_agent_id) {
            if ($chat->assigned_agent_id !== auth()->id()) {
                return response()->noContent();
            } else {
                $chat->agent_last_read_at = now();
                $chat->save();
            }
        } else {
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
            'AppDateTime' => Date('Y-m-d')
        ]);
        $apiData = $response->json();
        return response()->json([
            'feedbacks' => $feedbacks,
            // 'feedbacks' => [],
            'inquiries' => $apiData,
        ]);
    }

    public function storeFeedback(Request $request, Chat $chat)
    {
        $request->validate([
            'inquiries' => ['required', 'array', function ($attribute, $value, $fail) {
                $hasAtLeastOne = false;

                foreach ($value as $group) {
                    if (!empty($group)) {
                        $hasAtLeastOne = true;
                        break;
                    }
                }
                if (!$hasAtLeastOne) {
                    $fail('At least one inquiry must contain data.');
                }
            }],
        ]);

        $inquiryFeedBack = [];
        foreach ($request->inquiries as $key => $inquiryId) 
        {
            foreach($inquiryId as $list)
                {
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
            "registration_no" => $request->registration_no,
            "inquiries" => $request->inquiries,
        ];

        Http::withHeaders([
            'accept' => '*/*',
            'Authorization' => '6XesrAM2Nu',
            'Content-Type' => 'application/json',
        ])->post(
            'http://202.166.160.200:9074/api/App/AddCommentsByChat?AppDateTime=' . now()->toDateString(),
            $payload
        );
        return response()->json([
            'message' => 'Feedback stored successfully',
        ], 201);
    }

    public function close(Chat $chat)
    {
        // dd($chat);
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

    public function fetchExternalData(Request $request, Chat $chat, RegistrationApiClient $client)
    {
        $this->assertCanActOnChat($chat);
        $chat->loadMissing('messages');
        $registrationNo = $request->input('registration_no');
        if (!$registrationNo) {
            return response()->json([
                'message' => 'Registration No is missing for this chat.',
            ], 422);
        }

        try {
            $response = Http::withHeaders([
                'token' => 'bgc@123321'
            ])
            ->timeout(320)
            ->get(env('LEDGER_API_URL'), [
                'file' => $registrationNo
            ]);
            if ($response->failed()) {
                return response()->json([
                    'error' => 'External API failed',
                    'details' => $response->body()
                ], 500);
            }
            $data = $response->json();
            if (isset($data['meta']['data']) && is_string($data['meta']['data'])) {
                $decodedHtml = urldecode($data['meta']['data']);
                $decodedHtml = $this->addBootstrap4($decodedHtml);
                if (!$chat->registration_no || trim((string) $chat->registration_no) !== $registrationNo) {
                    $chat->registration_no = $registrationNo;
                }
                $reg = (string) $registrationNo;
                $safeReg = preg_replace('/[^a-zA-Z0-9-_]+/', '-', $reg);
                $safeReg = trim((string) $safeReg, '-');
                if ($safeReg === '') {
                    $safeReg = 'registration';
                }
                $fileName = 'external-html-' . $safeReg . '-' . (string) Str::uuid() . '.html';
                $dir = 'external-api/' . $chat->id;
                $htmlPath = $dir . '/' . $fileName;
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
                'external_data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Something went wrong',
                'message' => $e->getMessage()
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
            if (!empty($data['html_path']) && is_string($data['html_path'])) {
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
        if (!$fetch || $html === '') {
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

            $fileName = 'external-html-' . $safeReg . '-' . (string) Str::uuid() . '.html';
            $dir = 'chat-attachments/' . $chat->id;
            $path = $dir . '/' . $fileName;
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
            if (!$chat->assigned_agent_id) {
                $chat->assigned_agent_id = auth()->id();
            }
            $chat->save();
            broadcast(new MessageSent($message));
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
            $html = str_replace('<head>', '<head>' . $viewport . $bootstrapCss, $html);
        } else {
            $html = '<head>' . $viewport . $bootstrapCss . '</head>' . $html;
        }
        if (strpos($html, '<div class="container') === false) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
                $bodyContent = $matches[1];
                $wrappedContent = '<div class="container mt-3">' . $bodyContent . '</div>';
                $html = str_replace($bodyContent, $wrappedContent, $html);
            } else {
                $html = '<div class="container mt-3">' . $html . '</div>';
            }
        }

        return $html;
    }

    // public function fetchExternalData(Request $request, Chat $chat, RegistrationApiClient $client)
    // {
    //     $this->assertCanActOnChat($chat);

    //     $chat->loadMissing('messages');

    //     $requestRegistrationNo = $request->input('registration_no');
    //     $registrationNo = is_string($requestRegistrationNo) ? trim($requestRegistrationNo) : null;

    //     if (!$registrationNo) {
    //         $registrationNo = $chat->registration_no;
    //     }

    //     if (!$registrationNo) {
    //         $last = $chat->messages()
    //             ->where('message_type', 'user_info_response')
    //             ->orderByDesc('created_at')
    //             ->first();
    //         $registrationNo = $this->extractFieldFromMessage($last?->message, 'Registration No');
    //     }

    //     if (!$registrationNo) {
    //         return response()->json([
    //             'message' => 'Registration No is missing for this chat.',
    //         ], 422);
    //     }

    //     $registrationNo = trim((string) $registrationNo);

    //     try {
    //         $response = $client->lookup((string) $registrationNo, (int) $chat->id);
    //         $data = null;
    //         try {
    //             $data = $response->json();
    //         } catch (\Throwable $e) {
    //             $data = null;
    //         }
    //         if ($data === null) {
    //             $data = [
    //                 'raw' => $response->body(),
    //             ];
    //         }
    //         if (!is_array($data)) {
    //             $data = [
    //                 'value' => $data,
    //             ];
    //         }
    //         // keep chat registration in sync with the one we used for lookup
    //         if (!$chat->registration_no || trim((string) $chat->registration_no) !== $registrationNo) {
    //             $chat->registration_no = $registrationNo;
    //         }
    //         // persist on the chat record (DB)
    //         $chat->external_api_fetched_at = now();
    //         if ($response->successful()) {
    //             $isEmptyPayload = is_array($data) && count($data) === 0;
    //             $hasOnlyMessage = is_array($data) && array_key_exists('message', $data) && count(array_diff_key($data, ['message' => true])) === 0;

    //             if ($isEmptyPayload || $hasOnlyMessage) {
    //                 $chat->external_api_status = 'error';
    //                 $chat->external_api_error = trim((string) ($data['message'] ?? 'No third-party data found.'));
    //                 $chat->external_api_response = $data;
    //             } else {
    //                 $chat->external_api_status = 'success';
    //                 $chat->external_api_error = null;
    //                 $chat->external_api_response = $data;
    //             }
    //         } else {
    //             $chat->external_api_status = 'error';
    //             $chat->external_api_error = trim((string) ($data['message'] ?? $response->body())) ?: ('HTTP ' . $response->status());
    //             $chat->external_api_response = $data;
    //         }
    //         $chat->save();

    //         ChatExternalApiFetch::create([
    //             'chat_id' => $chat->id,
    //             'registration_no' => $registrationNo,
    //             'status' => $chat->external_api_status,
    //             'error' => $chat->external_api_error,
    //             'response' => $chat->external_api_response,
    //             'fetched_at' => $chat->external_api_fetched_at,
    //         ]);

    //         return response()->json([
    //             'chat' => $chat,
    //         ]);
    //     } catch (\Throwable $e) {
    //         report($e);

    //         $chat->external_api_status = 'error';
    //         $chat->external_api_error = 'Failed to fetch data from third-party API.';
    //         $chat->external_api_fetched_at = now();
    //         $chat->save();

    //         ChatExternalApiFetch::create([
    //             'chat_id' => $chat->id,
    //             'registration_no' => $registrationNo,
    //             'status' => 'error',
    //             'error' => $chat->external_api_error,
    //             'response' => null,
    //             'fetched_at' => $chat->external_api_fetched_at,
    //         ]);

    //         return response()->json([
    //             'message' => 'Failed to fetch data from third-party API.',
    //             'chat' => $chat,
    //         ], 500);
    //     }
    // }

    // public function sendExternalPdf(Request $request, Chat $chat)
    // {
    //     $this->assertCanActOnChat($chat);

    //     $requestRegistrationNo = $request->input('registration_no');
    //     $registrationNo = is_string($requestRegistrationNo) ? trim($requestRegistrationNo) : null;

    //     if (!$registrationNo) {
    //         $registrationNo = $chat->registration_no;
    //     }
    //     if (!$registrationNo) {
    //         $chat->loadMissing('messages');
    //         $last = $chat->messages()
    //             ->where('message_type', 'user_info_response')
    //             ->orderByDesc('created_at')
    //             ->first();
    //         $registrationNo = $this->extractFieldFromMessage($last?->message, 'Registration No');
    //     }
    //     $registrationNo = is_string($registrationNo) ? trim($registrationNo) : null;
    //     if (!$registrationNo) {
    //         return response()->json([
    //             'message' => 'Registration No is missing for this chat.',
    //         ], 422);
    //     }

    //     $fetch = ChatExternalApiFetch::query()
    //         ->where('chat_id', $chat->id)
    //         ->where('registration_no', $registrationNo)
    //         ->where('status', 'success')
    //         ->orderByDesc('id')
    //         ->first();

    //     $data = $fetch?->response;
    //     if (!$fetch || !is_array($data) || count($data) === 0) {
    //         return response()->json([
    //             'message' => 'No third-party data found for this registration. Please fetch data first.',
    //         ], 422);
    //     }

    //     try {
    //         $html = view('pdf.external-data', [
    //             'chat' => $chat,
    //             'data' => $data,
    //             'registrationNo' => $registrationNo,
    //             'generatedAt' => now()->toDateTimeString(),
    //         ])->render();

    //         $options = new Options();
    //         $options->set('defaultFont', 'DejaVu Sans');
    //         $options->set('isRemoteEnabled', false);

    //         $dompdf = new Dompdf($options);
    //         $dompdf->loadHtml($html, 'UTF-8');
    //         $dompdf->setPaper('A4', 'portrait');
    //         $dompdf->render();

    //         $pdfBytes = $dompdf->output();

    //         $reg = (string) $registrationNo;
    //         $safeReg = preg_replace('/[^a-zA-Z0-9-_]+/', '-', $reg);
    //         $safeReg = trim((string) $safeReg, '-');
    //         if ($safeReg === '') {
    //             $safeReg = 'registration';
    //         }

    //         $fileName = 'external-data-' . $safeReg . '-' . (string) Str::uuid() . '.pdf';
    //         $dir = 'chat-attachments/' . $chat->id;
    //         $path = $dir . '/' . $fileName;
    //         Storage::disk('public')->put($path, $pdfBytes);

    //         $message = Message::create([
    //             'chat_id' => $chat->id,
    //             'sender_type' => 'agent',
    //             'sender_id' => auth()->id(),
    //             'message' => null,
    //             'message_type' => 'external_data_pdf',
    //             'attachments' => $path,
    //         ]);

    //         $fetch->pdf_path = $path;
    //         $fetch->pdf_sent_at = now();
    //         $fetch->save();

    //         $chat->external_api_pdf_sent_at = now();
    //         $chat->last_message_at = $message->created_at;
    //         $chat->agent_last_read_at = now();
    //         if (!$chat->assigned_agent_id) {
    //             $chat->assigned_agent_id = auth()->id();
    //         }
    //         $chat->save();

    //         broadcast(new MessageSent($message));

    //         return response()->json([
    //             'chat' => $chat,
    //             'message' => $message,
    //         ]);
    //     } catch (\Throwable $e) {
    //         report($e);

    //         return response()->json([
    //             'message' => 'Failed to generate/send PDF.',
    //         ], 500);
    //     }
    // }
}
