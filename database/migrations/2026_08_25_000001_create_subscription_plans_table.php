<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('modules_count')->default(0);
            $table->unsignedInteger('ota_enabled_count')->default(0);
            $table->enum('duration', ['monthly', 'quarterly', 'half_yearly', 'yearly']);
            $table->enum('currency', ['inr', 'usd', 'eur']);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
