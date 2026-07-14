<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DialogOption extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'dialog_id', 'external_id', 'title', 'description',
        'section_title', 'section_order', 'option_order', 'save_response',
    ];

    protected $casts = [
        'save_response' => 'boolean',
        'option_order'  => 'integer',
        'section_order' => 'integer',
    ];

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    /** Conditions that branch based on this option being selected */
    public function actionConditions(): HasMany
    {
        return $this->hasMany(ActionCondition::class, 'option_id');
    }
}
