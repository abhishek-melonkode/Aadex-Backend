<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('hotel_id')->nullable()->after('id')->constrained('hotels')->nullOnDelete();
            $table->foreignId('chain_id')->nullable()->after('hotel_id')->constrained('hotel_chains')->nullOnDelete();
            $table->string('mobile')->nullable()->after('email');
            $table->string('status')->default('active')->after('mobile');
            $table->timestamp('last_login_at')->nullable();

            $table->index(['hotel_id', 'status']);
            $table->index(['chain_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_id');
            $table->dropConstrainedForeignId('chain_id');
            $table->dropColumn(['mobile', 'status', 'last_login_at']);
        });
    }
};
