<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatbotFlowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessChatbotMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    public int $tenantId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Conversation $conversation,
        public Message $message
    ) {
        // Set the tenant ID for the multitenancy package
        $this->tenantId = $conversation->tenant_id;

        $this->onQueue('chatbot');
    }

    /**
     * Execute the job.
     */
    public function handle(ChatbotFlowExecutor $executor): void
    {
        try {
            Log::info('Processing chatbot message', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversation->id,
                'message' => $this->message,
            ]);

            // Execute the flow
            $executor->processMessage($this->conversation, $this->message);

            Log::info('Chatbot message processed successfully', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversation->id,
                'message_id' => $this->message->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process chatbot message', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversation->id,
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Chatbot message processing failed permanently', [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark conversation as having issues
        $this->conversation->update([
            'metadata' => array_merge($this->conversation->metadata ?? [], [
                'last_error' => $exception->getMessage(),
                'last_error_at' => now()->toDateTimeString(),
            ]),
        ]);
    }
}
