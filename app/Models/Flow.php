<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bot_id', 'name', 'description', 'slug', 'status',
        'current_published_version_id', 'is_active', 'published_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'published_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'current_published_version_id');
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

    public function draftVersion(): ?FlowVersion
    {
        return $this->versions()->where('status', 'draft')->latest()->first();
    }

    public function publishedVersion(): ?FlowVersion
    {
        return $this->currentPublishedVersion;
    }
}
