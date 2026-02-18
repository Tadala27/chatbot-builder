<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'base_url',
        'auth_type',
        'auth_config',
        'headers',
        'timeout_seconds',
        'retry_attempts',
        'is_active',
    ];

    protected $casts = [
        'auth_config' => 'array',
        'headers' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helper methods
    public function getAuthHeaders(): array
    {
        if ($this->auth_type === 'none') {
            return [];
        }

        $config = $this->auth_config;

        return match ($this->auth_type) {
            'bearer' => ['Authorization' => 'Bearer ' . $config['token']],
            'api_key' => [$config['header_name'] => $config['api_key']],
            'basic' => ['Authorization' => 'Basic ' . base64_encode($config['username'] . ':' . $config['password'])],
            default => [],
        };
    }

    public function buildUrl(string $endpoint): string
    {
        return rtrim($this->base_url, '/') . '/' . ltrim($endpoint, '/');
    }
}