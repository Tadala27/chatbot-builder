<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
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
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [10, 30, 60];
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {
        $this->onQueue('chatbot');
    }

    public static function dispatchFor(Conversation $conversation, Message $message): void
    {
        self::dispatch(
            tenant()->id,
            $conversation->id,
            $message->id,
        );
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("conversation-{$this->conversationId}"))
                ->expireAfter(120)
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return "chatbot-message-{$this->messageId}";
    }

    public function handle(ChatbotFlowExecutor $executor): void
    {
        Log::info('Processing chatbot message', [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
        ]);

        $tenantModel = Tenant::find($this->tenantId);

        if (!$tenantModel || !$tenantModel->is_active) {
            Log::warning('ProcessChatbotMessage: tenant not found or inactive — dropping.', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        tenancy()->initialize($tenantModel);

        try {
            $conversation = Conversation::find($this->conversationId);
            $message = Message::find($this->messageId);

            if (!$conversation || !$message) {
                Log::warning('ProcessChatbotMessage: conversation or message not found in tenant DB.', [
                    'tenant_id' => $this->tenantId,
                    'conversation_id' => $this->conversationId,
                    'message_id' => $this->messageId,
                    'conversation_found' => $conversation !== null,
                    'message_found' => $message !== null,
                ]);

                return;
            }

            $message->refresh();

            if ($message->processed_at !== null) {
                Log::info('Skipping already-processed message', [
                    'message_id' => $message->id,
                    'processed_at' => $message->processed_at,
                ]);

                return;
            }
            if ($conversation->isUsingOutdatedVersion()) {
                $upgraded = $conversation->upgradeToLatestVersion();
                if ($upgraded) {
                    Log::info('[ProcessChatbotMessage] Upgraded conversation to latest version', [
                        'conversation_id' => $conversation->id,
                        'new_version_id' => $conversation->bot_version_id,
                    ]);
                    $conversation->refresh();
                }
            }

            Log::info('Processing chatbot message', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'bot_version_id' => $conversation->bot_version_id,
            ]);

            $executor->processMessage($conversation, $message);

            $message->update(['processed_at' => now()]);

            Log::info('Chatbot message processed successfully', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process chatbot message', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            tenancy()->end();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Chatbot message processing failed permanently', [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);

        $tenantModel = Tenant::find($this->tenantId);

        if (!$tenantModel) {
            return;
        }

        tenancy()->initialize($tenantModel);

        try {
            $conversation = Conversation::find($this->conversationId);

            $conversation?->update([
                'metadata' => array_merge($conversation->metadata ?? [], [
                    'last_error' => $exception->getMessage(),
                    'last_error_at' => now()->toDateTimeString(),
                ]),
            ]);
        } finally {
            tenancy()->end();
        }
    }
}