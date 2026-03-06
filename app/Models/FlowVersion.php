<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlowVersion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'flow_id', 'version_number', 'status',
        'start_node_id', 'changelog', 'created_by', 'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(Dialog::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function entryDialog(): ?Dialog
    {
        return $this->dialogs()->where('is_entry_point', true)->first();
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
