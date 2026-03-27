<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckChatToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = config('chat.api_token');
        if (!$token || $request->header('X-CHAT-TOKEN') !== $token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
