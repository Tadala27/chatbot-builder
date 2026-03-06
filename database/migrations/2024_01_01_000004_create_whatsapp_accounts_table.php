<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id')->unique();
            $table->string('phone_number_id')->unique();
            $table->string('phone_number', 20);
            $table->string('display_phone_number', 20)->nullable();
            $table->string('verified_name')->nullable();
            $table->enum('quality_rating', ['GREEN', 'YELLOW', 'RED', 'UNKNOWN'])->default('UNKNOWN');
            $table->enum('messaging_limit', ['TIER_1K', 'TIER_10K', 'TIER_100K', 'TIER_UNLIMITED'])->default('TIER_1K');
            $table->text('access_token');
            $table->string('webhook_verify_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
