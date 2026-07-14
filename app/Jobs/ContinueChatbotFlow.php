<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Dialog;
use App\Models\Tenant;
use App\Services\Bot\ChatbotFlowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FIXED — identical root cause as ProcessChatbotMessage: SerializesModels
 * + a $conversation->tenant_id read (column doesn't exist) + no tenancy
 * re-init in handle(). This job is used for delayed flow continuations
 * (a 'delay' action's queued resume), so it would fail the exact same way
 * — die during unserialization in the worker process, never reach
 * handle() — the moment any flow actually used a delay step.
 *
 * Same fix shape as ProcessChatbotMessage: scalar IDs only in the
 * constructor, dispatchFor() captures tenant() at the correct moment
 * (call site is wherever the delay action schedules this — that call
 * happens inside ChatbotFlowExecutor, itself only ever running inside an
 * already-tenancy-initialized context), re-initialize tenancy as the
 * first thing handle() does, fetch Conversation AND Dialog only after that.
 */
class ContinueChatbotFlow implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $nextDialogId,
    ) {
        $this->onQueue('chatbot');
    }

    /**
     * Call this instead of `new ContinueChatbotFlow($conversation, $dialog)`.
     * Must be called from a context where tenancy is already initialized
     * (true everywhere ChatbotFlowExecutor itself runs, since it's only
     * ever invoked from ProcessChatbotMessage::handle(), which is already
     * inside its own tenancy()->initialize() block).
     */
    public static function dispatchFor(Conversation $conversation, Dialog $nextDialog, int $delaySeconds = 0): void
    {
        $job = self::dispatch(tenant()->id, $conversation->id, $nextDialog->id);

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("conversation-{$this->conversationId}"))
                ->expireAfter(120)
                ->releaseAfter(15),
        ];
    }

    public function handle(ChatbotFlowExecutor $executor): void
    {
        $tenantModel = Tenant::find($this->tenantId);

        if (!$tenantModel || !$tenantModel->is_active) {
            Log::warning('ContinueChatbotFlow: tenant not found or inactive — dropping.', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'next_dialog_id' => $this->nextDialogId,
            ]);

            return;
        }

        tenancy()->initialize($tenantModel);

        try {
            $conversation = Conversation::find($this->conversationId);
            $nextDialog = Dialog::find($this->nextDialogId);

            if (!$conversation || !$nextDialog) {
                Log::warning('ContinueChatbotFlow: conversation or dialog not found in tenant DB.', [
                    'tenant_id' => $this->tenantId,
                    'conversation_id' => $this->conversationId,
                    'next_dialog_id' => $this->nextDialogId,
                    'conversation_found' => $conversation !== null,
                    'dialog_found' => $nextDialog !== null,
                ]);

                return;
            }

            Log::info('Continuing chatbot flow after delay', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $conversation->id,
                'next_dialog_id' => $nextDialog->id,
            ]);

            // Refresh status since the conversation may have ended since dispatch.
            $conversation->refresh();

            if (
                !$conversation->status->acceptsMessages()
                && !$conversation->status instanceof \App\States\HandedOff
            ) {
                return;
            }

            $executor->executeDialogFlow($nextDialog, $conversation);
        } catch (\Exception $e) {
            Log::error('Failed to continue chatbot flow', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'next_dialog_id' => $this->nextDialogId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            tenancy()->end();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Continue flow job failed permanently', [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'next_dialog_id' => $this->nextDialogId,
            'error' => $e->getMessage(),
        ]);
    }
}
