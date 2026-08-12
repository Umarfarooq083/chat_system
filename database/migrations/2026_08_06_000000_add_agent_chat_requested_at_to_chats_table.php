<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->timestamp('agent_chat_requested_at')->nullable()->after('prechat_submitted_at')->index();
        });

        DB::table('chats')
            ->whereNull('agent_chat_requested_at')
            ->where(function ($query) {
                $query
                    ->whereNotNull('assigned_agent_id')
                    ->orWhereNotNull('first_agent_reply_at')
                    ->orWhereExists(function ($subQuery) {
                        $subQuery
                            ->select(DB::raw(1))
                            ->from('messages')
                            ->whereColumn('messages.chat_id', 'chats.id')
                            ->where('messages.sender_type', 'visitor')
                            ->where(function ($messageQuery) {
                                $messageQuery
                                    ->whereNull('messages.message_type')
                                    ->orWhereNotIn('messages.message_type', [
                                        'prechat_info_response',
                                        'user_info_response',
                                        'cnic_response',
                                    ]);
                            });
                    });
            })
            ->update(['agent_chat_requested_at' => DB::raw('COALESCE(first_visitor_message_at, updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['agent_chat_requested_at']);
            $table->dropColumn('agent_chat_requested_at');
        });
    }
};
