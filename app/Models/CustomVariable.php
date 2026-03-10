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
        'bot_id',
        'name',
        'key',           // {{key}} used in dialog text / actions — immutable after creation
        'data_type',     // string | number | boolean | json | date
        'save_in',       // conversation | user_property | global
        'use_in_js',     // inject into JS CustomFunction sandbox
        'is_sensitive',  // mask value in logs
        'default_value',
        'description',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
        'use_in_js'    => 'boolean',
    ];


    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }


    public function conversationVariables(): HasMany
    {
        return $this->hasMany(ConversationVariable::class);
    }


    public function placeholder(): string
    {
        return '{{' . $this->key . '}}';
    }

    public function castValue(mixed $raw): mixed
    {
        return match ($this->data_type) {
            'number'  => is_numeric($raw) ? $raw + 0 : $raw,
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $raw,
            'json'    => is_string($raw) ? json_decode($raw, true) : $raw,
            default   => (string) $raw,
        };
    }
}
