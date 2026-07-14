<?php

namespace App\Models;

use App\States\ConversationState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

class Conversation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'bot_id',
        'bot_version_id',
        'whatsapp_account_id',
        'whatsapp_user_phone',
        'whatsapp_user_name',
        'status',
        'state',
        'assigned_agent_id',
        'started_at',
        'ended_at',
        'last_message_at',
        'message_count',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
        'metadata' => 'array',
        'status' => ConversationState::class,
        'state' => ConversationState::class,
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function botVersion(): BelongsTo
    {
        return $this->belongsTo(BotVersion::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at');
    }

    public function variables(): HasMany
    {
        return $this->hasMany(ConversationVariable::class);
    }

    public function variableLogs(): HasMany
    {
        return $this->hasMany(ConversationVariableLog::class);
    }

    public function context(): HasOne
    {
        return $this->hasOne(ConversationContext::class)->latest();
    }

    public function agentHandoverLogs(): HasMany
    {
        return $this->hasMany(AgentHandoverLog::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(BotExecutionLog::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latest('sent_at');
    }

    // ── Version Management ──────────────────────────────────────────────────

    public function upgradeToLatestVersion(): bool
    {
        if (!$this->bot_id) {
            return false;
        }

        $latestVersion = BotVersion::where('bot_id', $this->bot_id)
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        if (!$latestVersion) {
            return false;
        }

        if ($this->bot_version_id === $latestVersion->id) {
            return false;
        }

        $oldVersionId = $this->bot_version_id;

        $this->update([
            'bot_version_id' => $latestVersion->id,
        ]);

        $context = $this->context;
        if ($context) {
            $context->update([
                'last_dialog_id' => null,
                'dialog_history' => [],
            ]);
        }

        Log::info('[Conversation] Upgraded to latest bot version', [
            'conversation_id' => $this->id,
            'old_version_id' => $oldVersionId,
            'new_version_id' => $latestVersion->id,
            'bot_id' => $this->bot_id,
        ]);

        return true;
    }

    public function isUsingOutdatedVersion(): bool
    {
        if (!$this->bot_id || !$this->bot_version_id) {
            return false;
        }

        $newerVersion = BotVersion::where('bot_id', $this->bot_id)
            ->where('status', 'published')
            ->where('id', '!=', $this->bot_version_id)
            ->where('published_at', '>', function ($query) {
                $query->select('published_at')
                    ->from('bot_versions')
                    ->where('id', $this->bot_version_id)
                    ->limit(1);
            })
            ->exists();

        return $newerVersion;
    }

    // ── State Helpers ──────────────────────────────────────────────────────

    public function duration(): ?int
    {
        if (!$this->ended_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->ended_at);
    }

    public function isActive(): bool
    {
        return $this->state instanceof \App\States\Active;
    }

    public function isCompleted(): bool
    {
        return $this->state instanceof \App\States\Completed;
    }

    public function isAbandoned(): bool
    {
        return $this->state instanceof \App\States\Abandoned;
    }

    public function isHandedOff(): bool
    {
        return $this->state instanceof \App\States\HandedOff;
    }

    public function canAcceptMessages(): bool
    {
        return $this->state->acceptsMessages();
    }

    // ── Transition Helpers ─────────────────────────────────────────────────

    public function complete(): void
    {
        if (!$this->isCompleted()) {
            $this->state->transitionTo(\App\States\Completed::class);
            $this->save();
        }
    }

    public function abandon(): void
    {
        if (!$this->isAbandoned()) {
            $this->state->transitionTo(\App\States\Abandoned::class);
            $this->save();
        }
    }

    /**
     * Hand off the conversation to a human agent.
     * Only transitions if not already handed off.
     */
    public function handOff(?string $sourceDialogId = null, ?string $resumeAt = null, ?string $agentId = null): void
    {
        // Skip if already handed off
        if ($this->isHandedOff()) {
            Log::info('[Conversation] Already handed off, skipping transition', [
                'conversation_id' => $this->id,
            ]);

            // Still update metadata if needed
            $metadata = $this->metadata ?? [];
            if (!isset($metadata['handoff_at'])) {
                $this->update([
                    'assigned_agent_id' => $agentId,
                    'metadata' => array_merge($metadata, [
                        'handoff_source_dialog' => $sourceDialogId,
                        'handoff_resume_at' => $resumeAt,
                        'handoff_at' => now()->toISOString(),
                        'handoff_updated_at' => now()->toISOString(),
                    ]),
                ]);
            }

            return;
        }

        // Only transition if not already handed off
        $this->state->transitionTo(\App\States\HandedOff::class);
        $this->update([
            'assigned_agent_id' => $agentId,
            'metadata' => array_merge($this->metadata ?? [], [
                'handoff_source_dialog' => $sourceDialogId,
                'handoff_resume_at' => $resumeAt,
                'handoff_at' => now()->toISOString(),
            ]),
        ]);

        Log::info('[Conversation] Handed off', [
            'conversation_id' => $this->id,
            'source_dialog' => $sourceDialogId,
            'agent_id' => $agentId,
        ]);
    }

    /**
     * Reactivate a conversation (agent returns to bot).
     * Only transitions if not already active.
     */
    public function reactivate(): void
    {
        if ($this->isActive()) {
            Log::info('[Conversation] Already active, skipping reactivation', [
                'conversation_id' => $this->id,
            ]);

            return;
        }

        $this->state->transitionTo(\App\States\Active::class);
        $this->save();
    }

    public function getDuration(): ?int
    {
        return $this->duration();
    }

    public function getFormattedDuration(): ?string
    {
        $seconds = $this->duration();

        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }

    public function getCurrentDialogId(): ?string
    {
        return $this->context?->last_dialog_id;
    }

    public function setCurrentDialogId(?string $dialogId): void
    {
        $ctx = $this->context ?? $this->context()->create([
            'variables' => [],
            'dialog_history' => [],
            'expires_at' => now()->addHours(24),
        ]);
        $ctx->update(['last_dialog_id' => $dialogId]);
    }
}
