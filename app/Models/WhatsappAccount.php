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
        'tenant_id',
        'waba_id',
        'phone_number_id',
        'phone_number',
        'display_phone_number',
        'verified_name',
        'quality_rating',
        'messaging_limit',
        'access_token',
        'is_active',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'access_token',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function flows()
    {
        return $this->hasMany(Flow::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function getTotalConversations(): int
    {
        return $this->conversations()->count();
    }

    public function getActiveConversations(): int
    {
        return $this->conversations()->where('status', 'active')->count();
    }
}