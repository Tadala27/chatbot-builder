<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles, LogsActivity, CausesActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login',
        'locked_until',
        'failed_login_attempts',
        'password_reset_required',
        'is_super_admin',
        'avatar',
        'timezone',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'locked_until' => 'datetime',
        'is_active' => 'boolean',
        'password_reset_required' => 'boolean',
        'is_super_admin' => 'boolean',
        'password' => 'hashed',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_super_admin'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('is_primary', 'joined_at')
            ->withTimestamps();
    }

    // CHANGE: Update relationship names
    public function flows()  // instead of chatbots()
    {
        return $this->hasMany(Flow::class, 'created_by');
    }

    public function flowVersions()  // NEW
    {
        return $this->hasMany(FlowVersion::class, 'created_by');
    }

    public function customFunctions()
    {
        return $this->hasMany(CustomFunction::class, 'created_by');
    }

    public function assignedConversations()
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
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

    public function getPrimaryTenant(): ?Tenant
    {
        return $this->tenants()->wherePivot('is_primary', true)->first();
    }

    public function setPrimaryTenant(Tenant $tenant): void
    {
        // Remove primary from all current tenants
        $this->tenants()->updateExistingPivot(
            $this->tenants()->pluck('id')->toArray(),
            ['is_primary' => false]
        );

        // Set new primary
        $this->tenants()->updateExistingPivot($tenant->id, ['is_primary' => true]);
    }

    public function getCurrentTenant(): ?Tenant
    {
        return Tenant::current();
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