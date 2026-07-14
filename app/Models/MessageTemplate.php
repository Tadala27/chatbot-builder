<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'category', 'language', 'template_type',
        'content', 'variables', 'whatsapp_template_id', 'status',
    ];

    protected $casts = [
        'content'   => 'array',
        'variables' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
