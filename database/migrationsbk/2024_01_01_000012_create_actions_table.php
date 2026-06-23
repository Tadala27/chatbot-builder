<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | ACTIONS
    |--------------------------------------------------------------------------
    | Reusable actions attached to a dialog node.
    |
    | action_type:
    |   send_message        — send a message to the user
    |   set_variable        — assign a conversation variable
    |   call_api            — trigger an API integration
    |   call_function       — execute a custom function
    |   condition_branch    — branch based on a condition
    |   jump_to_dialog      — jump to another dialog node
    |   handover_to_agent   — transfer conversation to a human agent
    |   send_webhook        — fire an outgoing webhook
    |   end_conversation    — terminate the conversation
    */
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dialog_id')->constrained('dialogs')->cascadeOnDelete();
            $table->foreignId('then_action_id')
                ->nullable()
                ->constrained('actions')
                ->nullOnDelete();
            $table->string('action_type', 50);
            $table->unsignedSmallInteger('action_order')->default(0);
            $table->json('config')->nullable(); // action-specific config payload
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(
                ['dialog_id', 'is_active', 'action_order'],
                'actions_dialog_active_order_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};