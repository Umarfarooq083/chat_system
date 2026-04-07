<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_external_api_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->string('registration_no', 100);
            $table->string('status', 30)->nullable();
            $table->text('error')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_sent_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'registration_no']);
            $table->index(['chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_external_api_fetches');
    }
};

