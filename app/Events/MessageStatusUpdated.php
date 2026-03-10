<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast message delivery status updates (sent → delivered → read / failed).
 * Frontend listens to 'message.status' on conversation.{id}.
 */
class MessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("conversation.{$this->message->conversation_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.status';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'          => $this->message->id,
            'whatsapp_message_id' => $this->message->whatsapp_message_id,
            'status'              => $this->message->status,
            'delivered_at'        => $this->message->delivered_at?->toISOString(),
            'read_at'             => $this->message->read_at?->toISOString(),
        ];
    }
}
