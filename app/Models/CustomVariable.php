<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id', 'name', 'key', 'data_type',
        'default_value', 'is_sensitive', 'description',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** Conversation-level values for this variable definition */
    public function conversationVariables(): HasMany
    {
        return $this->hasMany(ConversationVariable::class);
    }
}
