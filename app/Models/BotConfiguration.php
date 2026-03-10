<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'starting_dialog_id',
        'invalid_input_dialog_id',
        'retry_dialog_id',
        'retry_enabled',
        'retry_after_minutes',
        'max_retry_attempts',
        'home_keywords',
        'back_keywords',
        'handover_keywords',
        'handover_enabled',
        'handover_dialog_id_in_hours',
        'handover_dialog_id_off_hours',
        'session_timeout_minutes',
        'operating_hours',
    ];

    protected $casts = [
        'retry_enabled'      => 'boolean',
        'handover_enabled'   => 'boolean',
        'home_keywords'      => 'array',
        'back_keywords'      => 'array',
        'handover_keywords'  => 'array',
        'operating_hours'    => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function startingDialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'starting_dialog_id');
    }

    public function invalidInputDialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'invalid_input_dialog_id');
    }

    public function retryDialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'retry_dialog_id');
    }

    public function handoverDialogInHours(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'handover_dialog_id_in_hours');
    }

    public function handoverDialogOffHours(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'handover_dialog_id_off_hours');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Default operating hours structure — all days enabled 08:00-17:00 UTC.
     * Used when initialising a new configuration.
     */
    public static function defaultOperatingHours(): array
    {
        $days = [];
        foreach (range(0, 6) as $d) {
            $days[(string)$d] = [
                'enabled'  => ($d >= 1 && $d <= 5), // Mon-Fri on by default
                'open'     => '08:00',
                'close'    => '17:00',
                'timezone' => 'UTC',
            ];
        }
        return $days;
    }

    /**
     * Is the bot currently within operating hours?
     * Returns true when operating_hours is null (no restriction configured).
     */
    public function isWithinOperatingHours(): bool
    {
        if (empty($this->operating_hours)) return true;

        $dayOfWeek = (string) now()->dayOfWeek; // 0=Sun … 6=Sat
        $day       = $this->operating_hours[$dayOfWeek] ?? null;

        if (!$day || empty($day['enabled'])) return false;

        $tz   = $day['timezone'] ?? 'UTC';
        $now  = now()->setTimezone($tz);
        $open  = \Carbon\Carbon::createFromTimeString($day['open'],  $tz);
        $close = \Carbon\Carbon::createFromTimeString($day['close'], $tz);

        return $now->between($open, $close);
    }
}
