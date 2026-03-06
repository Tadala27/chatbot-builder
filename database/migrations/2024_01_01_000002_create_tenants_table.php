<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->unique()->nullable();
            $table->string('database')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('subscription_tier', ['free', 'starter', 'professional', 'enterprise'])->default('free');
            $table->timestamp('subscription_expires_at')->nullable();
            $table->integer('max_flows')->default(3);
            $table->integer('max_conversations_per_month')->default(1000);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
