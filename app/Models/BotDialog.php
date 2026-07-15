<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotDialog extends Model
{
    use HasUuids;

    // ── Hardcoded purpose constants ───────────────────────────────────────────
    public const PURPOSE_GREETING = 'greeting';
    public const PURPOSE_MAIN_MENU = 'main_menu';
    public const PURPOSE_INVALID_INPUT = 'invalid_input';
    public const PURPOSE_FLOW_INVALID_INPUT = 'flow_invalid_input';
    public const PURPOSE_RETRY = 'retry';
    public const PURPOSE_HANDOVER_IN_HOURS = 'handover_in_hours';
    public const PURPOSE_HANDOVER_OFF_HOURS = 'handover_off_hours';

    /** All reserved purposes the runtime treats specially. */
    public const RESERVED_PURPOSES = [
        self::PURPOSE_GREETING,
        self::PURPOSE_MAIN_MENU,
        self::PURPOSE_INVALID_INPUT,
        self::PURPOSE_FLOW_INVALID_INPUT,
        self::PURPOSE_RETRY,
        self::PURPOSE_HANDOVER_IN_HOURS,
        self::PURPOSE_HANDOVER_OFF_HOURS,
    ];

    // ── Dialog kinds ──────────────────────────────────────────────────────────
    public const KIND_MESSAGE = 'message';
    public const KIND_BUTTONS = 'buttons';
    public const KIND_LIST = 'list';

    protected $fillable = [
        'bot_id',
        'purpose',
        'name',
        'description',
        'kind',
        'is_active',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Is this dialog interactive (has buttons or list rows the user can tap)?
     * Interactive config dialogs pause the conversation and wait for a reply
     * before continuing. Plain message dialogs do not pause.
     */
    public function isInteractive(): bool
    {
        return in_array($this->kind, [self::KIND_BUTTONS, self::KIND_LIST], true);
    }

    /**
     * Find the active BotDialog for a given bot and purpose.
     * Returns null if none is configured — callers must handle gracefully.
     */
    public static function forBot(string $botId, string $purpose): ?self
    {
        return static::where('bot_id', $botId)
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Human-readable label shown in the settings UI for a reserved purpose.
     */
    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_GREETING => 'Greeting',
            self::PURPOSE_MAIN_MENU => 'Main menu',
            self::PURPOSE_INVALID_INPUT => 'Invalid input (config mode)',
            self::PURPOSE_FLOW_INVALID_INPUT => 'Invalid input (flow mode)',
            self::PURPOSE_RETRY => 'Retry',
            self::PURPOSE_HANDOVER_IN_HOURS => 'Handover — in hours',
            self::PURPOSE_HANDOVER_OFF_HOURS => 'Handover — off hours',
            default => $purpose,
        };
    }
}