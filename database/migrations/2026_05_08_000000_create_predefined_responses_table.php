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
        Schema::create('predefined_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('response');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('predefined_responses');
    }
};
