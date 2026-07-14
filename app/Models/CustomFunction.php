<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomFunction extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'bot_id', 'name', 'slug', 'description', 'function_type',
        'code', 'parameters', 'return_type', 'timeout_seconds', 'is_active',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_active'  => 'boolean',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
