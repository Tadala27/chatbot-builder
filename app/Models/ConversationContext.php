<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ConversationContext extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'variables',
        'last_node_id',
        'expires_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'expires_at' => 'datetime',
    ];

    // Relationships
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    // Helper methods
    public function getVariable(string $key, $default = null)
    {
        return $this->variables[$key] ?? $default;
    }

    public function setVariable(string $key, $value): void
    {
        $variables = $this->variables ?? [];
        $variables[$key] = $value;
        $this->update(['variables' => $variables]);
    }

    public function removeVariable(string $key): void
    {
        $variables = $this->variables ?? [];
        unset($variables[$key]);
        $this->update(['variables' => $variables]);
    }

    public function hasVariable(string $key): bool
    {
        return isset($this->variables[$key]);
    }

    public function clearVariables(): void
    {
        $this->update(['variables' => []]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function extendExpiry(int $hours = 24): void
    {
        $this->update([
            'expires_at' => now()->addHours($hours),
        ]);
    }
}