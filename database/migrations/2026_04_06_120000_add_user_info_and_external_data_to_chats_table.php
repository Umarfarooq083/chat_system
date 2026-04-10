<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('website_slug');
            $table->string('customer_name')->nullable()->after('phone');
            $table->string('registration_no', 100)->nullable()->after('customer_name');
            $table->string('email')->nullable()->after('registration_no');
            $table->timestamp('user_info_submitted_at')->nullable()->after('email');

            $table->string('external_api_status', 30)->nullable()->after('user_info_submitted_at');
            $table->text('external_api_error')->nullable()->after('external_api_status');
            $table->json('external_api_response')->nullable()->after('external_api_error');
            $table->timestamp('external_api_fetched_at')->nullable()->after('external_api_response');
            $table->timestamp('external_api_pdf_sent_at')->nullable()->after('external_api_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'customer_name',
                'registration_no',
                'email',
                'user_info_submitted_at',
                'external_api_status',
                'external_api_error',
                'external_api_response',
                'external_api_fetched_at',
                'external_api_pdf_sent_at',
            ]);
        });
    }
};

