<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookBusinessAccount extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'fb_business_id', 'fb_user_id',
        'access_token', 'token_expires_at', 'scopes',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
