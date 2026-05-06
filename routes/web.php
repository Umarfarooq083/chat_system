<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatWidgetController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
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

// Route::get('/dashboard', function () { return Inertia::render('Dashboard'); })->middleware(['auth', 'verified'])->name('dashboard');
Route::get('/dashboard', [AgentController::class, 'dashboard'])->middleware(['auth', 'verified'])->name('dashboard');

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
Route::post('/chat/read', [ChatController::class, 'markVisitorRead']);
Route::get('/attachments/{message}/view', [ChatController::class, 'viewAttachment'])->name('attachments.view');
Route::get('/attachments/{message}/download', [ChatController::class, 'downloadAttachment'])->name('attachments.download');

Route::get('/chat-widget', [ChatWidgetController::class, 'page'])->name('chat-widget.page');

Route::middleware(['auth'])->group(function () {
    Route::get('/agent/chats', [AgentController::class, 'index'])->name('agent.chats');
    Route::get('/agent/reports', [AgentController::class, 'reports'])->name('agent.reports');
    Route::get('/agent/sla-report', [AgentController::class, 'slaReport'])->name('agent.sla-report');
    Route::get('/agent/reports/export', [AgentController::class, 'exportReports'])->name('agent.reports.export');
    Route::get('/agent/chats/poll', [AgentController::class, 'poll'])->name('agent.chats.poll');
    Route::get('/agent/chats/{chat}', [AgentController::class, 'show'])->name('agent.chat.show');
    Route::get('/agent/chats/{chat}/messages', [AgentController::class, 'messages'])->name('agent.chat.messages');
    Route::get('/agent/chats/{chat}/feedbacks', [AgentController::class, 'feedbacks'])->name('agent.chat.feedbacks');
    Route::post('/agent/chats/{chat}/feedbacks', [AgentController::class, 'storeFeedback'])->name('agent.chat.feedbacks.store');
    Route::post('/agent/chats/{chat}/read', [AgentController::class, 'markRead'])->name('agent.chat.read');
    Route::post('/agent/chats/{chat}/close', [AgentController::class, 'close'])->name('agent.chat.close');
    Route::post('/agent/chats/{chat}/transfer', [AgentController::class, 'transfer'])->name('agent.chat.transfer');
    Route::get('/agent/transfer-users', [AgentController::class, 'transferUsers'])->name('agent.transfer.users');
    Route::post('/agent/cnic/lookup', [AgentController::class, 'cnicLookup'])->name('agent.cnic.lookup');
    Route::post('/agent/chats/{chat}/external/fetch', [AgentController::class, 'fetchExternalData'])->name('agent.chat.external.fetch');
    Route::post('/agent/chats/{chat}/external/send-html', [AgentController::class, 'sendExternalHtml'])->name('agent.chat.external.sendHtml');
    Route::post('/agent/chats/{chat}/external/send-pdf', [AgentController::class, 'sendExternalPdf'])->name('agent.chat.external.sendPdf');
    Route::delete('/agent/chats/{chat}', [AgentController::class, 'destroy'])->name('agent.chat.destroy');

    // Company CRUD routes
    Route::resource('companies', CompanyController::class);

    // User CRUD routes
    Route::resource('users', UserController::class);
});

Route::post('/visitor-chat/create', [ChatController::class, 'getOrCreateChat']);
Route::post('/visitor-chat/new', [ChatController::class, 'newChat']);
// Route::post('/send-message', [ChatController::class, 'sendMessage']);

require __DIR__.'/auth.php';

// phone no (required)
// customer name (required)
// registration no (required)
// email
