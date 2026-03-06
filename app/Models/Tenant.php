<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Tenant extends SpatieTenant
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'database',
        'is_active',
        'subscription_tier',
        'subscription_expires_at',
        'max_flows',
        'max_conversations_per_month',
        'settings',
    ];

    protected $casts = [
        'is_active'                => 'boolean',
        'subscription_expires_at'  => 'datetime',
        'settings'                 => 'array',
        'max_flows'                => 'integer',
        'max_conversations_per_month' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'is_active', 'subscription_tier'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }


    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot('is_primary', 'joined_at')
            ->withTimestamps();
    }

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


    // ─── Business Logic ───────────────────────────────────────────────────────

    public function isSubscriptionActive(): bool
    {
        return $this->subscription_expires_at === null
            || $this->subscription_expires_at->isFuture();
    }
    public function canCreateFlow(): bool
    {
        return $this->bots()->count() < $this->max_flows;
    }

    public function getRemainingFlows(): int
    {
        return max(0, $this->max_flows - $this->bots()->count());
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
        $used = $this->conversations()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        if ($this->max_conversations_per_month == 0) {
            return 0;
        }

        return min(100, ($used / $this->max_conversations_per_month) * 100);
    }
}
