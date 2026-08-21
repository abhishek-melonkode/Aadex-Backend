<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_id')->nullable()->constrained('hotel_chains')->nullOnDelete();
            $table->string('name');
            $table->string('admin_name')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('subscription_plan_id')->nullable();
            $table->string('ota_status')->default('disconnected');
            $table->string('status')->default('active');
            $table->string('plan_duration')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('gst_tax_id')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('website_slug')->nullable()->unique();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->index(['chain_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
