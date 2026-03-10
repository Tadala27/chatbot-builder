<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Dialog;
use App\Services\ChatbotFlowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Resumes a delayed flow by executing the target dialog.
 * Dispatched by ActionExecutorService when a 'delay' action is encountered.
 */
class ContinueChatbotFlow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public int $tenantId;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Dialog       $nextDialog,   // Dialog, not FlowNode
    ) {
        $this->tenantId = $conversation->tenant_id;
        $this->onQueue('chatbot');
    }

    public function handle(ChatbotFlowExecutor $executor): void
    {
        Log::info('Continuing chatbot flow after delay', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'next_dialog_id'  => $this->nextDialog->id,
            'flow_id'         => $this->conversation->flow_id,
        ]);

        // Do not execute if the conversation has already ended or been handed off
        if (!in_array($this->conversation->status, ['active', 'handed_off'], true)) {
            Log::info('Conversation is no longer active — skipping delayed execution', [
                'conversation_id' => $this->conversation->id,
                'status'          => $this->conversation->status,
            ]);
            return;
        }

        try {
            $executor->executeDialogFlow($this->nextDialog, $this->conversation);

            Log::info('Chatbot flow continued successfully', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $this->nextDialog->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to continue chatbot flow', [
                'tenant_id'       => $this->tenantId,
                'conversation_id' => $this->conversation->id,
                'next_dialog_id'  => $this->nextDialog->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Continue flow job failed permanently', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'next_dialog_id'  => $this->nextDialog->id,
            'error'           => $exception->getMessage(),
        ]);
    }
}
