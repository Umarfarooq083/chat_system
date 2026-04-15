<?php

return [
    // API token that remote sites must provide via X-CHAT-TOKEN header
    // set this in your .env as CHAT_API_TOKEN
    'api_token' => env('CHAT_API_TOKEN'),

    // Widget defaults (used by /chat-widget and public/chat-widget/embed.js)
    'widget_title' => env('CHAT_WIDGET_TITLE', 'Chat'),
    'widget_brand_color' => env('CHAT_WIDGET_BRAND_COLOR', '#111827'),

    // Third-party registration API (used by agent UI)
    // Given cURL example:
    // GET http://webtointr.bwcapp.net/api/chatbwc/ledger?file=34324&test=12
    // Header: token: bgc@123321
    'registration_api_url' => env('LEDGER_API_URL'),
    'registration_api_method' => env('CHAT_REGISTRATION_API_METHOD', 'GET'), // GET or POST
    'registration_api_timeout' => (int) env('CHAT_REGISTRATION_API_TIMEOUT', 15),
    'registration_api_token' => env('LEDGER_API_TOKEN'),
    'registration_api_token_header' => env('CHAT_REGISTRATION_API_TOKEN_HEADER', 'token'),
    'registration_api_query_registration_key' => env('CHAT_REGISTRATION_API_QUERY_REG_KEY', 'file'),
    'registration_api_query_chat_id_key' => env('CHAT_REGISTRATION_API_QUERY_CHAT_KEY', 'test'),
];
