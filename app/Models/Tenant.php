<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\DatabaseConfig;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'db_schema',
            'deployment_mode',
            'slug',
            'is_active',
            'subscription_tier',
            'subscription_expires_at',
            'max_bots',
            'max_conversations_per_month',
        ];
    }

    protected $fillable = [
        'id',
        'name',
        'db_schema',
        'deployment_mode',                         // data bag
        'slug',
        'is_active',
        'subscription_tier',
        'subscription_expires_at',
        'max_bots',
        'max_conversations_per_month',
        'settings',                     // data bag
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'subscription_expires_at' => 'datetime',
        'settings' => 'array',
        'max_bots' => 'integer',
        'max_conversations_per_month' => 'integer',
    ];

    protected static function booted(): void
    {
        // Override the global DB name generator so ALL tenants use db_schema.
        DatabaseConfig::generateDatabaseNamesUsing(
            fn (TenantWithDatabase $tenant) => $tenant->db_schema
        );

        // When a tenant is loaded, sync db_schema → internal db_name
        static::retrieved(function (self $tenant) {
            if ($tenant->db_schema) {
                $tenant->setInternal('db_name', $tenant->db_schema);
            }
        });

        // When a new tenant is being created, set db_name so DatabaseConfig
        // picks it up. If db_config is set (existing DB), we still set db_name
        // but the TenantObserver::$enabled = false flag prevents auto-provisioning.
        static::creating(function (self $tenant) {
            if ($tenant->db_schema) {
                $tenant->setInternal('db_name', $tenant->db_schema);
            }
        });
    }

    public function primaryDomain(): ?Domain
    {
        return $this->domains()->where('is_primary', true)->first();
    }

    // ── Tenant DB relationships ───────────────────────────────────────────────
    // All of these live in the tenant's own database.
    // Only call them inside an initialized tenant context.

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsappAccount::class);
    }

    public function facebookBusinessAccounts(): HasMany
    {
        return $this->hasMany(FacebookBusinessAccount::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function globalVariables(): HasMany
    {
        return $this->hasMany(GlobalVariable::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function outgoingWebhooks(): HasMany
    {
        return $this->hasMany(OutgoingWebhook::class);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isSubscriptionActive(): bool
    {
        return $this->subscription_expires_at === null
            || $this->subscription_expires_at->isFuture();
    }

    public function canCreateBots(): bool
    {
        return $this->bots()->count() < $this->max_bots;
    }

    public function getRemainingBots(): int
    {
        return max(0, $this->max_bots - $this->bots()->count());
    }

    public function getConversationsThisMonth(): int
    {
        return $this->conversations()
            ->whereYear('started_at', now()->year)
            ->whereMonth('started_at', now()->month)
            ->count();
    }

    public function hasReachedConversationLimit(): bool
    {
        return $this->getConversationsThisMonth() >= $this->max_conversations_per_month;
    }

    public function getUsagePercentage(): float
    {
        if ($this->max_conversations_per_month == 0) {
            return 0;
        }

        $used = $this->conversations()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return min(100, ($used / $this->max_conversations_per_month) * 100);
    }
}