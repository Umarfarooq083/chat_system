<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CheckChatToken;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatWidgetController;

Route::post('/chat', [ChatController::class, 'externalCreateChat'])
    ->middleware(CheckChatToken::class);

Route::post('/message', [ChatController::class, 'externalSendMessage'])
    ->middleware(CheckChatToken::class);

Route::post('/chat/ping', [ChatController::class, 'ping'])
    ->middleware(CheckChatToken::class);

Route::prefix('widget')->middleware('throttle:60,1')->group(function () {
    Route::post('/chat', [ChatWidgetController::class, 'createChat']);
    Route::post('/message', [ChatWidgetController::class, 'sendMessage']);
    Route::get('/messages', [ChatWidgetController::class, 'messages']);
});
