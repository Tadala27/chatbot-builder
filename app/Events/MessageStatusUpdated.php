<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// FIXED: Channel -> PrivateChannel only. Event name/payload unchanged.
class MessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Message $message)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->message->conversation_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.status';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'whatsapp_message_id' => $this->message->whatsapp_message_id,
            'status' => $this->message->status,
            'delivered_at' => $this->message->delivered_at?->toISOString(),
            'read_at' => $this->message->read_at?->toISOString(),
        ];
    }
}