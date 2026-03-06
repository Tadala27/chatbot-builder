<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Action extends Model
{
    use HasFactory;

    protected $fillable = [
        'dialog_id', 'action_type', 'action_order', 'config', 'is_active',
    ];

    protected $casts = [
        'config'       => 'array',
        'is_active'    => 'boolean',
        'action_order' => 'integer',
    ];

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ActionCondition::class)->orderBy('condition_order');
    }
}
