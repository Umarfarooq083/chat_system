<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class RegistrationApiClient
{
    public function lookup(string $registrationNo, int $chatId): Response
    {
        $url = config('chat.registration_api_url');
        if (!$url) {
            throw new \RuntimeException('Registration API URL is not configured (CHAT_REGISTRATION_API_URL).');
        }

        $method = strtoupper((string) config('chat.registration_api_method', 'POST'));
        $timeout = (int) config('chat.registration_api_timeout', 15);

        $token = config('chat.registration_api_token');
        $tokenHeader = (string) config('chat.registration_api_token_header', 'token');
        $regKey = (string) config('chat.registration_api_query_registration_key', 'file');
        $chatKey = (string) config('chat.registration_api_query_chat_id_key', 'test');

        $headers = [];
        if ($token) {
            $headers[$tokenHeader] = (string) $token;
        }

        $payload = [
            $regKey => $registrationNo,
            $chatKey => $chatId,
        ];

        $client = Http::timeout($timeout)->acceptJson()->asJson()->withHeaders($headers);

        if ($method === 'GET') {
            return $client->get($url, $payload);
        }

        return $client->post($url, $payload);
    }
}
