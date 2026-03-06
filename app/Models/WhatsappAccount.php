<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'waba_id', 'phone_number_id', 'phone_number',
        'display_phone_number', 'verified_name', 'quality_rating',
        'messaging_limit', 'access_token', 'webhook_verify_token',
        'is_active', 'last_synced_at', 'metadata',
    ];

    protected $hidden = ['access_token', 'webhook_verify_token'];

    protected $casts = [
        'is_active'      => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata'       => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
