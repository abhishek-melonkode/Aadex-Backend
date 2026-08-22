<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a login record to the Sanctum token it issued, so `GET /auth/sessions`
 * can show the IP and user agent behind each active session. Nullable and
 * nullOnDelete: revoking a token must not delete the audit row, it just
 * detaches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_activity_logs', function (Blueprint $table) {
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->after('user_id')
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('login_activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
        });
    }
};
