<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'whatsapp_account_id',
        'name', 'description', 'is_active',
        'fallback_message', 'welcome_message',
        'default_language', 'supported_languages', 'settings',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'supported_languages' => 'array',
        'settings'           => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function customFunctions(): HasMany
    {
        return $this->hasMany(CustomFunction::class);
    }

    public function customVariables(): HasMany
    {
        return $this->hasMany(CustomVariable::class);
    }

    public function apis(): HasMany
    {
        return $this->hasMany(Api::class);
    }

    public function activeFlows(): HasMany
    {
        return $this->hasMany(Flow::class)->where('is_active', true);
    }
}
