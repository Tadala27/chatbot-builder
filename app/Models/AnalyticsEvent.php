<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'chatbot_id',
        'conversation_id',
        'event_type',
        'node_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // CHANGE: Add relationship to FlowNode (not DialogNode anymore)
    public function flowNode()
    {
        return $this->belongsTo(FlowNode::class, 'node_id');
    }

    // CHANGE: Update relationship names
    public function flow()  // instead of chatbot
    {
        return $this->belongsTo(Flow::class);
    }
    
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    // Scopes
    public function scopeForChatbot($query, int $chatbotId)
    {
        return $query->where('chatbot_id', $chatbotId);
    }

    public function scopeForConversation($query, int $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}