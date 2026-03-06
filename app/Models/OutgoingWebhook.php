<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'flow_id', 'name', 'url',
        'method', 'headers', 'events', 'is_active', 'secret',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'headers'   => 'array',
        'events'    => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Optional: scoped to a specific flow. Null = fires for all flows in the tenant. */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
