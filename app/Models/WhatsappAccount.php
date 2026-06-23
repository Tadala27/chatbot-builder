<?php

// app/Models/WhatsappAccount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WhatsappAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'waba_id',
        'phone_number_id',
        'phone_number',
        'display_phone_number',
        'verified_name',
        'quality_rating',
        'messaging_limit',
        'access_token', // nullable now — only set for managed_bot accounts
        'webhook_verify_token',
        'is_active',
        'last_synced_at',
        'metadata',
        // ── connector additions ──
        'mode',
        'webhook_url',
        'webhook_secret',
    ];

    protected $hidden = [
        'access_token',
        'webhook_verify_token',
        'connector_api_key',
        'webhook_secret',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
        'connector_api_key_rotated_at' => 'datetime',
        'webhook_last_failed_at' => 'datetime',
    ];

    // ── Connector mode helpers ──────────────────────────────────────────────

    public function isConnectorMode(): bool
    {
        return $this->mode === 'connector';
    }

    public function isManagedBotMode(): bool
    {
        return $this->mode === 'managed_bot';
    }

    /**
     * managed_bot accounts always have an access_token (from embedded
     * signup). connector accounts never do — they use the platform's own
     * Tech Provider token instead. Use this helper anywhere you'd
     * previously assumed access_token is always present.
     */
    public function hasOwnAccessToken(): bool
    {
        return !empty($this->access_token);
    }

    public function rotateConnectorApiKey(): string
    {
        $key = 'wac_'.Str::random(48);

        $this->forceFill([
            'connector_api_key' => $key,
            'connector_api_key_rotated_at' => now(),
        ])->save();

        return $key;
    }

    public function isHealthy(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->quality_rating === 'RED') {
            return false;
        }

        if ($this->isConnectorMode() && $this->webhook_failure_count >= 5) {
            return false;
        }

        return true;
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function connectorLogs(): HasMany
    {
        return $this->hasMany(ConnectorMessageLog::class);
    }

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
