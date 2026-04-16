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
            $table->timestamp('visitor_last_read_at')->nullable()->after('agent_last_read_at')->index();
        });

        DB::statement("
            UPDATE chats
            SET visitor_last_read_at = COALESCE(visitor_last_read_at, last_message_at, created_at)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['visitor_last_read_at']);
            $table->dropColumn('visitor_last_read_at');
        });
    }
};

