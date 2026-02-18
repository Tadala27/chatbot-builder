<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFunction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'function_type',
        'code',
        'parameters',
        'return_type',
        'is_async',
        'timeout_seconds',
        'is_active',
        'test_cases',
        'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_async' => 'boolean',
        'is_active' => 'boolean',
        'test_cases' => 'array',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helper methods
    public function execute(array $params = [])
    {
        $executor = app(\App\Services\FunctionExecutor::class);
        return $executor->execute($this->id, $params);
    }
}