<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowExecutionLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'event_type', 'success',
        'error_message', 'execution_time_ms',
    ];

    protected $casts = [
        'success'           => 'boolean',
        'execution_time_ms' => 'integer',
        'created_at'        => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
