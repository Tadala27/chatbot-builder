<?php


namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Dialog;
use App\Services\Bot\ChatbotFlowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContinueChatbotFlow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;
    public int $tenantId;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Dialog       $nextDialog,
    ) {
        $this->tenantId = $conversation->tenant_id;
        $this->onQueue('chatbot');
    }

    /**
     * Share the same lock key as ProcessChatbotMessage so a delay continuation
     * cannot execute while an inbound message is being processed (or vice versa).
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("conversation-{$this->conversation->id}"))
                ->expireAfter(120)
                ->releaseAfter(15),    // delayed continuation can wait 15s for a slot
        ];
    }

    public function handle(ChatbotFlowExecutor $executor): void
    {
        Log::info('Continuing chatbot flow after delay', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'next_dialog_id'  => $this->nextDialog->id,
        ]);

        // Refresh status since the conversation may have ended since dispatch
        $this->conversation->refresh();

        if (!$this->conversation->status->acceptsMessages() &&
            !$this->conversation->status instanceof \App\States\HandedOff) {
            return;
        }

        try {
            $executor->executeDialogFlow($this->nextDialog, $this->conversation);
        } catch (\Exception $e) {
            Log::error('Failed to continue chatbot flow', [
                'conversation_id' => $this->conversation->id,
                'next_dialog_id'  => $this->nextDialog->id,
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Continue flow job failed permanently', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'next_dialog_id'  => $this->nextDialog->id,
            'error'           => $e->getMessage(),
        ]);
    }
}