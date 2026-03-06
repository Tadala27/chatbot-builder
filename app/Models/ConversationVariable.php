<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'custom_variable_id', 'key', 'value',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** The bot-level variable schema this value belongs to (nullable) */
    public function customVariable(): BelongsTo
    {
        return $this->belongsTo(CustomVariable::class);
    }
}
