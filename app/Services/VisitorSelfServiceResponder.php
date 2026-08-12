<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\ChatExternalApiFetch;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VisitorSelfServiceResponder
{
    public function sendLedgerHtml(Chat $chat, string $registrationNo): ?Message
    {
        $registrationNo = trim($registrationNo);
        if ($registrationNo === '') {
            return $this->sendBotText($chat, 'Registration No is missing.', 'self_service_error');
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
                return $this->sendBotText($chat, 'Ledger file could not be fetched. Please try again later.', 'self_service_error');
            }

            $data = $response->json();
            if (! isset($data['meta']['data']) || ! is_string($data['meta']['data'])) {
                $this->recordLedgerFetch($chat, $registrationNo, 'error', 'No Registration no found.', is_array($data) ? $data : ['value' => $data]);

                return $this->sendBotText($chat, 'No ledger file found for this registration number.', 'self_service_error');
            }

            $decodedHtml = $this->addBootstrap4(urldecode($data['meta']['data']));
            $safeReg = $this->safeRegistrationFilePart($registrationNo);
            $fileName = 'ledger-' . $safeReg . '-' . (string) Str::uuid() . '.html';
            $path = 'chat-attachments/' . $chat->id . '/' . $fileName;
            Storage::disk('public')->put($path, $decodedHtml);

            $payload = [
                'html_path' => $path,
                'status' => $data['meta']['status'] ?? null,
                'phone' => $data['meta']['phone'] ?? null,
                'message' => $data['data']['errors']['message'] ?? null,
            ];

            $chat->registration_no = $registrationNo;
            $chat->external_api_status = 'success';
            $chat->external_api_error = null;
            $chat->external_api_response = $payload;
            $chat->external_api_fetched_at = now();
            $chat->save();

            $this->recordLedgerFetch($chat, $registrationNo, 'success', null, $payload);

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'agent',
                'message' => 'Your ledger file is ready to download.',
                'message_type' => 'external_data_html',
                'attachments' => $path,
            ]);

            $this->touchChatForBotMessage($chat, $message);
            broadcast(new MessageSent($message));

            return $message;
        } catch (\Throwable $e) {
            report($e);

            return $this->sendBotText($chat, 'Ledger service is currently unavailable. Please try again later.', 'self_service_error');
        }
    }

    public function sendCnicLookupResult(Chat $chat, string $cnic): ?Message
    {
        $cnic = trim($cnic);
        if ($cnic === '') {
            return $this->sendBotText($chat, 'CNIC is missing.', 'self_service_error');
        }

        try {
            $response = Http::withHeaders([
                'token' => env('LEDGER_API_TOKEN'),
            ])
                ->timeout(920)
                ->get(env('CNIC_LOOKUP_API_URL'), [
                    'cnic' => $cnic,
                ]);

            if ($response->failed()) {
                return $this->sendBotText($chat, 'CNIC lookup failed. Please try again later.', 'self_service_error');
            }

            $data = $response->json();
            $files = $data['data']['files'] ?? [];
            $registrationNumbers = collect(is_array($files) ? $files : [])
                ->map(fn ($file) => is_array($file) ? ($file['reg_no'] ?? null) : null)
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn ($value) => trim($value))
                ->values()
                ->all();

            $apiMessage = $data['data']['message'] ?? null;
            $text = count($registrationNumbers) > 0
                ? "Registration numbers found:\n" . implode("\n", array_map(fn ($reg) => '- ' . $reg, $registrationNumbers))
                : ($apiMessage ?: 'No registration numbers found for this CNIC.');

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'agent',
                'message' => json_encode([
                    'type' => 'cnic_lookup_response',
                    'cnic' => $cnic,
                    'registration_numbers' => $registrationNumbers,
                    'message' => $apiMessage,
                    'text' => $text,
                ]),
                'message_type' => 'cnic_lookup_response',
            ]);

            $this->touchChatForBotMessage($chat, $message);
            broadcast(new MessageSent($message));

            return $message;
        } catch (\Throwable $e) {
            report($e);

            return $this->sendBotText($chat, 'CNIC lookup service is currently unavailable. Please try again later.', 'self_service_error');
        }
    }

    private function sendBotText(Chat $chat, string $text, string $messageType): Message
    {
        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'agent',
            'message' => $text,
            'message_type' => $messageType,
        ]);

        $this->touchChatForBotMessage($chat, $message);
        broadcast(new MessageSent($message));

        return $message;
    }

    private function touchChatForBotMessage(Chat $chat, Message $message): void
    {
        $chat->last_message_at = $message->created_at;
        $chat->agent_last_read_at = now();
        $chat->save();
    }

    private function recordLedgerFetch(Chat $chat, string $registrationNo, string $status, ?string $error, array $response): void
    {
        ChatExternalApiFetch::create([
            'chat_id' => $chat->id,
            'registration_no' => $registrationNo,
            'status' => $status,
            'error' => $error,
            'response' => $response,
            'fetched_at' => now(),
        ]);
    }

    private function safeRegistrationFilePart(string $registrationNo): string
    {
        $safeReg = preg_replace('/[^a-zA-Z0-9-_]+/', '-', $registrationNo);
        $safeReg = trim((string) $safeReg, '-');

        return $safeReg !== '' ? $safeReg : 'registration';
    }

    private function addBootstrap4(string $html): string
    {
        $bootstrapCss = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.3.7/dist/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">';
        $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">';

        if (strpos($html, '<head>') !== false) {
            return str_replace('<head>', '<head>' . $viewport . $bootstrapCss, $html);
        }

        return '<head>' . $viewport . $bootstrapCss . '</head>' . $html;
    }
}
