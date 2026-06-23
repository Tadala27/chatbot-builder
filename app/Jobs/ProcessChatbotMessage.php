<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Bot\ChatbotFlowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Serialize all processing for a single conversation so messages
     * can never be processed out of order or concurrently.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("conversation-{$this->conversation->id}"))
                ->expireAfter(120)       // release if job crashes without cleanup
                ->dontRelease(),         // if locked, throw — don't retry silently
        ];
    }

    /**
     * Unique lock key (Laravel uses this to prevent dispatch of duplicate jobs
     * for the same message).
     */
    public function uniqueId(): string
    {
        return "chatbot-message-{$this->message->id}";
    }

    public int $uniqueFor = 300;

    public function handle(ChatbotFlowExecutor $executor): void
    {
        // Idempotency guard: if processing already finished for this message
        // (from a prior attempt that succeeded right before a crash), bail out.
        $this->message->refresh();
        if ($this->message->processed_at !== null) {
            Log::info('Skipping already-processed message', [
                'message_id'     => $this->message->id,
                'processed_at'   => $this->message->processed_at,
            ]);
            return;
        }

        Log::info('Processing chatbot message', [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversation->id,
            'message_id'      => $this->message->id,
        ]);

        try {
            $executor->processMessage($this->conversation, $this->message);

            // Mark processed so retries don't re-run the flow.
            $this->message->update(['processed_at' => now()]);

            Log::info('Chatbot message processed successfully', [
                'conversation_id' => $this->conversation->id,
                'message_id'      => $this->message->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process chatbot message', [
                'conversation_id' => $this->conversation->id,
                'message_id'      => $this->message->id,
                'error'           => $e->getMessage(),
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