<?php

// app/Models/WhatsappPhoneIndex.php — LANDLORD-side model.

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappPhoneIndex extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_phone_index';

    protected $fillable = [
        'phone_number_id',
        'tenant_id',
        'verify_token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}