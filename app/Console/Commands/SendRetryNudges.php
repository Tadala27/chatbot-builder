<?php

namespace App\Console\Commands;

use App\Models\BotDialog;
use App\Models\Conversation;
use App\Services\Bot\BotConfigurationRuntime;
use App\Services\Bot\ConversationStateManager;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendRetryNudges extends Command
{
    protected $signature = 'bots:send-retry-nudges';

    protected $description = "Send configured retry nudges to conversations that have gone quiet (BotConfiguration's retry_enabled / retry_after_minutes / max_retry_attempts).";

    public function handle(BotConfigurationRuntime $configRuntime, ConversationStateManager $state): int
    {
        $conversations = Conversation::query()
            ->where('status', 'active')
            ->whereNotNull('bot_id')
            ->whereNotNull('bot_version_id')
            ->with(['bot.configuration', 'botVersion'])
            ->whereHas('bot.configuration', fn ($q) => $q->where('retry_enabled', true))
            ->get();

        $sent = 0;

        foreach ($conversations as $conversation) {
            if ($this->maybeNudge($conversation, $configRuntime, $state)) {
                ++$sent;
            }
        }

        $this->info("Checked {$conversations->count()} active conversation(s) with retry enabled, sent {$sent} nudge(s).");

        return self::SUCCESS;
    }

    private function maybeNudge(
        Conversation $conversation,
        BotConfigurationRuntime $configRuntime,
        ConversationStateManager $state
    ): bool {
        $config = $conversation->bot?->configuration;
        if (!$config?->retry_enabled) {
            return false;
        }

        $retryAfterMinutes = $config->retry_after_minutes ?? 60;
        $maxAttempts = $config->max_retry_attempts ?? 1;

        $retryState = $conversation->metadata['retry'] ?? ['count' => 0, 'last_sent_at' => null];

        if (($retryState['count'] ?? 0) >= $maxAttempts) {
            return false;
        }

        // Space nudges from whichever is more recent: the last real message,
        // or the last nudge we already sent.
        $sinceLast = $retryState['last_sent_at']
            ? Carbon::parse($retryState['last_sent_at'])
            : $conversation->last_message_at;

        if (!$sinceLast || $sinceLast->diffInMinutes(now()) < $retryAfterMinutes) {
            return false;
        }

        if ($config->retry_dialog_id) {
            $dialog = BotDialog::find($config->retry_dialog_id);

            if ($dialog) {
                $version = $conversation->botVersion;
                $sourceFlowDialogId = $version
                    ? $state->getCurrentDialog($version, $conversation)?->id
                    : null;

                $configRuntime->renderConfigDialog($conversation, $dialog, $sourceFlowDialogId);
            }
        }

        $conversation->update([
            'metadata' => array_merge($conversation->metadata ?? [], [
                'retry' => [
                    'count' => ($retryState['count'] ?? 0) + 1,
                    'last_sent_at' => now()->toISOString(),
                ],
            ]),
        ]);

        return true;
    }
}