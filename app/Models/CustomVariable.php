<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomVariable extends Model
{
    protected $fillable = [
        'flow_id',
        'name',
        'save_in',
        'use_in_js',
        'is_sensitive',
    ];

    protected $casts = [
        'use_in_js' => 'boolean',
        'is_sensitive' => 'boolean',
    ];

    /**
     * Get the flow that owns the variable
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /**
     * Get conversation variables that use this custom variable
     */
    public function conversationVariables()
    {
        return $this->hasMany(ConversationVariable::class);
    }
}
