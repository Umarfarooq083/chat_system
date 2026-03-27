<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable()->after('last_activity')->index();
            $table->timestamp('agent_last_read_at')->nullable()->after('last_message_at')->index();
        });

        // Backfill for existing data (treat pre-existing chats as "read")
        DB::statement("
            UPDATE chats
            SET last_message_at = (
                SELECT MAX(m.created_at)
                FROM messages m
                WHERE m.chat_id = chats.id
                  AND m.deleted_at IS NULL
            )
            WHERE last_message_at IS NULL
        ");

        DB::statement("
            UPDATE chats
            SET last_message_at = COALESCE(last_message_at, created_at)
        ");

        DB::statement("
            UPDATE chats
            SET agent_last_read_at = COALESCE(agent_last_read_at, last_message_at)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['last_message_at']);
            $table->dropIndex(['agent_last_read_at']);
            $table->dropColumn(['last_message_at', 'agent_last_read_at']);
        });
    }
};

