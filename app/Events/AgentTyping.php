<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when an agent starts/stops typing in a conversation.
 * Used to show "Agent is typing…" to other agents watching the same conversation.
 * (Does NOT send a typing indicator to the WhatsApp end-user — that is handled
 *  separately via WhatsAppMessageService::sendTypingIndicator.)
 */
class AgentTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $conversationId,
        public readonly int    $agentId,
        public readonly string $agentName,
        public readonly bool   $isTyping,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("conversation.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'agent_id'   => $this->agentId,
            'agent_name' => $this->agentName,
            'is_typing'  => $this->isTyping,
        ];
    }
}
