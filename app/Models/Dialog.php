<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dialog extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'uuid', 'bot_version_id', 'label', 'kind', 'config',
        'position_x', 'position_y', 'is_entry_point', 'is_terminal', 'input_variable',
    ];

    protected $casts = [
        'config' => 'array',
        'position_x' => 'float',
        'position_y' => 'float',
        'is_entry_point' => 'boolean',
        'is_terminal' => 'boolean',
    ];

    public function botVersion()
    {
        return $this->belongsTo(BotVersion::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(DialogOption::class)->orderBy('option_order');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class)->orderBy('action_order');
    }
}