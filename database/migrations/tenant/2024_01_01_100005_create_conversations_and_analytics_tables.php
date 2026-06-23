<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations & analytics — runs inside EACH TENANT's database.
 *
 * Tables (in dependency order):
 *   1. conversations            — one row per user session through a flow
 *   2. messages                 — inbound & outbound WhatsApp messages
 *   3. conversation_variables   — live variable values for an active conversation
 *   4. conversation_variable_logs — append-only change history for variables
 *   5. conversation_contexts    — serialised runtime context (last dialog, expiry)
 *   6. agent_handover_logs      — records when/to whom a conversation was handed off
 *   7. flow_execution_logs      — per-event execution trace (success/failure/timing)
 *   8. analytics_events         — aggregatable event stream for reporting
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Conversations ──────────────────────────────────────────────
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
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
            // Composite for race-condition-safe active-conversation lookups
            $table->index(
                ['whatsapp_account_id', 'whatsapp_user_phone', 'status'],
                'conversations_account_phone_status_idx'
            );
        });

        // ── 2. Messages ───────────────────────────────────────────────────
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_message_id')->unique()->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', [
                'text', 'image', 'video', 'audio',
                'document', 'location', 'interactive', 'template',
            ]);
            $table->json('content');
            $table->enum('status', ['sent', 'delivered', 'read', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            // Metadata: sender_type/sender_id/sender_name for agent messages + other context
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'direction']);
            $table->index(['conversation_id', 'processed_at']);
        });

        // ── 3. Conversation variables (live state) ────────────────────────
        Schema::create('conversation_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_variable_id')
                  ->nullable()
                  ->constrained('custom_variables')
                  ->nullOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('value_type', 20)
                  ->default('string')
                  ->comment('string|number|boolean|json|datetime|null');
            $table->timestamps();

            $table->unique(['conversation_id', 'key'], 'conv_vars_unique');
        });

        // ── 4. Conversation variable change log (append-only) ─────────────
        Schema::create('conversation_variable_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // ── 5. Conversation contexts (serialised runtime state) ───────────
        Schema::create('conversation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->json('variables')->nullable();
            $table->unsignedBigInteger('last_dialog_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('expires_at');
        });

        // ── 6. Agent handover log ─────────────────────────────────────────
        Schema::create('agent_handover_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_agent_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // ── 7. Flow execution log ─────────────────────────────────────────
        Schema::create('flow_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });

        // ── 8. Analytics events ───────────────────────────────────────────
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('event_type', [
                'conversation_started',
                'conversation_completed',
                'conversation_abandoned',
                'dialog_entered',
                'dialog_completed',
                'condition_evaluated',
                'function_executed',
                'api_called',
                'handoff_initiated',
                'error_occurred',
            ]);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['flow_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('flow_execution_logs');
        Schema::dropIfExists('agent_handover_logs');
        Schema::dropIfExists('conversation_contexts');
        Schema::dropIfExists('conversation_variable_logs');
        Schema::dropIfExists('conversation_variables');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
