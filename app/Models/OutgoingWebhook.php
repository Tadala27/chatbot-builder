<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingWebhook extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'bot_id', 'name', 'url',
        'method', 'headers', 'events', 'is_active', 'secret',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'headers'   => 'array',
        'events'    => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}