<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class BotDialog extends Model
{
    protected $fillable = [
        'bot_id',
        'tenant_id',
        'purpose',
        'name',
        'description',
        'kind',
        'config',
        'is_active',
        'is_entry_point',
    ];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
        'is_entry_point' => 'boolean',
    ];

    // ── Well-known purpose constants ──────────────────────────────────────────
    // Use these everywhere instead of bare strings to avoid typos.
    const PURPOSE_INVALID_INPUT     = 'invalid_input';
    const PURPOSE_INVALID_ESCALATE  = 'invalid_escalate';
    const PURPOSE_RETRY_NUDGE       = 'retry_nudge';
    const PURPOSE_HANDOVER_IN_HOURS = 'handover_in_hours';
    const PURPOSE_HANDOVER_OFF_HOURS = 'handover_off_hours';
    const PURPOSE_SESSION_EXPIRED   = 'session_expired';
    const PURPOSE_OPT_OUT_CONFIRM   = 'opt_out_confirm';
    const PURPOSE_OPT_IN_CONFIRM    = 'opt_in_confirm';
    const PURPOSE_WELCOME           = 'welcome';
    const PURPOSE_STARTING          = 'starting';

    // All purposes in display order for the UI
    const ALL_PURPOSES = [
        self::PURPOSE_STARTING          => 'Starting dialog',
        self::PURPOSE_WELCOME           => 'Welcome (first contact)',
        self::PURPOSE_INVALID_INPUT     => 'Invalid input fallback',
        self::PURPOSE_INVALID_ESCALATE  => 'Max invalid attempts escalation',
        self::PURPOSE_RETRY_NUDGE       => 'Re-engagement nudge',
        self::PURPOSE_HANDOVER_IN_HOURS => 'Handover — within hours',
        self::PURPOSE_HANDOVER_OFF_HOURS => 'Handover — off hours',
        self::PURPOSE_SESSION_EXPIRED   => 'Session expired notice',
        self::PURPOSE_OPT_OUT_CONFIRM   => 'Opt-out confirmation',
        self::PURPOSE_OPT_IN_CONFIRM    => 'Opt-in confirmation',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPurpose($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}
