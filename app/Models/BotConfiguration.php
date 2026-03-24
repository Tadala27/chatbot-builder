<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BotConfiguration extends Model
{
    protected $fillable = [
        'bot_id',
        'tenant_id',
        'starting_dialog_id',
        'welcome_dialog_id',
        'session_timeout_minutes',
        'invalid_input_message',
        'invalid_input_dialog_id',
        'max_invalid_attempts',
        'invalid_attempts_dialog_id',
        'retry_enabled',
        'retry_dialog_id',
        'retry_after_minutes',
        'max_retry_attempts',
        'home_keywords',
        'back_keywords',
        'handover_keywords',
        'opt_out_keywords',
        'opt_in_keywords',
        'handover_enabled',
        'handover_dialog_id_in_hours',
        'handover_dialog_id_off_hours',
        'handover_unavailable_message',
        'auto_resolve_after_minutes',
        'operating_hours',
    ];

    protected $casts = [
        'home_keywords'     => 'array',
        'back_keywords'     => 'array',
        'handover_keywords' => 'array',
        'opt_out_keywords'  => 'array',
        'opt_in_keywords'   => 'array',
        'operating_hours'   => 'array',
        'retry_enabled'     => 'boolean',
        'handover_enabled'  => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function startingDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'starting_dialog_id');
    }

    public function welcomeDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'welcome_dialog_id');
    }

    public function invalidInputDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'invalid_input_dialog_id');
    }

    public function invalidAttemptsDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'invalid_attempts_dialog_id');
    }

    public function retryDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'retry_dialog_id');
    }

    public function handoverInHoursDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'handover_dialog_id_in_hours');
    }

    public function handoverOffHoursDialog(): BelongsTo
    {
        return $this->belongsTo(BotDialog::class, 'handover_dialog_id_off_hours');
    }
}