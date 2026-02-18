<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutgoingWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'flow_id',
        'name',
        'url',
        'method',
        'headers',
        'events',
        'is_active',
        'secret',
    ];

    protected $casts = [
        'headers' => 'array',
        'events' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->whereJsonContains('events', $event);
    }

    // Helper methods
    public function shouldTriggerFor(string $event): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return in_array($event, $this->events ?? []);
    }

    public function generateSignature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->secret);
    }
}