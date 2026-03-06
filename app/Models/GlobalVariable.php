<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlobalVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'key', 'value', 'data_type', 'is_encrypted', 'description',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
