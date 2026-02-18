<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookBusinessAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'fb_business_id',
        'fb_user_id',
        'access_token',
        'token_expires_at',
        'scopes',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Helper methods
    public function isTokenValid(): bool
    {
        if (!$this->token_expires_at) {
            return true; // Permanent token
        }

        return $this->token_expires_at->isFuture();
    }

    public function needsTokenRefresh(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }

        // Refresh if expiring within 7 days
        return $this->token_expires_at->diffInDays(now()) < 7;
    }

    public function getDecryptedAccessToken(): string
    {
        return decrypt($this->access_token);
    }
}