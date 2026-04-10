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
        Schema::table('chat_feedbacks', function (Blueprint $table) {
            $table->string('inquiry_type')->nullable()->after('description');
            $table->integer('inquiry_id')->nullable();
            $table->string('inquiry_name',191)->nullable();
            $table->string('registration')->nullable();
            $table->string('status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_feedbacks', function (Blueprint $table) {
            //
        });
    }
};
