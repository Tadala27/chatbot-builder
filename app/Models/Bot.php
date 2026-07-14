<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bot extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'whatsapp_account_id',
        'name',
        'description',
        'is_active',
        'default_language',
        'supported_languages',
        'settings',
        'current_published_version_id',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supported_languages' => 'array',
        'settings' => 'array',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(BotVersion::class, 'current_published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BotVersion::class);
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(BotDialog::class);
    }

    public function configuration(): HasOne
    {
        return $this->hasOne(BotConfiguration::class);
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(BotMediaFile::class);
    }

    public function botDialogForPurpose(string $purpose): ?BotDialog
    {
        return $this->dialogs()->where('purpose', $purpose)->first();
    }

    public function getConfigOrCreate(): BotConfiguration
    {
        return $this->configuration()->firstOrCreate(
            ['bot_id' => $this->id]
        );
    }

    public function draftVersion(): ?BotVersion
    {
        return $this->versions()
            ->where('status', 'draft')
            ->latest('version_number')
            ->first();
    }

    public function publishedVersion(): ?BotVersion
    {
        return $this->versions()
            ->where('status', 'published')
            ->latest('version_number')
            ->first();
    }

    public function isPublished(): bool
    {
        return !is_null($this->current_published_version_id);
    }

    public function getPublishedVersion(): ?BotVersion
    {
        return $this->currentPublishedVersion;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}