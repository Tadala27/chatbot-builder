<?php

// app/Models/WhatsappPhoneIndex.php — LANDLORD-side model.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappPhoneIndex extends Model
{
    protected $table = 'whatsapp_phone_index';

    protected $fillable = [
        'phone_number_id',
        'tenant_id',
        'verify_token',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
