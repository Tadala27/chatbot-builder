<?php

// app/Models/ConnectorKeyIndex.php — LANDLORD-side model.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorKeyIndex extends Model
{
    protected $table = 'connector_key_index';

    protected $fillable = [
        'connector_api_key',
        'tenant_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
