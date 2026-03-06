<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Api extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id', 'name', 'method', 'url', 'content_type',
        'headers', 'request_body', 'form_data', 'url_encoded_fields',
        'body_parameters', 'header_parameters', 'is_active',
    ];

    protected $casts = [
        'headers'            => 'array',
        'form_data'          => 'array',
        'url_encoded_fields' => 'array',
        'body_parameters'    => 'array',
        'header_parameters'  => 'array',
        'is_active'          => 'boolean',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
