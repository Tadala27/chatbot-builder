<?php

// app/Models/User.php — full replacement

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use HasRoles;
    use SoftDeletes;
    use HasUuids;

    protected $guard_name = 'tenant';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login',
        'password_reset_required', // true after invite — cleared on first reset
        'invited_by',              // user_id of the admin who created this account
        'invited_at',              // when the invitation email was sent
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'invited_at' => 'datetime',
        'is_active' => 'boolean',
        'password_reset_required' => 'boolean',
        'password' => 'hashed',
    ];

    // ── Relations ──────────────────────────────────────────────────────────

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // ── Computed ───────────────────────────────────────────────────────────

    public function getDisplayRoleAttribute(): string
    {
        return $this->roles->pluck('name')->implode(', ') ?: 'No Role';
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}