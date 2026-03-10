<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Single event for all message broadcasts (inbound + outbound, bot + agent).
 * Uses public channels — swap Channel → PrivateChannel once Pusher auth is confirmed.
 *
 * Frontend listens:
 *   pusher.subscribe(`conversation.{id}`).bind('message.received', ...)
 *   pusher.subscribe(`tenant.{id}.inbox`).bind('message.received', ...)
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message      $message,
        public readonly Conversation $conversation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("conversation.{$this->conversation->id}"),
            new Channel("tenant.{$this->conversation->tenant_id}.inbox"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        $m = $this->message;

        $status = 'sent';
        if ($m->read_at)          $status = 'read';
        elseif ($m->delivered_at) $status = 'delivered';

        $unreadCount = $this->conversation->messages()
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->count();

        return [
            'message' => [
                'id'                  => $m->id,
                'conversation_id'     => $m->conversation_id,
                'whatsapp_message_id' => $m->whatsapp_message_id,
                'direction'           => $m->direction,
                'message_type'        => $m->message_type,
                'content'             => $m->content,
                'status'              => $status,
                'sender_type'         => $m->metadata['sender_type'] ?? ($m->direction === 'outbound' ? 'bot' : null),
                'sender_name'         => $m->metadata['sender_name'] ?? null,
                'sent_at'             => $m->sent_at?->toISOString(),
                'delivered_at'        => $m->delivered_at?->toISOString(),
                'read_at'             => $m->read_at?->toISOString(),
                'created_at'          => $m->created_at->toISOString(),
            ],
            'conversation' => [
                'id'                  => $this->conversation->id,
                'whatsapp_user_phone' => $this->conversation->whatsapp_user_phone,
                'whatsapp_user_name'  => $this->conversation->whatsapp_user_name,
                'status'              => $this->conversation->status,
                'last_message_at'     => $this->conversation->last_message_at?->toISOString(),
                'unread_count'        => $unreadCount,
            ],
        ];
    }
}
