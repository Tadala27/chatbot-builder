<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class SystemUser extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use HasRoles;
    use SoftDeletes;

    protected $connection = 'landlord';
    protected $table = 'users';
    protected $guard_name = 'system';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'avatar',
        'timezone',
        'locale',
        'last_login',
        'locked_until',
        'failed_login_attempts',
        'password_reset_required',
        'email_verified_at',
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
        'failed_login_attempts' => 'integer',
    ];

    public function getDefaultGuardName(): string
    {
        return 'system';
    }
}
