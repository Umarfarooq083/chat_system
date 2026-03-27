<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Chat;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private chat channel - allow agents to listen to messages in their chats
Broadcast::channel('chat.{id}', function ($user, $id) {
    // You can add authorization logic here if needed
    // For now, allow all authenticated users
    return true;
});
