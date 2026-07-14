<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WhatsappAccount extends Model
{
    use SoftDeletes;
    use HasUuids;

    protected $fillable = [
        // ── Identity ──────────────────────────────────────────────────────
        'waba_id',
        'phone_number_id',
        'phone_number',
        'display_phone_number',
        'verified_name',

        // ── Auth ─────────────────────────────────────────────────────────
        'access_token',
        'phone_number_pin',
        'webhook_verify_token',

        // ── Onboarding progress ──────────────────────────────────────────
        'onboarding_status',
        'registered_at',

        // ── Mode ──────────────────────────────────────────────────────────
        'mode',
        'webhook_url',
        'connector_api_key',
        'connector_api_key_rotated_at',

        // ── Health / status, synced from Meta ────────────────────────────
        'quality_rating',
        'messaging_limit',
        'health_status',
        'last_synced_at',

        // ── State ─────────────────────────────────────────────────────────
        'is_active',
        'webhook_failure_count',
        'webhook_last_failed_at',
        'metadata',
    ];

    protected $hidden = [
        'access_token',
        'phone_number_pin',
        'webhook_verify_token',
        'connector_api_key',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'registered_at' => 'datetime',
        'connector_api_key_rotated_at' => 'datetime',
        'webhook_last_failed_at' => 'datetime',
        'metadata' => 'array',
        'health_status' => 'array',
    ];

    // ── Mode helpers ──────────────────────────────────────────────────────

    public function isConnectorMode(): bool
    {
        return $this->mode === 'connector';
    }

    public function isManagedBotMode(): bool
    {
        return $this->mode === 'managed_bot';
    }

    /**
     * True once Embedded Signup completed but the tenant hasn't yet picked
     * managed_bot vs connector. UI should route here before anything else.
     */
    public function needsModeSelection(): bool
    {
        return $this->mode === null;
    }

    // ── Onboarding / payment status ─────────────────────────────────────

    public function isPendingPayment(): bool
    {
        return $this->onboarding_status === 'pending_payment';
    }

    public function isFullyOnboarded(): bool
    {
        return $this->onboarding_status === 'active';
    }

    /**
     * Reads the last-synced health_status rollup rather than calling Meta
     * live. Run sync() first if you need this fresh.
     */
    public function canSendMessages(): bool
    {
        return ($this->metadata['can_send_message'] ?? null) === 'AVAILABLE';
    }

    /**
     * Get extra fields from metadata.
     */
    public function getMetadataField(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get phone status from metadata.
     */
    public function getPhoneStatusAttribute()
    {
        return $this->metadata['phone_status'] ?? null;
    }

    /**
     * Get code verification status from metadata.
     */
    public function getCodeVerificationStatusAttribute()
    {
        return $this->metadata['code_verification_status'] ?? null;
    }

    /**
     * Get name status from metadata.
     */
    public function getNameStatusAttribute()
    {
        return $this->metadata['name_status'] ?? null;
    }

    /**
     * Get platform type from metadata.
     */
    public function getPlatformTypeAttribute()
    {
        return $this->metadata['platform_type'] ?? null;
    }

    /**
     * Get throughput level from metadata.
     */
    public function getThroughputLevelAttribute()
    {
        return $this->metadata['throughput_level'] ?? null;
    }

    /**
     * Get account review status from metadata.
     */
    public function getAccountReviewStatusAttribute()
    {
        return $this->metadata['account_review_status'] ?? null;
    }

    /**
     * Get currency from metadata.
     */
    public function getCurrencyAttribute()
    {
        return $this->metadata['currency'] ?? null;
    }

    /**
     * Get timezone ID from metadata.
     */
    public function getTimezoneIdAttribute()
    {
        return $this->metadata['timezone_id'] ?? null;
    }

    // ── Connector API key ────────────────────────────────────────────────

    public function rotateConnectorApiKey(): string
    {
        $key = 'wac_'.Str::random(48);

        $this->forceFill([
            'connector_api_key' => $key,
            'connector_api_key_rotated_at' => now(),
        ])->save();

        return $key;
    }

    // ── Overall health ───────────────────────────────────────────────────

    public function isHealthy(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->isFullyOnboarded()) {
            return false;
        }

        if ($this->quality_rating === 'RED') {
            return false;
        }

        // Check phone status from metadata
        $phoneStatus = $this->getPhoneStatusAttribute();
        if ($phoneStatus === 'RESTRICTED') {
            return false;
        }

        // Check if can send messages
        if (!$this->canSendMessages()) {
            return false;
        }

        if ($this->isConnectorMode() && $this->webhook_failure_count >= 5) {
            return false;
        }

        return true;
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
