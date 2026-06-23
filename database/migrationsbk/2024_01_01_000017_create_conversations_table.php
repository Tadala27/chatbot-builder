<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();

            $table->string('whatsapp_user_phone', 20);
            $table->string('whatsapp_user_name')->nullable();

            $table->enum('status', ['active', 'completed', 'handed_off', 'abandoned'])
                ->default('active');

            $table->foreignId('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_message_at')->useCurrent();

            $table->integer('message_count')->default(0);
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['whatsapp_user_phone', 'status']);
            $table->index('last_message_at');

            // ✅ NEW (important for performance + race conditions)
            $table->index(
                ['whatsapp_account_id', 'whatsapp_user_phone', 'status'],
                'conversations_account_phone_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};