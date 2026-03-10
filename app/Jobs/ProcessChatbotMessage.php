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

    public int $tries   = 3;
    public int $timeout = 60;
    public array $backoff = [10, 30, 60];

    public int $tenantId;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message      $message,
    ) {
        $this->tenantId = $conversation->tenant_id;
        $this->onQueue('chatbot');
    }

    public function handle(ChatbotFlowExecutor $executor): void
    {
        Log::info('Processing chatbot message', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'message_id'      => $this->message->id,
        ]);

        try {
            $executor->processMessage($this->conversation, $this->message);

            Log::info('Chatbot message processed successfully', [
                'tenant_id'       => $this->tenantId,
                'conversation_id' => $this->conversation->id,
                'message_id'      => $this->message->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process chatbot message', [
                'tenant_id'       => $this->tenantId,
                'conversation_id' => $this->conversation->id,
                'message_id'      => $this->message->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Chatbot message processing failed permanently', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'message_id'      => $this->message->id,
            'error'           => $exception->getMessage(),
        ]);

        $this->conversation->update([
            'metadata' => array_merge($this->conversation->metadata ?? [], [
                'last_error'    => $exception->getMessage(),
                'last_error_at' => now()->toDateTimeString(),
            ]),
        ]);
    }
}
