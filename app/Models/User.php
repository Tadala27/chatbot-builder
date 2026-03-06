<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity, CausesActivity;

    protected $fillable = [
        'name', 'email', 'password',
        'is_active', 'is_super_admin', 'avatar', 'timezone', 'locale',
        'last_login', 'locked_until', 'failed_login_attempts', 'password_reset_required',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'       => 'datetime',
        'last_login'              => 'datetime',
        'locked_until'            => 'datetime',
        'password'                => 'hashed',
        'is_active'               => 'boolean',
        'is_super_admin'          => 'boolean',
        'password_reset_required' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_super_admin'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
                    ->withPivot('is_primary', 'joined_at')
                    ->withTimestamps();
    }

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function createdFlowVersions(): HasMany
    {
        return $this->hasMany(FlowVersion::class, 'created_by');
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
    }

    public function agentHandoverLogs(): HasMany
    {
        return $this->hasMany(AgentHandoverLog::class, 'assigned_agent_id');
    }

    // Helper methods
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function hasAccessToTenant(Tenant $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->tenants->contains($tenant->id);
    }

    public function primaryTenant(): ?Tenant
    {
        return $this->tenants()->wherePivot('is_primary', true)->first();
    }
     public function incrementFailedLogin()
    {
        $this->failed_login_attempts++;
        $this->save();
    }

    public function lockAccount(int $minutes = 15)
    {
        $this->is_active = false;
        $this->locked_until = Carbon::now()->addMinutes($minutes);
        $this->failed_login_attempts = 0;
        $this->save();
    }

    public function unlockAccount()
    {
        $this->locked_until = null;
        $this->failed_login_attempts = 0;
        $this->save();
    }

    public function isLocked(): bool
    {
        return $this->locked_until && Carbon::now()->lt($this->locked_until);
    }

    public function resetFailedAttempts()
    {
        $this->failed_login_attempts = 0;
        $this->save();
    }
}
