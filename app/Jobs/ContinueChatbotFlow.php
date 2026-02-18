<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\FlowNode;
use App\Services\ChatbotFlowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContinueChatbotFlow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Conversation $conversation,
        public ConversationContext $context,
        public FlowNode $nextNode
    ) {
        $this->onQueue('chatbot');
    }

    /**
     * Execute the job.
     */
    public function handle(ChatbotFlowExecutor $executor): void
    {
        try {
            Log::info('Continuing chatbot flow after delay', [
                'conversation_id' => $this->conversation->id,
                'next_node_id' => $this->nextNode->node_id,
                'flow_id' => $this->conversation->flow_id,
            ]);

            // Check if conversation is still active
            if (!in_array($this->conversation->status, ['active', 'handed_off'])) {
                Log::info('Conversation is no longer active, skipping execution', [
                    'conversation_id' => $this->conversation->id,
                    'status' => $this->conversation->status,
                ]);
                return;
            }

            // Execute next node using reflection to access private method
            $reflection = new \ReflectionClass($executor);
            $method = $reflection->getMethod('executeNode');
            $method->setAccessible(true);
            $method->invoke($executor, $this->conversation, $this->context, $this->nextNode);

            Log::info('Chatbot flow continued successfully', [
                'conversation_id' => $this->conversation->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to continue chatbot flow', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Continue flow job failed permanently', [
            'conversation_id' => $this->conversation->id,
            'next_node_id' => $this->nextNode->node_id,
            'error' => $exception->getMessage(),
        ]);
    }
}