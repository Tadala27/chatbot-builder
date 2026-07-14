<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── 1. Conversations ──────────────────────────────────────────────
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bot_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignUuid('bot_version_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignUuid('whatsapp_account_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('whatsapp_user_phone', 20);
            $table->string('whatsapp_user_name')->nullable();

            $table->enum('status', ['active', 'completed', 'handed_off', 'abandoned'])
                ->default('active');

            $table->foreignUuid('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('state')->default('active');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamp('version_checked_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('version_checked_at');
            $table->index(['whatsapp_user_phone', 'status']);
            $table->index('last_message_at');
            $table->index(['whatsapp_account_id', 'whatsapp_user_phone', 'status'],
                'conversations_account_phone_status_idx');
        });

        // ── 2. Messages ───────────────────────────────────────────────────
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_message_id')->unique()->nullable();
            $table->string('reply_to_wamid')->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', [
                'text', 'image', 'video', 'audio',
                'document', 'location', 'interactive',
                'template', 'button', 'sticker', 'contacts',
            ]);
            $table->json('content');
            $table->enum('status', ['sent', 'delivered', 'read', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('reply_to_wamid');
            $table->index(['conversation_id', 'direction']);
            $table->index(['conversation_id', 'processed_at']);
        });

        // ── 3. Conversation variables (live state) ────────────────────────
        Schema::create('conversation_variables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('custom_variable_id')
                ->nullable()
                ->constrained('custom_variables')
                ->nullOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('value_type', 20)->default('string');
            $table->timestamps();

            $table->unique(['conversation_id', 'key'], 'conv_vars_unique');
        });

        // ── 4. Conversation variable change log ───────────────────────────
        Schema::create('conversation_variable_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // ── 5. Conversation contexts (serialised runtime state) ───────────
        Schema::create('conversation_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->json('variables')->nullable();
            $table->foreignUuid('last_dialog_id')->nullable()->constrained('dialogs')->nullOnDelete();
            $table->json('dialog_history')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('expires_at');
        });

        // ── 6. Agent handover log ─────────────────────────────────────────
        Schema::create('agent_handover_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // ── 7. Bot execution log ──────────────────────────────────────────
        Schema::create('bot_execution_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });

        // ── 8. Analytics events ───────────────────────────────────────────
        // bot_id nullable: connector conversations have no bot
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 50);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bot_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('bot_execution_logs');
        Schema::dropIfExists('agent_handover_logs');
        Schema::dropIfExists('conversation_contexts');
        Schema::dropIfExists('conversation_variable_logs');
        Schema::dropIfExists('conversation_variables');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};