<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BuiltInFunction extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'description',
        'syntax',
        'parameters',
        'return_type',
        'examples',
        'is_active',
    ];

    protected $casts = [
        'parameters' => 'array',
        'examples' => 'array',
        'is_active' => 'boolean',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}