<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CheckChatToken;
use App\Http\Controllers\ChatController;

Route::post('/chat', [ChatController::class, 'externalCreateChat'])
    ->middleware(CheckChatToken::class);

Route::post('/message', [ChatController::class, 'externalSendMessage'])
    ->middleware(CheckChatToken::class);

Route::post('/chat/ping', [ChatController::class, 'ping'])
    ->middleware(CheckChatToken::class);
