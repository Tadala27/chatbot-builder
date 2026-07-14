<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BotMediaFile extends Model
{
    use SoftDeletes;
    use HasUuids;

    protected $fillable = [
        'bot_id',
        'tenant_id',
        'user_id',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'url',
        'media_type',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}