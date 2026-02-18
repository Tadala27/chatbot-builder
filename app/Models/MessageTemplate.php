<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'language',
        'template_type',
        'content',
        'variables',
        'whatsapp_template_id',
        'status',
    ];

    protected $casts = [
        'content' => 'array',
        'variables' => 'array',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }
}