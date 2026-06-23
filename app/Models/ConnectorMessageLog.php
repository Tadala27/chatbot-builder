<?php

// app/Models/ConnectorMessageLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectorMessageLog extends Model
{
    public $timestamps = false; // only created_at, set via useCurrent()

    protected $fillable = [
        'whatsapp_account_id',
        'direction',
        'whatsapp_user_phone',
        'whatsapp_message_id',
        'status',
        'error_message',
    ];

    public function whatsappAccount()
    {
        return $this->belongsTo(WhatsappAccount::class);
    }
}
