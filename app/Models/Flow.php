<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Flow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'whatsapp_account_id',
        'name',
        'slug',
        'status',
        'current_published_version_id',
        'default_language',
        'settings',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'published_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function whatsappAccount()
    {
        return $this->belongsTo(WhatsappAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    // ─── Scopes ───────────────────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('status', 'published');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public static function boot()
    {
        parent::boot();

        static::creating(function ($flow) {
            if (empty($flow->slug)) {
                $flow->slug = Str::slug($flow->name);
            }
        });
    }

    public function publish(FlowVersion $version): bool
    {
        // Lock the version
        $version->update(['status' => 'published']);

        // Update flow
        $this->update([
            'status' => 'published',
            'current_published_version_id' => $version->id,
            'published_at' => now(),
        ]);

        return true;
    }

    public function unpublish(): bool
    {
        $this->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        return true;
    }

    public function archive(): bool
    {
        $this->update(['status' => 'archived']);
        return true;
    }

    public function createVersion(array $data = []): FlowVersion
    {
        $latestVersion = $this->versions()->max('version_number') ?? 0;

        return $this->versions()->create(array_merge($data, [
            'version_number' => $latestVersion + 1,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]));
    }

    public function getDraftVersion(): ?FlowVersion
    {
        return $this->versions()->where('status', 'draft')->first();
    }

    public function getPublishedVersion(): ?FlowVersion
    {
        return $this->currentPublishedVersion;
    }

    public function duplicate(string $newName): self
    {
        $newFlow = $this->replicate();
        $newFlow->name = $newName;
        $newFlow->slug = Str::slug($newName);
        $newFlow->status = 'draft';
        $newFlow->current_published_version_id = null;
        $newFlow->published_at = null;
        $newFlow->save();

        // Duplicate latest version
        $latestVersion = $this->versions()->latest('version_number')->first();
        if ($latestVersion) {
            $latestVersion->duplicateToFlow($newFlow);
        }

        return $newFlow;
    }

    public function getTotalConversations(): int
    {
        return $this->conversations()->count();
    }

    public function getActiveConversations(): int
    {
        return $this->conversations()->where('status', 'active')->count();
    }

    public function getCompletionRate(): float
    {
        $total = $this->conversations()->count();
        if ($total === 0) return 0;

        $completed = $this->conversations()->where('status', 'completed')->count();
        return round(($completed / $total) * 100, 2);
    }
}