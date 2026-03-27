<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});



// Route::get('/chat', function () {
//     if (!session()->has('visitor_id')) {
//         session(['visitor_id' => \Str::uuid()]);
//     }
//     return Inertia::render('VisitorChat'); 
// });


Route::get('/chat', [ChatController::class, 'visitorChat']);

Route::post('/send-message', [ChatController::class, 'sendMessage']);
Route::post('/chat/ping', [ChatController::class, 'ping']);

Route::middleware(['auth'])->group(function () {
    Route::get('/agent/chats', [AgentController::class, 'index'])->name('agent.chats');
    Route::get('/agent/chats/{chat}', [AgentController::class, 'show'])->name('agent.chat.show');
    Route::post('/agent/chats/{chat}/read', [AgentController::class, 'markRead'])->name('agent.chat.read');
    Route::delete('/agent/chats/{chat}', [AgentController::class, 'destroy'])->name('agent.chat.destroy');
});


Route::post('/visitor-chat/create', [ChatController::class, 'getOrCreateChat']);
// Route::post('/send-message', [ChatController::class, 'sendMessage']);

require __DIR__.'/auth.php';
