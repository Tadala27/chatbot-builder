<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConditionGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_version_id',
        'logical_operator',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(Condition::class);
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function evaluate(Conversation $conversation): bool
    {
        $results = [];

        foreach ($this->conditions as $condition) {
            $results[] = $condition->evaluate($conversation);
        }

        if (empty($results)) {
            return true; // No conditions = always true
        }

        return $this->logical_operator === 'AND'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);
    }
}


class Condition extends Model
{
    use HasFactory;

    protected $fillable = [
        'condition_group_id',
        'variable_key',
        'operator',
        'value',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function conditionGroup(): BelongsTo
    {
        return $this->belongsTo(ConditionGroup::class);
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function evaluate(Conversation $conversation): bool
    {
        // Get variable value from conversation
        $actualValue = ConversationVariable::where('conversation_id', $conversation->id)
            ->where('key', $this->variable_key)
            ->value('value');

        return match($this->operator) {
            'equals' => $actualValue == $this->value,
            'not_equals' => $actualValue != $this->value,
            'contains' => str_contains((string)$actualValue, (string)$this->value),
            'greater_than' => $actualValue > $this->value,
            'less_than' => $actualValue < $this->value,
            'exists' => !empty($actualValue),
            default => false,
        };
    }
}
