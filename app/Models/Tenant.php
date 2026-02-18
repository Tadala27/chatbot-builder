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
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'is_active',
        'subscription_tier',
        'subscription_expires_at',
        'max_flows',
        'max_conversations_per_month',
        'settings',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'is_active', 'subscription_tier'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $casts = [
        'is_active' => 'boolean',
        'subscription_expires_at' => 'datetime',
        'max_flows' => 'integer',
        'max_conversations_per_month' => 'integer',
        'settings' => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot('is_primary', 'joined_at')
            ->withTimestamps();
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsappAccount::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function globalVariables()
    {
        return $this->hasMany(GlobalVariable::class);
    }

    public function customFunctions()
    {
        return $this->hasMany(CustomFunction::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function apiIntegrations()
    {
        return $this->hasMany(ApiIntegration::class);
    }
    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSubscribed($query)
    {
        return $query->where('subscription_expires_at', '>', now())
            ->orWhereNull('subscription_expires_at');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function isSubscriptionActive(): bool
    {
        return $this->subscription_expires_at === null
            || $this->subscription_expires_at->isFuture();
    }

    public function canCreateFlow(): bool
    {
        return $this->flows()->count() < $this->max_flows;
    }

    public function getRemainingFlows(): int
    {
        return max(0, $this->max_flows - $this->flows()->count());
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