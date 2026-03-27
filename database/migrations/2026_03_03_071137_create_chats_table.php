<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id')->index();
            $table->integer('assigned_agent_id')->nullable()->index();
            $table->integer('ip_address')->nullable();
            $table->string('website')->nullable();
            $table->string('website_slug')->nullable();
            $table->string('country')->nullable();
            $table->string('status')->default('close');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
