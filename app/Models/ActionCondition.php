<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionCondition extends Model
{
        use HasFactory, HasUuids;


    protected $fillable = [
        'action_id', 'condition_type', 'condition_operator',
        'variable_key', 'condition_value',
        'option_id',
        'response_field', 'response_path',
        'condition_order',
    ];

    protected $casts = [
        'condition_order' => 'integer',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    /** For saved_response conditions: the option that was tapped */
    public function dialogOption(): BelongsTo
    {
        return $this->belongsTo(DialogOption::class, 'option_id');
    }
}